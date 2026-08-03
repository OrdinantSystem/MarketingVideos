<?php

namespace Tests\Feature;

use App\Creative\CreativeOperationalIntegrityAuditor;
use App\Creative\CreativePathResolver;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MarkdownDocument;
use App\Creative\Media\MediaBatchStore;
use App\Creative\Media\MediaJobStore;
use App\Creative\Media\QueuedMediaBatchCoordinator;
use App\Creative\Media\SimulatedMediaJobService;
use App\Jobs\ProcessQueuedMediaBatchJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreativeQueuedMediaBatchTest extends TestCase
{
    private string $creativeRoot;

    /** @var array{project: string, run: string, phase: string} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/queued-media-batch-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
            'creative.media.execution' => 'queue',
            'creative.media.timeout_seconds' => 2,
        ]);
        Queue::fake();
        $this->target = app(CreativeWorkspaceRepository::class)->createProject(
            'Queued batch foundation',
            'Prove all-or-nothing simulated media coordination.',
            'concepts',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_every_job_is_reserved_before_dispatch_and_sidecars_publish_only_after_all_complete(): void
    {
        $this->requireGd();
        $attemptId = '20260728-120000--generation--queued--images';
        $documents = $this->documents($attemptId);
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments($attemptId, $documents));

        $this->assertSame('pending', $submission['state']['status']);
        $request = $submission['request'];
        $jobIds = array_values($request['media_job_ids']);
        $this->assertCount(2, $jobIds);
        foreach ($jobIds as $jobId) {
            $this->assertTrue($this->jobs()->exists(
                $this->target['project'],
                $this->target['run'],
                'images',
                $jobId,
            ));
        }
        Queue::assertPushed(ProcessQueuedMediaBatchJob::class, 2);
        $this->assertSame([], $this->sidecars());

        $this->queuedJob($attemptId, $jobIds[0])->handle($this->media(), $this->coordinator());
        $waiting = $this->batches()->state(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        );
        $this->assertSame('pending', $waiting['status']);
        $this->assertSame([], $this->sidecars());

        $this->queuedJob($attemptId, $jobIds[1])->handle($this->media(), $this->coordinator());
        $completed = $this->batches()->state(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        );
        $this->assertSame('completed', $completed['status']);
        $this->assertCount(2, $this->sidecars());
        $result = $this->batches()->result($this->target['project'], $this->target['run'], 'images', $attemptId);
        $this->assertIsArray($result);
        $this->assertSame(array_keys($request['media_job_ids']), $result['artifact_ids']);
        $this->assertSame(['pending', 'aggregate_updated', 'completed'], array_column(
            $this->batches()->events($this->target['project'], $this->target['run'], 'images', $attemptId),
            'event',
        ));
        $this->assertFalse(collect(app(CreativeOperationalIntegrityAuditor::class)->audit(
            $this->target['project'],
            $this->target['run'],
        ))->contains(fn (array $issue): bool => str_starts_with((string) ($issue['code'] ?? ''), 'media_batch_')));

        $missingId = $result['artifact_ids'][0];
        $missingPath = app(CreativePathResolver::class)->outputsPath(
            $this->target['project'],
            $this->target['run'],
            'images',
        ).'/'.$missingId.'.md';
        File::delete($missingPath);
        $eventFiles = File::files($this->batches()->batch(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        )['path'].'/events');
        File::delete($eventFiles[array_key_last($eventFiles)]->getPathname());
        $recovered = $this->coordinator()->reconcile(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        );
        $this->assertSame('completed', $recovered['status']);
        $this->assertFileExists($missingPath);
        $this->assertSame($result['artifact_sha256'][$missingId], hash_file('sha256', $missingPath));
        $this->assertContains('outputs_recovered', array_column(
            $this->batches()->events($this->target['project'], $this->target['run'], 'images', $attemptId),
            'event',
        ));
        $this->assertContains('terminal_recovered', array_column(
            $this->batches()->events($this->target['project'], $this->target['run'], 'images', $attemptId),
            'event',
        ));

        $this->coordinator()->enqueue(...$this->enqueueArguments($attemptId, $documents));
        Queue::assertPushed(ProcessQueuedMediaBatchJob::class, 2);
        $this->assertCount(2, $this->sidecars());

        File::append($missingPath, "\ncorrupt");
        $this->assertContains('media_batch_sidecar_hash_mismatch', array_column(
            app(CreativeOperationalIntegrityAuditor::class)->audit(
                $this->target['project'],
                $this->target['run'],
            ),
            'code',
        ));
    }

    public function test_one_failed_child_terminalizes_the_batch_without_any_sidecar_commit(): void
    {
        $this->requireGd();
        $attemptId = '20260728-120001--generation--failed--images';
        $documents = $this->documents($attemptId);
        $documents[1] = array_merge($documents[1], [
            'metadata' => array_merge($documents[1]['metadata'], ['fixture_outcome' => 'failed']),
        ]);
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments($attemptId, $documents));
        $jobIds = array_values($submission['request']['media_job_ids']);

        foreach ($jobIds as $jobId) {
            $this->queuedJob($attemptId, $jobId)->handle($this->media(), $this->coordinator());
        }
        $failed = $this->coordinator()->reconcile(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        );

        $this->assertSame('failed', $failed['status']);
        $this->assertSame([], $this->sidecars());
        $result = $this->batches()->result($this->target['project'], $this->target['run'], 'images', $attemptId);
        $this->assertIsArray($result);
        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['artifact_ids']);
        $this->assertSame(0, $result['output_count']);
        $this->assertCount(1, $result['retained_failed_attempt_assets']);
        $this->assertStringStartsWith(
            'media-batches/'.$attemptId.'/assets/',
            $result['retained_failed_attempt_assets'][0]['asset_path'],
        );
        $this->assertFalse(collect(app(CreativeOperationalIntegrityAuditor::class)->audit(
            $this->target['project'],
            $this->target['run'],
        ))->contains(fn (array $issue): bool => str_starts_with((string) ($issue['code'] ?? ''), 'media_batch_')));
        $this->assertSame($failed, $this->coordinator()->reconcile(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        ));
    }

    public function test_aggregate_records_require_the_exact_immutable_child_membership(): void
    {
        $attemptId = '20260728-120002--generation--membership--images';
        $documents = $this->documents($attemptId);
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments($attemptId, $documents));
        $jobIds = array_values($submission['request']['media_job_ids']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact immutable child-job set');
        $this->batches()->recordAggregate(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
            'pending',
            [$jobIds[0] => 'pending'],
        );
    }

    public function test_worker_failure_callback_terminalizes_and_reconciles_its_parent_batch(): void
    {
        $attemptId = '20260728-120003--generation--worker-timeout--images';
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments(
            $attemptId,
            $this->documents($attemptId),
        ));
        $jobIds = array_values($submission['request']['media_job_ids']);

        $this->queuedJob($attemptId, $jobIds[0])->failed(new RuntimeException('Queue worker timeout exceeded.'));

        $state = $this->batches()->state($this->target['project'], $this->target['run'], 'images', $attemptId);
        $this->assertSame('timed_out', $state['status']);
        $this->assertSame('timed_out', $state['media_job_statuses'][$jobIds[0]]);
        $this->assertSame('cancelled', $state['media_job_statuses'][$jobIds[1]]);
        $this->assertSame([], $this->sidecars());
    }

    public function test_failed_child_terminalizes_a_running_sibling_without_waiting_for_its_worker(): void
    {
        $attemptId = '20260728-120003b--generation--running-sibling--images';
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments(
            $attemptId,
            $this->documents($attemptId),
        ));
        $jobIds = array_values($submission['request']['media_job_ids']);
        $claim = $this->jobs()->begin($this->target['project'], $this->target['run'], 'images', $jobIds[1]);
        $this->assertTrue($claim['claimed']);

        $this->queuedJob($attemptId, $jobIds[0])->failed(new RuntimeException('Worker stopped unexpectedly.'));

        $runningSibling = $this->jobs()->state(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobIds[1],
        );
        $batch = $this->batches()->state($this->target['project'], $this->target['run'], 'images', $attemptId);
        $this->assertSame('cancelled', $runningSibling['status']);
        $this->assertSame('failed', $batch['status']);
        $this->assertSame('cancelled', $batch['media_job_statuses'][$jobIds[1]]);
        $this->assertSame([], $this->sidecars());
    }

    public function test_tampered_staged_draft_is_audited_and_terminalizes_without_publishing_sidecars(): void
    {
        $this->requireGd();
        $attemptId = '20260728-120004--generation--draft-fence--images';
        $documents = $this->documents($attemptId);
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments($attemptId, $documents));
        $jobIds = array_values($submission['request']['media_job_ids']);
        $batch = $this->batches()->batch($this->target['project'], $this->target['run'], 'images', $attemptId);
        $draft = $batch['path'].'/drafts/'.$documents[0]['id'].'.md';
        File::append($draft, "\n<!-- changed after immutable reservation -->\n");

        $this->assertContains('media_batch_draft_hash_mismatch', array_column(
            app(CreativeOperationalIntegrityAuditor::class)->audit(
                $this->target['project'],
                $this->target['run'],
            ),
            'code',
        ));

        foreach ($jobIds as $jobId) {
            $this->queuedJob($attemptId, $jobId)->handle($this->media(), $this->coordinator());
        }

        $state = $this->batches()->state($this->target['project'], $this->target['run'], 'images', $attemptId);
        $result = $this->batches()->result($this->target['project'], $this->target['run'], 'images', $attemptId);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('immutable SHA-256', $result['error']);
        $this->assertCount(2, $result['retained_failed_attempt_assets']);
        $this->assertSame([], $this->sidecars());

        $resultPath = $batch['path'].'/result.md';
        $resultHash = hash_file('sha256', $resultPath);
        $eventCount = count($this->batches()->events(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        ));
        $this->assertSame($state, $this->coordinator()->reconcile(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        ));
        $this->assertSame($resultHash, hash_file('sha256', $resultPath));
        $this->assertCount($eventCount, $this->batches()->events(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        ));
    }

    public function test_corrupt_completed_child_binary_terminalizes_post_render_failure_with_evidence(): void
    {
        $this->requireGd();
        $attemptId = '20260728-120005--generation--corrupt-binary--images';
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments(
            $attemptId,
            $this->documents($attemptId),
        ));
        $jobIds = array_values($submission['request']['media_job_ids']);

        $this->queuedJob($attemptId, $jobIds[0])->handle($this->media(), $this->coordinator());
        $firstResult = $this->jobs()->result(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobIds[0],
        );
        $this->assertIsArray($firstResult);
        $binary = $this->jobs()->assetPath(
            $this->target['project'],
            $this->target['run'],
            'images',
            $firstResult['asset_path'],
        );
        File::append($binary, 'corrupt');

        $this->queuedJob($attemptId, $jobIds[1])->handle($this->media(), $this->coordinator());

        $state = $this->batches()->state($this->target['project'], $this->target['run'], 'images', $attemptId);
        $result = $this->batches()->result($this->target['project'], $this->target['run'], 'images', $attemptId);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('failed', $result['status']);
        $this->assertCount(2, $result['retained_failed_attempt_assets']);
        $this->assertSame([], $this->sidecars());
        $codes = array_column(app(CreativeOperationalIntegrityAuditor::class)->audit(
            $this->target['project'],
            $this->target['run'],
        ), 'code');
        $this->assertContains('media_job_asset_hash_mismatch', $codes);
        $this->assertContains('media_batch_retained_asset_mismatch', $codes);
    }

    public function test_immutable_child_result_recovers_stale_state_and_event_before_worker_failure(): void
    {
        $this->requireGd();
        $attemptId = '20260728-120006--generation--child-fence--images';
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments(
            $attemptId,
            $this->documents($attemptId),
        ));
        $jobIds = array_values($submission['request']['media_job_ids']);
        $jobId = $jobIds[0];

        $this->media()->process($this->target['project'], $this->target['run'], 'images', $jobId);
        $job = $this->jobs()->job($this->target['project'], $this->target['run'], 'images', $jobId);
        $resultPath = $job['path'].'/result.md';
        $resultHash = hash_file('sha256', $resultPath);
        foreach ($job['events'] as $event) {
            if (($event['event'] ?? null) === 'completed' && is_string($event['_path'] ?? null)) {
                File::delete($event['_path']);
            }
        }
        $statePath = $job['path'].'/state.md';
        $stateDocument = MarkdownDocument::read($statePath);
        $staleState = array_merge($stateDocument['metadata'], [
            'status' => 'running',
            'finished_at' => null,
            'last_error' => null,
        ]);
        MarkdownDocument::write($statePath, $staleState, $stateDocument['body']);

        $this->queuedJob($attemptId, $jobId)->failed(new RuntimeException('Worker died after committing result.md.'));

        $recovered = $this->jobs()->job($this->target['project'], $this->target['run'], 'images', $jobId);
        $this->assertSame('completed', $recovered['state']['status']);
        $this->assertSame($resultHash, hash_file('sha256', $resultPath));
        $this->assertCount(1, array_filter(
            $recovered['events'],
            fn (array $event): bool => ($event['event'] ?? null) === 'terminal_recovered',
        ));
        MarkdownDocument::write($statePath, $staleState, $stateDocument['body']);
        $this->assertSame('completed', $this->media()->cancel(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobId,
        )['status']);
        MarkdownDocument::write($statePath, $staleState, $stateDocument['body']);
        $this->assertSame('completed', $this->media()->process(
            $this->target['project'],
            $this->target['run'],
            'images',
            $jobId,
        )['status']);
        $this->assertCount(1, array_filter(
            $this->jobs()->events($this->target['project'], $this->target['run'], 'images', $jobId),
            fn (array $event): bool => ($event['event'] ?? null) === 'terminal_recovered',
        ));

        $this->queuedJob($attemptId, $jobIds[1])->handle($this->media(), $this->coordinator());
        $this->assertSame('completed', $this->batches()->state(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        )['status']);
        $this->assertCount(2, $this->sidecars());
    }

    public function test_integrity_rejects_retained_evidence_with_the_wrong_child_identity(): void
    {
        $this->requireGd();
        $attemptId = '20260728-120007--generation--retained-identity--images';
        $documents = $this->documents($attemptId);
        $documents[1] = array_merge($documents[1], [
            'metadata' => array_merge($documents[1]['metadata'], ['fixture_outcome' => 'failed']),
        ]);
        $submission = $this->coordinator()->enqueue(...$this->enqueueArguments($attemptId, $documents));
        $jobIds = array_values($submission['request']['media_job_ids']);
        foreach ($jobIds as $jobId) {
            $this->queuedJob($attemptId, $jobId)->handle($this->media(), $this->coordinator());
        }

        $batch = $this->batches()->batch($this->target['project'], $this->target['run'], 'images', $attemptId);
        $resultPath = $batch['path'].'/result.md';
        $resultDocument = MarkdownDocument::read($resultPath);
        $resultDocument['metadata']['retained_failed_attempt_assets'][0]['media_job_id'] = $jobIds[1];
        MarkdownDocument::write($resultPath, $resultDocument['metadata'], $resultDocument['body']);

        $this->assertContains('media_batch_retained_assets_invalid', array_column(
            app(CreativeOperationalIntegrityAuditor::class)->audit(
                $this->target['project'],
                $this->target['run'],
            ),
            'code',
        ));
    }

    /**
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @return array<int, mixed>
     */
    private function enqueueArguments(string $attemptId, array $documents): array
    {
        $shot = $this->shot();

        return [
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
            $documents,
            ['shot-01'],
            ['shot-01' => ['source-script-1']],
            ['shot-01' => $shot],
        ];
    }

    /** @return list<array{id: string, metadata: array<string, mixed>, body: string}> */
    private function documents(string $attemptId): array
    {
        $documents = [];
        foreach (['a', 'b'] as $index => $variant) {
            $id = "queued-image--{$variant}";
            $documents[] = [
                'id' => $id,
                'metadata' => [
                    'schema_version' => 1,
                    'id' => $id,
                    'artifact_type' => 'image_sidecar',
                    'phase' => 'images',
                    'slot' => 'shot-01',
                    'title' => 'Queued image '.strtoupper($variant),
                    'summary' => 'A deterministic queued image fixture.',
                    'candidate_order' => $index + 1,
                    'created_at' => '2026-07-28T12:00:00.000000+00:00',
                    'version' => 1,
                    'generation_attempt_id' => $attemptId,
                    'generator' => 'simulation',
                    'prompt_version' => 'images-simulation-v1',
                    'source_artifact_ids' => ['source-script-1'],
                    'settings' => ['driver' => 'simulation', 'lightweight' => true],
                    'usage' => [],
                    'cost_usd' => 0.0,
                    'status' => 'simulated',
                    'simulation' => true,
                    'asset_status' => 'absent_simulation',
                    'asset_path' => "assets/{$id}.png",
                    'variant' => strtoupper($variant),
                    'preview_color' => $index === 0 ? '#123456' : '#654321',
                    'shot' => $this->shot(),
                ],
                'body' => "# Queued image\n\n## Visual\n\nA clear product frame.\n\n## Direction\n\nVertical composition.\n\n## Generation prompt\n\nRender a deterministic local fixture.\n\n## Asset status\n\nSimulation sidecar only. No PNG was created.",
            ];
        }

        return $documents;
    }

    /** @return array{id: string, order: int, start_seconds: float, end_seconds: float, purpose: string, visual: string} */
    private function shot(): array
    {
        return [
            'id' => 'shot-01',
            'order' => 1,
            'start_seconds' => 0.0,
            'end_seconds' => 1.0,
            'purpose' => 'Introduce the product',
            'visual' => 'Product enters frame',
        ];
    }

    /** @return list<string> */
    private function sidecars(): array
    {
        $outputs = app(CreativePathResolver::class)->outputsPath(
            $this->target['project'],
            $this->target['run'],
            'images',
        );

        return array_values(collect(File::files($outputs))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->all());
    }

    private function requireGd(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is required for deterministic image fixtures.');
        }
    }

    private function coordinator(): QueuedMediaBatchCoordinator
    {
        return app(QueuedMediaBatchCoordinator::class);
    }

    private function batches(): MediaBatchStore
    {
        return app(MediaBatchStore::class);
    }

    private function jobs(): MediaJobStore
    {
        return app(MediaJobStore::class);
    }

    private function media(): SimulatedMediaJobService
    {
        return app(SimulatedMediaJobService::class);
    }

    private function queuedJob(string $batchId, string $jobId): ProcessQueuedMediaBatchJob
    {
        return new ProcessQueuedMediaBatchJob(
            $this->target['project'],
            $this->target['run'],
            'images',
            $batchId,
            $jobId,
        );
    }
}
