<?php

namespace Tests\Feature;

use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MarkdownDocument;
use App\Creative\Media\MediaJobStore;
use App\Creative\Media\SimulatedMediaJobService;
use App\Creative\Rendering\RenderStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeOperationalIntegrityTest extends TestCase
{
    private string $creativeRoot;

    /** @var array{project: string, run: string, phase: string} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/operational-integrity-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
            'creative.media.execution' => 'sync',
            'creative.render.execution' => 'sync',
        ]);
        $this->target = app(CreativeWorkspaceRepository::class)->createProject(
            'Operational integrity',
            'Audit local jobs and renders without contacting a provider.',
            'concepts',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_completed_media_binary_is_verified_and_mutation_is_reported(): void
    {
        $this->requireGd();
        $submission = app(SimulatedMediaJobService::class)->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'integrity-image--one',
            'image',
        );
        $jobId = $submission['job_id'];
        $job = app(MediaJobStore::class)->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobId,
        );

        $this->assertSame([], $this->entityCodes($this->audit(), $jobId));
        $asset = app(MediaJobStore::class)->assetPath(
            $this->target['project'],
            $this->target['run'],
            'images',
            $job['result']['asset_path'],
        );
        $bytes = File::get($asset);
        File::put($asset, chr(ord($bytes[0]) ^ 0xFF).substr($bytes, 1));

        $this->assertContains('media_job_asset_hash_mismatch', $this->entityCodes($this->audit(), $jobId));

        $this->rewriteMetadata($job['path'].'/result.md', ['asset_path' => '../../outside.png']);
        $this->assertContains('media_job_asset_path_invalid', $this->entityCodes($this->audit(), $jobId));
    }

    public function test_pending_media_job_is_incomplete_but_cancelled_terminal_history_is_valid(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);
        $media = app(SimulatedMediaJobService::class);
        $submission = $media->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'integrity-image--pending',
            'image',
        );
        $jobId = $submission['job_id'];

        $this->assertContains('incomplete_media_job', $this->entityCodes($this->audit(), $jobId));

        $media->cancel($this->target['project'], $this->target['run'], 'images', $jobId);

        $this->assertSame([], $this->entityCodes($this->audit(), $jobId));
    }

    public function test_media_event_and_artifact_association_corruption_are_reported(): void
    {
        $this->requireGd();
        $repository = app(CreativeWorkspaceRepository::class);
        foreach (['concepts', 'brief-script'] as $phase) {
            $repository->simulate($this->target['project'], $this->target['run'], $phase, true);
            $artifact = $repository->workspace(
                $this->target['project'],
                $this->target['run'],
                $phase,
            )['artifacts'][0];
            $repository->select($this->target['project'], $this->target['run'], $phase, $artifact['id']);
        }
        $repository->simulate($this->target['project'], $this->target['run'], 'images', true);
        $image = $repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'images',
        )['artifacts'][0];
        $jobId = $image['metadata']['media_job_id'];
        $imagePath = $this->creativeRoot.'/'.$image['path'];
        $document = MarkdownDocument::read($imagePath);
        $document['metadata']['media_job_id'] = 'media-job-wrong--identity';
        MarkdownDocument::write($imagePath, $document['metadata'], $document['body']);

        $job = app(MediaJobStore::class)->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobId,
        );
        $event = collect(File::files($job['path'].'/events'))->first();
        $this->assertNotNull($event);
        File::put($event->getPathname(), "---\nevent: [broken\n---\n");

        $codes = $this->entityCodes($this->audit(), $jobId);
        $this->assertContains('media_job_artifact_mismatch', $codes);
        $this->assertContains('malformed_media_job_event', $codes);
    }

    public function test_media_sidecar_with_deleted_job_records_is_reported(): void
    {
        $this->requireGd();
        $repository = app(CreativeWorkspaceRepository::class);
        foreach (['concepts', 'brief-script'] as $phase) {
            $repository->simulate($this->target['project'], $this->target['run'], $phase, true);
            $artifact = $repository->workspace(
                $this->target['project'],
                $this->target['run'],
                $phase,
            )['artifacts'][0];
            $repository->select($this->target['project'], $this->target['run'], $phase, $artifact['id']);
        }

        $repository->simulate($this->target['project'], $this->target['run'], 'images', true);
        $image = $repository->workspace(
            $this->target['project'],
            $this->target['run'],
            'images',
        )['artifacts'][0];
        $jobId = $image['metadata']['media_job_id'];
        $job = app(MediaJobStore::class)->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobId,
        );
        File::deleteDirectory($job['path']);

        $this->assertContains('media_sidecar_job_missing', $this->entityCodes($this->audit(), $jobId));
    }

    public function test_completed_render_outputs_are_verified_and_mutation_is_reported(): void
    {
        $renderId = 'render-20260728-120000-000000--integrity';
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
            'scene_artifact_ids' => ['scene-one'],
            'scene_selection_ids' => ['scene-selection-one'],
            'scene_asset_sha256' => ['scene-one' => str_repeat('a', 64)],
        ]);
        $store->begin($this->target['project'], $this->target['run'], $renderId);
        $root = $store->path($this->target['project'], $this->target['run'], $renderId);
        $outputs = [
            'final.mp4' => 'deterministic final bytes',
            'captions.srt' => "1\n00:00:00,000 --> 00:00:01,000\nCaption\n",
            'voice-script.md' => '# Voice timing only',
            'timing-reference.wav' => 'deterministic audio bytes',
        ];
        foreach ($outputs as $filename => $bytes) {
            File::put($root.'/'.$filename, $bytes);
        }
        $store->finish($this->target['project'], $this->target['run'], $renderId, 'completed', [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
            'scene_artifact_ids' => ['scene-one'],
            'scene_selection_ids' => ['scene-selection-one'],
            'scene_asset_sha256' => ['scene-one' => str_repeat('a', 64)],
            'final_path' => "renders/{$renderId}/final.mp4",
            'final_sha256' => hash('sha256', $outputs['final.mp4']),
            'final_bytes' => strlen($outputs['final.mp4']),
            'captions_path' => "renders/{$renderId}/captions.srt",
            'captions_sha256' => hash('sha256', $outputs['captions.srt']),
            'voice_script_path' => "renders/{$renderId}/voice-script.md",
            'voice_script_sha256' => hash('sha256', $outputs['voice-script.md']),
            'audio_path' => "renders/{$renderId}/timing-reference.wav",
            'audio_sha256' => hash('sha256', $outputs['timing-reference.wav']),
            'audio_bytes' => strlen($outputs['timing-reference.wav']),
        ], 'Completed local integrity fixture.');

        $this->assertSame([], $this->entityCodes($this->audit(), $renderId));

        $bytes = File::get($root.'/final.mp4');
        File::put($root.'/final.mp4', chr(ord($bytes[0]) ^ 0xFF).substr($bytes, 1));

        $this->assertContains('render_output_hash_mismatch', $this->entityCodes($this->audit(), $renderId));

        $this->rewriteMetadata($root.'/manifest.md', ['final_path' => '../../outside.mp4']);
        $this->assertContains('render_output_path_invalid', $this->entityCodes($this->audit(), $renderId));
    }

    public function test_pending_render_is_incomplete_but_cancelled_terminal_manifest_is_valid(): void
    {
        $renderId = 'render-20260728-120000-000000--cancelled';
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
        ]);

        $this->assertContains('incomplete_render', $this->entityCodes($this->audit(), $renderId));

        $store->cancel($this->target['project'], $this->target['run'], $renderId);
        $this->assertSame([], $this->entityCodes($this->audit(), $renderId));

        File::put(
            $store->path($this->target['project'], $this->target['run'], $renderId).'/manifest.md',
            "---\nstatus: [broken\n---\n",
        );
        $this->assertContains('malformed_render_manifest', $this->entityCodes($this->audit(), $renderId));
    }

    public function test_failed_and_timed_out_operations_are_truthful_terminal_history(): void
    {
        $media = app(SimulatedMediaJobService::class);
        $mediaJobs = [
            ['images', $media->submit(
                $this->target['project'],
                $this->target['run'],
                'images',
                'integrity-image--failed',
                'image',
                ['fixture_outcome' => 'failed'],
            )],
            ['scenes', $media->submit(
                $this->target['project'],
                $this->target['run'],
                'scenes',
                'integrity-scene--timed-out',
                'video',
                ['fixture_outcome' => 'timed_out'],
            )],
        ];
        foreach ($mediaJobs as [, $submission]) {
            $this->assertSame([], $this->entityCodes($this->audit(), $submission['job_id']));
        }

        $renders = app(RenderStore::class);
        foreach (['failed' => 'failed', 'timedout' => 'timed_out'] as $suffix => $status) {
            $renderId = "render-20260728-120000-000000--{$suffix}";
            $renders->create($this->target['project'], $this->target['run'], $renderId, [
                'renderer' => 'deterministic-ffmpeg-v1',
                'sequence_artifact_id' => 'sequence-one',
            ]);
            $renders->begin($this->target['project'], $this->target['run'], $renderId);
            $renders->finish(
                $this->target['project'],
                $this->target['run'],
                $renderId,
                $status,
                ['error' => "Truthful {$status} fixture."],
                "Render ended {$status}.",
            );
            $this->assertSame([], $this->entityCodes($this->audit(), $renderId));
        }
    }

    public function test_contradictory_operational_identities_and_statuses_are_reported(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);
        $media = app(SimulatedMediaJobService::class);
        $submission = $media->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'integrity-image--contradiction',
            'image',
        );
        $jobId = $submission['job_id'];
        $media->cancel($this->target['project'], $this->target['run'], 'images', $jobId);
        $job = app(MediaJobStore::class)->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobId,
        );
        $this->rewriteMetadata($job['path'].'/request.md', ['job_id' => 'media-job-wrong--request']);
        $this->rewriteMetadata($job['path'].'/state.md', ['status' => 'mystery']);
        $this->rewriteMetadata($job['path'].'/result.md', [
            'job_id' => 'media-job-wrong--result',
            'status' => 'failed',
        ]);

        $mediaCodes = $this->entityCodes($this->audit(), $jobId);
        $this->assertContains('invalid_media_job_request', $mediaCodes);
        $this->assertContains('invalid_media_job_state', $mediaCodes);
        $this->assertContains('invalid_media_job_result', $mediaCodes);
        $this->assertContains('media_job_status_mismatch', $mediaCodes);

        $renderId = 'render-20260728-120000-000000--contradiction';
        $renders = app(RenderStore::class);
        $renders->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => 'sequence-one',
        ]);
        $renders->cancel($this->target['project'], $this->target['run'], $renderId);
        $renderPath = $renders->path($this->target['project'], $this->target['run'], $renderId);
        $this->rewriteMetadata($renderPath.'/request.md', ['render_id' => 'render-wrong--request']);
        $this->rewriteMetadata($renderPath.'/state.md', ['status' => 'mystery']);
        $this->rewriteMetadata($renderPath.'/manifest.md', [
            'render_id' => 'render-wrong--manifest',
            'status' => 'failed',
        ]);

        $renderCodes = $this->entityCodes($this->audit(), $renderId);
        $this->assertContains('invalid_render_request', $renderCodes);
        $this->assertContains('invalid_render_state', $renderCodes);
        $this->assertContains('invalid_render_manifest', $renderCodes);
        $this->assertContains('render_status_mismatch', $renderCodes);
    }

    /** @return array<string, mixed> */
    private function audit(): array
    {
        return app(CreativeIntegrityAuditor::class)->audit($this->target['project'], $this->target['run']);
    }

    /** @param array<string, mixed> $report @return list<string> */
    private function entityCodes(array $report, string $entity): array
    {
        return array_values(collect($report['issues'])
            ->where('entity', $entity)
            ->pluck('code')
            ->all());
    }

    private function requireGd(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is required for deterministic image fixtures.');
        }
    }

    /** @param array<string, mixed> $changes */
    private function rewriteMetadata(string $path, array $changes): void
    {
        $document = MarkdownDocument::read($path);
        MarkdownDocument::write($path, array_merge($document['metadata'], $changes), $document['body']);
    }
}
