<?php

namespace Tests\Feature;

use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeMediaDelivery;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MarkdownDocument;
use App\Creative\Rendering\DeterministicRenderService;
use App\Creative\Rendering\RenderStore;
use App\Jobs\ProcessDeterministicRender;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreativeRenderTest extends TestCase
{
    private string $creativeRoot;

    /** @var array{project: string, run: string, phase: string} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-render-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
            'creative.media.execution' => 'sync',
            'creative.render.execution' => 'sync',
            'creative.media.width' => 180,
            'creative.media.height' => 320,
            'creative.media.frames_per_second' => 8,
        ]);
        $this->target = app(CreativeWorkspaceRepository::class)->createProject(
            'Deterministic final render',
            'Assemble a complete simulated advertisement without a provider.',
            'concepts',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_a_queued_render_uses_exact_selections_and_commits_captions_audio_and_final_manifest(): void
    {
        $this->requireFfmpeg();
        $this->completeManualRun();
        Queue::fake();
        config(['creative.render.execution' => 'queue']);

        $this->post("/projects/{$this->target['project']}/runs/{$this->target['run']}/render")
            ->assertRedirect()
            ->assertSessionHas('success');
        $record = app(RenderStore::class)->renders($this->target['project'], $this->target['run'])[0];
        $renderId = $record['id'];
        $this->assertSame('pending', $record['state']['status']);
        Queue::assertPushedOn('creative-render', ProcessDeterministicRender::class);
        Queue::assertPushed(ProcessDeterministicRender::class, fn (ProcessDeterministicRender $job): bool => $job->renderId === $renderId
            && $job->project === $this->target['project']);

        $state = app(DeterministicRenderService::class)->process(
            $this->target['project'],
            $this->target['run'],
            $renderId,
        );

        $this->assertSame('completed', $state['status']);
        $record = app(RenderStore::class)->renders($this->target['project'], $this->target['run'])[0];
        $manifest = $record['result'];
        $this->assertIsArray($manifest);
        $this->assertSame('completed', $manifest['status']);
        $this->assertSame('timing_reference_tone_not_voice_synthesis', $manifest['audio_semantics']);
        $this->assertSame('video', $manifest['media_probe']['media_type']);
        $this->assertSame('aac', $manifest['media_probe']['audio_codec']);
        $this->assertCount(count($record['request']['shot_plan']['shots']), $manifest['scene_artifact_ids']);
        $expectedDuration = (float) $record['request']['shot_plan']['total_duration_seconds'];
        $timelineTolerance = 1 / (int) $record['request']['settings']['frames_per_second'];
        $this->assertEqualsWithDelta(
            $expectedDuration,
            $manifest['media_probe']['stream_duration_seconds'],
            $timelineTolerance,
        );
        $this->assertEqualsWithDelta(
            $expectedDuration,
            $manifest['timeline']['final_video_duration_seconds'],
            $timelineTolerance,
        );
        $this->assertCount(
            count($record['request']['shot_plan']['shots']),
            $manifest['timeline']['scene_boundaries'],
        );

        $renderRoot = app(RenderStore::class)->path(
            $this->target['project'],
            $this->target['run'],
            $renderId,
        );
        foreach (['final.mp4', 'captions.srt', 'voice-script.md', 'timing-reference.wav', 'manifest.md'] as $file) {
            $this->assertFileExists($renderRoot.'/'.$file);
        }
        $this->assertSame($manifest['final_sha256'], hash_file('sha256', $renderRoot.'/final.mp4'));
        $this->assertStringContainsString('00:00:00,000 -->', File::get($renderRoot.'/captions.srt'));
        $voice = MarkdownDocument::read($renderRoot.'/voice-script.md');
        $this->assertFalse($voice['metadata']['voice_generated']);
        $this->assertSame('simulation_timing_only', $voice['metadata']['status']);
        $this->assertDirectoryDoesNotExist($renderRoot.'/staging');

        $delivery = app(CreativeMediaDelivery::class);
        $finalUrl = $delivery->renderAssetUrl(
            $this->target['project'],
            $this->target['run'],
            $renderId,
            'final',
        );
        $audioUrl = $delivery->renderAssetUrl(
            $this->target['project'],
            $this->target['run'],
            $renderId,
            'audio',
        );
        $this->assertIsString($finalUrl);
        $this->assertIsString($audioUrl);
        $this->get("/projects/{$this->target['project']}/runs/{$this->target['run']}/sequence")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('renders.0.id', $renderId)
                ->where('renders.0.media_url', $finalUrl)
                ->where('renders.0.audio_url', $audioUrl));

        $finalResponse = $this->get($finalUrl);
        $finalResponse->assertOk()
            ->assertHeader('Content-Type', $manifest['media_probe']['mime_type'])
            ->assertHeader('ETag', '"'.$manifest['final_sha256'].'"');
        $this->assertStringStartsWith(
            'inline;',
            (string) $finalResponse->headers->get('Content-Disposition'),
        );

        $audioResponse = $this->get($audioUrl);
        $audioResponse->assertOk()
            ->assertHeader('Content-Type', $manifest['audio_probe']['mime_type'])
            ->assertHeader('ETag', '"'.$manifest['audio_sha256'].'"');
        $this->assertStringStartsWith(
            'inline;',
            (string) $audioResponse->headers->get('Content-Disposition'),
        );

        File::append($renderRoot.'/final.mp4', 'tamper');
        $this->get($finalUrl)->assertNotFound();
        $this->assertNull($delivery->renderAssetUrl(
            $this->target['project'],
            $this->target['run'],
            $renderId,
            'final',
        ));
        $this->get($audioUrl)->assertOk();
    }

    public function test_a_queued_render_fails_if_the_same_artifacts_are_reselected_after_reservation(): void
    {
        $this->requireFfmpeg();
        $this->completeManualRun();
        Queue::fake();
        config(['creative.render.execution' => 'queue']);
        $renderer = app(DeterministicRenderService::class);
        $store = app(RenderStore::class);
        $repository = app(CreativeWorkspaceRepository::class);

        $sequenceSubmission = $renderer->submit($this->target['project'], $this->target['run']);
        $sequenceRequest = $store->request(
            $this->target['project'],
            $this->target['run'],
            $sequenceSubmission['render_id'],
        );
        $sequence = $this->selectedArtifact($repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'sequence',
        )['artifacts'] ?? null);
        $this->assertIsArray($sequence);
        $repository->select(
            $this->target['project'],
            $this->target['run'],
            'sequence',
            $sequence['id'],
        );
        $reselectedSequence = $this->artifactById($repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'sequence',
        )['artifacts'] ?? null, $sequence['id']);
        $this->assertIsArray($reselectedSequence);
        $this->assertNotSame(
            $sequenceRequest['sequence_selection_id'],
            $reselectedSequence['selection']['id'],
        );

        $sequenceState = $renderer->process(
            $this->target['project'],
            $this->target['run'],
            $sequenceSubmission['render_id'],
        );
        $sequenceResult = $store->result(
            $this->target['project'],
            $this->target['run'],
            $sequenceSubmission['render_id'],
        );
        $this->assertSame('failed', $sequenceState['status']);
        $this->assertIsArray($sequenceResult);
        $this->assertStringContainsString('Effective selections or binary identities changed', $sequenceResult['error']);

        $sceneSubmission = $renderer->submit($this->target['project'], $this->target['run']);
        $sceneRequest = $store->request(
            $this->target['project'],
            $this->target['run'],
            $sceneSubmission['render_id'],
        );
        $scene = $this->selectedArtifact($repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'scenes',
        )['artifacts'] ?? null);
        $this->assertIsArray($scene);
        $repository->select(
            $this->target['project'],
            $this->target['run'],
            'scenes',
            $scene['id'],
        );
        $reselectedScene = $this->artifactById($repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'scenes',
        )['artifacts'] ?? null, $scene['id']);
        $this->assertIsArray($reselectedScene);
        $this->assertNotContains(
            $reselectedScene['selection']['id'],
            $sceneRequest['scene_selection_ids'],
        );

        $sceneState = $renderer->process(
            $this->target['project'],
            $this->target['run'],
            $sceneSubmission['render_id'],
        );
        $sceneResult = $store->result(
            $this->target['project'],
            $this->target['run'],
            $sceneSubmission['render_id'],
        );
        $this->assertSame('failed', $sceneState['status']);
        $this->assertIsArray($sceneResult);
        $this->assertStringContainsString('Effective selections or binary identities changed', $sceneResult['error']);
    }

    public function test_a_duplicate_delivery_cannot_claim_or_process_an_already_running_render(): void
    {
        $renderId = 'render-20260728-120000-000000--duplicate';
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
        ]);

        $claim = $store->begin($this->target['project'], $this->target['run'], $renderId);
        $duplicate = app(DeterministicRenderService::class)->process(
            $this->target['project'],
            $this->target['run'],
            $renderId,
        );
        $renderRoot = $store->path($this->target['project'], $this->target['run'], $renderId);

        $this->assertTrue($claim['claimed']);
        $this->assertSame('running', $claim['state']['status']);
        $this->assertSame('running', $duplicate['status']);
        $this->assertNull($store->result($this->target['project'], $this->target['run'], $renderId));
        $this->assertFileDoesNotExist($renderRoot.'/captions.srt');
        $this->assertFileDoesNotExist($renderRoot.'/voice-script.md');
        $this->assertDirectoryDoesNotExist($renderRoot.'/staging');
    }

    public function test_a_scene_with_mismatched_probed_duration_is_ineligible_audited_and_cannot_render(): void
    {
        $this->requireFfmpeg();
        $this->completeManualRun();
        $repository = app(CreativeWorkspaceRepository::class);
        $scene = $this->selectedArtifact($repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'scenes',
        )['artifacts'] ?? null);
        $this->assertIsArray($scene);

        $scenePath = $this->creativeRoot.'/'.$scene['path'];
        $document = MarkdownDocument::read($scenePath);
        $shot = $document['metadata']['shot'];
        $expectedDuration = (float) $shot['end_seconds'] - (float) $shot['start_seconds'];
        $document['metadata']['media_probe']['duration_seconds'] = $expectedDuration + 0.5;
        MarkdownDocument::write($scenePath, $document['metadata'], $document['body']);

        $workspace = $repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'scenes',
        );
        $altered = $this->artifactById($workspace['artifacts'] ?? null, $scene['id']);
        $this->assertIsArray($altered);
        $this->assertFalse($altered['validation']['valid']);
        $this->assertContains(
            'Video media probe duration_seconds must match the shot duration within 0.125 seconds.',
            $altered['validation']['errors'],
        );

        $report = app(CreativeIntegrityAuditor::class)->audit(
            $this->target['project'],
            $this->target['run'],
        );
        $durationIssue = false;
        foreach ($report['issues'] as $issue) {
            if ($issue['code'] === 'invalid_artifact'
                && $issue['entity'] === $scene['id']
                && str_contains($issue['message'], 'duration_seconds must match')) {
                $durationIssue = true;
            }
        }
        $this->assertTrue($durationIssue);
        $this->assertContains('stale_selection', array_column($report['issues'], 'code'));

        $this->expectException(RuntimeException::class);
        app(DeterministicRenderService::class)->submit(
            $this->target['project'],
            $this->target['run'],
        );
    }

    public function test_a_pending_render_can_be_cancelled_without_deleting_its_request(): void
    {
        $renderId = 'render-20260728-120000-000000--cancelled';
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
        ]);

        $first = $store->cancel($this->target['project'], $this->target['run'], $renderId);
        $second = $store->cancel($this->target['project'], $this->target['run'], $renderId);

        $this->assertSame('cancelled', $first['status']);
        $this->assertSame($first, $second);
        $this->assertFileExists($store->path($this->target['project'], $this->target['run'], $renderId).'/request.md');
        $this->assertFileExists($store->path($this->target['project'], $this->target['run'], $renderId).'/manifest.md');
    }

    public function test_a_completed_manifest_recovers_missing_and_stale_state_idempotently(): void
    {
        $this->requireFfmpeg();
        $this->completeManualRun();
        $renderer = app(DeterministicRenderService::class);
        $store = app(RenderStore::class);
        $submission = $renderer->submit($this->target['project'], $this->target['run']);
        $renderId = $submission['render_id'];
        $renderRoot = $store->path($this->target['project'], $this->target['run'], $renderId);
        $manifestHash = hash_file('sha256', $renderRoot.'/manifest.md');
        $finalHash = hash_file('sha256', $renderRoot.'/final.mp4');

        File::delete($renderRoot.'/state.md');
        $recovered = $store->state($this->target['project'], $this->target['run'], $renderId);
        $this->assertSame('completed', $recovered['status']);
        $this->assertTrue($recovered['recovered_from_manifest']);
        $recoveredStateHash = hash_file('sha256', $renderRoot.'/state.md');

        $again = $renderer->process($this->target['project'], $this->target['run'], $renderId);
        $this->assertSame($recovered, $again);
        $this->assertSame($recoveredStateHash, hash_file('sha256', $renderRoot.'/state.md'));

        $stale = array_merge($recovered, [
            'status' => 'running',
            'finished_at' => null,
            'updated_at' => $recovered['started_at'],
        ]);
        unset($stale['recovered_from_manifest'], $stale['recovered_at']);
        MarkdownDocument::write(
            $renderRoot.'/state.md',
            $stale,
            '# Simulated stale render checkpoint',
        );
        $recoveredAgain = $renderer->process($this->target['project'], $this->target['run'], $renderId);

        $this->assertSame('completed', $recoveredAgain['status']);
        $this->assertTrue($recoveredAgain['recovered_from_manifest']);
        $this->assertSame($manifestHash, hash_file('sha256', $renderRoot.'/manifest.md'));
        $this->assertSame($finalHash, hash_file('sha256', $renderRoot.'/final.mp4'));
    }

    public function test_completed_manifest_recovery_fails_closed_on_corrupt_output_or_manifest(): void
    {
        $this->requireFfmpeg();
        $this->completeManualRun();
        $renderer = app(DeterministicRenderService::class);
        $store = app(RenderStore::class);
        $submission = $renderer->submit($this->target['project'], $this->target['run']);
        $renderId = $submission['render_id'];
        $renderRoot = $store->path($this->target['project'], $this->target['run'], $renderId);
        $state = MarkdownDocument::read($renderRoot.'/state.md');
        $stale = array_merge($state['metadata'], [
            'status' => 'running',
            'finished_at' => null,
        ]);
        MarkdownDocument::write($renderRoot.'/state.md', $stale, $state['body']);

        $finalBytes = File::get($renderRoot.'/final.mp4');
        File::append($renderRoot.'/final.mp4', 'corruption');
        try {
            $store->state($this->target['project'], $this->target['run'], $renderId);
            $this->fail('Corrupt final bytes must not recover a completed state.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('output hash is invalid', $exception->getMessage());
        }
        $this->assertSame('running', MarkdownDocument::read($renderRoot.'/state.md')['metadata']['status']);

        File::put($renderRoot.'/final.mp4', $finalBytes);
        File::put($renderRoot.'/manifest.md', "---\nid: [unterminated\n---\n");
        try {
            $store->state($this->target['project'], $this->target['run'], $renderId);
            $this->fail('A malformed immutable manifest must not recover a completed state.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Render result is malformed', $exception->getMessage());
        }
        $this->assertSame('running', MarkdownDocument::read($renderRoot.'/state.md')['metadata']['status']);
    }

    public function test_running_cancellation_wins_the_atomic_render_promotion_commit(): void
    {
        $renderId = 'render-20260728-120000-000000--atomiccancel';
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
        ]);
        $store->begin($this->target['project'], $this->target['run'], $renderId);
        $store->cancel($this->target['project'], $this->target['run'], $renderId);
        $commitRan = false;

        $state = $store->commitCompletion(
            $this->target['project'],
            $this->target['run'],
            $renderId,
            function () use (&$commitRan): array {
                $commitRan = true;

                return ['final_path' => 'renders/never/final.mp4'];
            },
            'This callback must not run after cancellation owns the final lock.',
        );

        $this->assertFalse($commitRan);
        $this->assertSame('cancelled', $state['status']);
        $this->assertSame(
            'cancelled',
            $store->result($this->target['project'], $this->target['run'], $renderId)['status'] ?? null,
        );
    }

    public function test_a_failed_multi_file_promotion_does_not_publish_a_partial_final_delivery(): void
    {
        $this->requireFfmpeg();
        $this->completeManualRun();
        Queue::fake();
        config(['creative.render.execution' => 'queue']);
        $renderer = app(DeterministicRenderService::class);
        $store = app(RenderStore::class);
        $submission = $renderer->submit($this->target['project'], $this->target['run']);
        $renderRoot = $store->path(
            $this->target['project'],
            $this->target['run'],
            $submission['render_id'],
        );
        File::put($renderRoot.'/timing-reference.wav', 'pre-existing collision');

        $state = $renderer->process(
            $this->target['project'],
            $this->target['run'],
            $submission['render_id'],
        );

        $this->assertSame('failed', $state['status']);
        $this->assertFileDoesNotExist($renderRoot.'/final.mp4');
        $this->assertFileDoesNotExist($renderRoot.'/captions.srt');
        $this->assertFileDoesNotExist($renderRoot.'/voice-script.md');
        $this->assertSame('pre-existing collision', File::get($renderRoot.'/timing-reference.wav'));
        $this->assertSame(
            'failed',
            $store->result(
                $this->target['project'],
                $this->target['run'],
                $submission['render_id'],
            )['status'] ?? null,
        );
    }

    public function test_a_hard_worker_timeout_writes_a_terminal_render_manifest(): void
    {
        $renderId = 'render-20260728-120000-000000--workertimeout';
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
        ]);
        $store->begin($this->target['project'], $this->target['run'], $renderId);

        $job = new ProcessDeterministicRender(
            $this->target['project'],
            $this->target['run'],
            $renderId,
        );
        $job->failed(new RuntimeException('Queue worker timeout exceeded.'));

        $record = $store->renders($this->target['project'], $this->target['run'])[0];
        $this->assertSame('timed_out', $record['state']['status']);
        $this->assertSame('timed_out', $record['result']['status']);
        $this->assertTrue($record['result']['worker_failure']);
    }

    private function completeManualRun(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        foreach (['concepts', 'brief-script'] as $phase) {
            $repository->simulate($this->target['project'], $this->target['run'], $phase);
            $artifact = $repository->workspace($this->target['project'], $this->target['run'], $phase)['artifacts'][0];
            $repository->select($this->target['project'], $this->target['run'], $phase, $artifact['id']);
        }
        foreach (['images', 'scenes'] as $phase) {
            $repository->simulate($this->target['project'], $this->target['run'], $phase);
            $artifacts = $repository->workspace($this->target['project'], $this->target['run'], $phase)['artifacts'];
            $selectedSlots = [];
            foreach ($artifacts as $artifact) {
                $slot = is_array($artifact) && is_string($artifact['slot'] ?? null)
                    ? $artifact['slot']
                    : '';
                if ($slot === '' || in_array($slot, $selectedSlots, true)) {
                    continue;
                }

                $repository->select($this->target['project'], $this->target['run'], $phase, $artifact['id']);
                $selectedSlots[] = $slot;
            }
        }
        $repository->simulate($this->target['project'], $this->target['run'], 'sequence');
        $sequence = $repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'sequence',
        )['artifacts'][0];
        $repository->select($this->target['project'], $this->target['run'], 'sequence', $sequence['id']);
    }

    private function requireFfmpeg(): void
    {
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            $process = new Process([(string) config("creative.media.{$binary}_binary", $binary), '-version']);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->markTestSkipped("{$binary} is required for deterministic render verification.");
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function selectedArtifact(mixed $artifacts): ?array
    {
        if (! is_array($artifacts)) {
            return null;
        }

        foreach ($artifacts as $artifact) {
            if (is_array($artifact) && ($artifact['selected'] ?? false) === true) {
                return $artifact;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function artifactById(mixed $artifacts, string $id): ?array
    {
        if (! is_array($artifacts)) {
            return null;
        }

        foreach ($artifacts as $artifact) {
            if (is_array($artifact) && ($artifact['id'] ?? null) === $id) {
                return $artifact;
            }
        }

        return null;
    }
}
