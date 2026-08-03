<?php

namespace Tests\Feature;

use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Media\MediaJobStatus;
use App\Creative\Media\MediaJobStore;
use App\Creative\Media\SimulatedMediaBatchMaterializer;
use App\Creative\Media\SimulatedMediaJobService;
use App\Jobs\ProcessSimulatedMediaJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreativeMediaJobTest extends TestCase
{
    private string $creativeRoot;

    /** @var array{project: string, run: string, phase: string} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-media-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
            'creative.media.execution' => 'sync',
            'creative.media.timeout_seconds' => 2,
        ]);
        $this->target = app(CreativeWorkspaceRepository::class)->createProject(
            'Deterministic media jobs',
            'Exercise media lifecycle behavior without contacting a provider.',
            'concepts',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_a_local_image_job_stages_probes_hashes_and_promotes_an_immutable_binary(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is required for deterministic image fixtures.');
        }

        $submission = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--one',
            'image',
            ['label' => 'Shot one', 'color' => '#345678'],
        );

        $this->assertSame(MediaJobStatus::Completed->value, $submission['state']['status']);
        $job = $this->store()->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $result = $job['result'];
        $this->assertIsArray($result);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('image', $result['media_probe']['media_type']);
        $this->assertSame('image/png', $result['media_probe']['mime_type']);
        $this->assertGreaterThan(0, $result['asset_bytes']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['asset_sha256']);
        $this->assertSame(['pending', 'running', 'completed'], array_column($job['events'], 'event'));

        $asset = $this->store()->assetPath(
            $this->target['project'],
            $this->target['run'],
            'images',
            $result['asset_path'],
        );
        $this->assertFileExists($asset);
        $this->assertSame($result['asset_sha256'], hash_file('sha256', $asset));
        $this->assertCount(0, File::allFiles($job['path'].'/staging'));
    }

    public function test_queue_mode_leaves_a_traceable_pending_job_and_dispatches_the_local_worker(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);

        $submission = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'scenes',
            'fixture-scene--queued',
            'video',
        );

        $this->assertSame(MediaJobStatus::Pending->value, $submission['state']['status']);
        Queue::assertPushedOn('creative-media', ProcessSimulatedMediaJob::class);
        Queue::assertPushed(ProcessSimulatedMediaJob::class, fn (ProcessSimulatedMediaJob $job): bool => $job->mediaJobId === $submission['job_id']
            && $job->project === $this->target['project']
            && $job->phase === 'scenes');
        $this->assertSame(
            ['pending'],
            array_column($this->store()->job(
                $this->target['project'],
                $this->target['run'],
                'scenes',
                $submission['job_id'],
            )['events'], 'event'),
        );
    }

    public function test_a_duplicate_delivery_cannot_claim_or_process_an_already_running_media_job(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);
        $submission = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--duplicate-delivery',
            'image',
        );

        $claim = $this->store()->begin(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $duplicate = $this->media()->process(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $job = $this->store()->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );

        $this->assertTrue($claim['claimed']);
        $this->assertSame(MediaJobStatus::Running->value, $claim['state']['status']);
        $this->assertSame(MediaJobStatus::Running->value, $duplicate['status']);
        $this->assertNull($job['result']);
        $this->assertSame(['pending', 'running'], array_column($job['events'], 'event'));
        $this->assertDirectoryDoesNotExist($job['path'].'/staging');
    }

    public function test_a_local_audio_job_creates_a_probed_timing_fixture(): void
    {
        $this->requireFfmpeg();

        $submission = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'sequence',
            'fixture-audio--timing',
            'audio',
            ['duration_seconds' => 0.5, 'frequency' => 220],
        );
        $result = $this->store()->result(
            $this->target['project'],
            $this->target['run'],
            'sequence',
            $submission['job_id'],
        );

        $this->assertSame('completed', $submission['state']['status']);
        $this->assertIsArray($result);
        $this->assertSame('audio', $result['media_probe']['media_type']);
        $this->assertGreaterThan(0, $result['media_probe']['duration_seconds']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['asset_sha256']);
    }

    public function test_a_generation_batch_reserves_and_materializes_every_binary_with_shared_lineage(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is required for deterministic image fixtures.');
        }

        $attemptId = 'generation-batch--one';
        $documents = [];
        foreach (['a', 'b'] as $index => $variant) {
            $id = "fixture-image--{$variant}";
            $documents[] = [
                'id' => $id,
                'metadata' => [
                    'id' => $id,
                    'artifact_type' => 'image_sidecar',
                    'title' => 'Fixture '.strtoupper($variant),
                    'preview_color' => $index === 0 ? '#123456' : '#654321',
                    'status' => 'simulated',
                    'asset_status' => 'absent_simulation',
                    'shot' => [
                        'start_seconds' => 0.0,
                        'end_seconds' => 1.0,
                    ],
                ],
                'body' => 'Simulation sidecar only. No PNG was created.',
            ];
        }

        $materialized = app(SimulatedMediaBatchMaterializer::class)->materialize(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
            $documents,
        );

        $this->assertCount(2, $materialized);
        $this->assertTrue(collect($materialized)->every(
            fn (array $document): bool => ($document['metadata']['status'] ?? null) === 'ready'
                && ($document['metadata']['asset_status'] ?? null) === 'ready'
                && is_string($document['metadata']['media_job_id'] ?? null),
        ));
        $jobs = $this->store()->jobs($this->target['project'], $this->target['run'], 'images');
        $this->assertCount(2, $jobs);
        $this->assertTrue(collect($jobs)->every(
            fn (array $job): bool => ($job['request']['batch_id'] ?? null) === $attemptId
                && ($job['state']['status'] ?? null) === 'completed',
        ));
    }

    public function test_failed_and_timed_out_fixtures_write_explicit_terminal_results(): void
    {
        $failed = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--failed',
            'image',
            ['fixture_outcome' => 'failed'],
        );
        $timedOut = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'scenes',
            'fixture-scene--timed-out',
            'video',
            ['fixture_outcome' => 'timed_out'],
        );

        $this->assertSame(MediaJobStatus::Failed->value, $failed['state']['status']);
        $this->assertSame(MediaJobStatus::TimedOut->value, $timedOut['state']['status']);
        foreach ([['images', $failed], ['scenes', $timedOut]] as [$phase, $submission]) {
            $job = $this->store()->job(
                $this->target['project'],
                $this->target['run'],
                $phase,
                $submission['job_id'],
            );
            $this->assertIsArray($job['result']);
            $this->assertSame($submission['state']['status'], $job['result']['status']);
            $this->assertNotEmpty($job['result']['error']);
        }
    }

    public function test_pending_cancellation_is_terminal_and_idempotent(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);
        $submission = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--cancelled',
            'image',
        );

        $first = $this->media()->cancel(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $second = $this->media()->cancel(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );

        $this->assertSame(MediaJobStatus::Cancelled->value, $first['status']);
        $this->assertSame($first, $second);
        $job = $this->store()->job(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $this->assertSame(['pending', 'cancelled'], array_column($job['events'], 'event'));
        $this->assertTrue($job['state']['cancel_requested']);
    }

    public function test_running_cancellation_and_worker_timeout_are_terminalized_truthfully(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);
        $running = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--running-cancel',
            'image',
        );
        $claim = $this->store()->begin(
            $this->target['project'],
            $this->target['run'],
            'images',
            $running['job_id'],
        );
        $requested = $this->media()->cancel(
            $this->target['project'],
            $this->target['run'],
            'images',
            $running['job_id'],
        );
        $duplicate = $this->media()->process(
            $this->target['project'],
            $this->target['run'],
            'images',
            $running['job_id'],
        );
        $cancelled = $this->store()->finish(
            $this->target['project'],
            $this->target['run'],
            'images',
            $running['job_id'],
            MediaJobStatus::Cancelled,
            [],
            'The claimed worker honored cancellation at its next safe checkpoint.',
        );

        $this->assertTrue($claim['claimed']);
        $this->assertSame('running', $requested['status']);
        $this->assertTrue($requested['cancel_requested']);
        $this->assertSame('running', $duplicate['status']);
        $this->assertSame('cancelled', $cancelled['status']);

        $timedOut = $this->media()->submit(
            $this->target['project'],
            $this->target['run'],
            'scenes',
            'fixture-scene--worker-timeout',
            'video',
        );
        $job = new ProcessSimulatedMediaJob(
            $this->target['project'],
            $this->target['run'],
            'scenes',
            $timedOut['job_id'],
        );
        $job->failed(new RuntimeException('Queue worker timeout exceeded.'));

        $this->assertSame(
            'timed_out',
            $this->store()->state(
                $this->target['project'],
                $this->target['run'],
                'scenes',
                $timedOut['job_id'],
            )['status'],
        );
    }

    public function test_cancellation_wins_the_atomic_media_promotion_commit(): void
    {
        $submission = $this->media()->reserve(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--atomic-cancel',
            'image',
        );
        $store = $this->store();
        $store->begin(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $store->requestCancellation(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
        );
        $commitRan = false;

        $state = $store->commitCompletion(
            $this->target['project'],
            $this->target['run'],
            'images',
            $submission['job_id'],
            function () use (&$commitRan): array {
                $commitRan = true;

                return ['asset_path' => 'assets/fixture-image--atomic-cancel.png'];
            },
            'This callback must not run after cancellation owns the final lock.',
        );

        $this->assertFalse($commitRan);
        $this->assertSame(MediaJobStatus::Cancelled->value, $state['status']);
        $this->assertSame(
            MediaJobStatus::Cancelled->value,
            $store->result(
                $this->target['project'],
                $this->target['run'],
                'images',
                $submission['job_id'],
            )['status'] ?? null,
        );
    }

    public function test_recovery_terminalizes_an_expired_running_batch_without_deleting_files(): void
    {
        Queue::fake();
        config(['creative.media.execution' => 'queue']);
        Carbon::setTestNow('2026-07-28 10:00:00 UTC');
        $jobs = [];
        foreach (['one', 'two'] as $suffix) {
            $submission = $this->media()->submit(
                $this->target['project'],
                $this->target['run'],
                'scenes',
                "fixture-scene--{$suffix}",
                'video',
            );
            $this->store()->begin(
                $this->target['project'],
                $this->target['run'],
                'scenes',
                $submission['job_id'],
            );
            $jobs[] = $submission['job_id'];
        }
        Carbon::setTestNow('2026-07-28 10:00:03 UTC');

        $recovered = $this->media()->recover(
            $this->target['project'],
            $this->target['run'],
            'scenes',
        );

        $this->assertEqualsCanonicalizing($jobs, $recovered['timed_out']);
        $this->assertSame([], $recovered['completed']);
        foreach ($jobs as $jobId) {
            $job = $this->store()->job(
                $this->target['project'],
                $this->target['run'],
                'scenes',
                $jobId,
            );
            $this->assertSame(MediaJobStatus::TimedOut->value, $job['state']['status']);
            $this->assertTrue($job['result']['recovered']);
            $this->assertFileExists($job['path'].'/request.md');
        }
    }

    public function test_attempt_recovery_closes_only_pending_and_running_jobs_owned_by_that_generation(): void
    {
        $attemptId = '20260728-120000--generation--interrupted--images';
        $pending = $this->media()->reserve(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--attempt-pending',
            'image',
            context: ['generation_attempt_id' => $attemptId],
        );
        $running = $this->media()->reserve(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--attempt-running',
            'image',
            context: ['generation_attempt_id' => $attemptId],
        );
        $unrelated = $this->media()->reserve(
            $this->target['project'],
            $this->target['run'],
            'images',
            'fixture-image--unrelated-pending',
            'image',
            context: ['generation_attempt_id' => '20260728-120000--generation--other--images'],
        );
        $this->store()->begin(
            $this->target['project'],
            $this->target['run'],
            'images',
            $running['job_id'],
        );

        $recovered = $this->media()->recoverAttempt(
            $this->target['project'],
            $this->target['run'],
            'images',
            $attemptId,
        );

        $this->assertSame([$pending['job_id']], $recovered['cancelled']);
        $this->assertSame([$running['job_id']], $recovered['timed_out']);
        $this->assertSame(
            MediaJobStatus::Cancelled->value,
            $this->store()->state(
                $this->target['project'],
                $this->target['run'],
                'images',
                $pending['job_id'],
            )['status'],
        );
        $this->assertSame(
            MediaJobStatus::TimedOut->value,
            $this->store()->state(
                $this->target['project'],
                $this->target['run'],
                'images',
                $running['job_id'],
            )['status'],
        );
        $this->assertSame(
            MediaJobStatus::Pending->value,
            $this->store()->state(
                $this->target['project'],
                $this->target['run'],
                'images',
                $unrelated['job_id'],
            )['status'],
        );
    }

    private function media(): SimulatedMediaJobService
    {
        return app(SimulatedMediaJobService::class);
    }

    private function store(): MediaJobStore
    {
        return app(MediaJobStore::class);
    }

    private function requireFfmpeg(): void
    {
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            $process = new Process([(string) config("creative.media.{$binary}_binary", $binary), '-version']);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->markTestSkipped("{$binary} is required for deterministic audio verification.");
            }
        }
    }
}
