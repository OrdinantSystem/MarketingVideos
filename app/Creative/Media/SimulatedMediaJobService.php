<?php

namespace App\Creative\Media;

use App\Jobs\ProcessSimulatedMediaJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class SimulatedMediaJobService
{
    public function __construct(
        private readonly MediaJobStore $jobs,
        private readonly DeterministicMediaRenderer $renderer,
        private readonly MediaProbeService $probe,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $context
     * @return array{job_id: string, state: array<string, mixed>}
     */
    public function submit(
        string $project,
        string $run,
        string $phase,
        string $artifactId,
        string $mediaType,
        array $parameters = [],
        array $context = [],
        ?string $jobId = null,
    ): array {
        $submission = $this->reserve(
            $project,
            $run,
            $phase,
            $artifactId,
            $mediaType,
            $parameters,
            $context,
            $jobId,
        );
        $jobId = $submission['job_id'];
        $state = $submission['state'];

        if (config('creative.media.execution', 'sync') === 'queue') {
            ProcessSimulatedMediaJob::dispatch($project, $run, $phase, $jobId)
                ->onQueue((string) config('creative.media.queue', 'creative-media'));
        } else {
            $state = $this->process($project, $run, $phase, $jobId);
        }

        return ['job_id' => $jobId, 'state' => $state];
    }

    /**
     * Reserve the complete immutable request without dispatching it. Batch callers use
     * this to persist every intended output before the first renderer can start.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $context
     * @return array{job_id: string, state: array<string, mixed>}
     */
    public function reserve(
        string $project,
        string $run,
        string $phase,
        string $artifactId,
        string $mediaType,
        array $parameters = [],
        array $context = [],
        ?string $jobId = null,
    ): array {
        $extension = match ($mediaType) {
            'image' => 'png',
            'video' => 'mp4',
            'audio' => 'wav',
            default => throw new RuntimeException('The simulated media type is unsupported.'),
        };
        $jobId ??= $this->newJobId();
        $reservedAssetPath = $context['asset_path'] ?? null;
        unset($context['asset_path']);
        $assetPath = is_string($reservedAssetPath) && trim($reservedAssetPath) !== ''
            ? trim($reservedAssetPath)
            : "assets/{$artifactId}.{$extension}";
        $state = $this->jobs->create($project, $run, $phase, $jobId, array_merge($context, [
            'artifact_id' => $artifactId,
            'media_type' => $mediaType,
            'asset_path' => $assetPath,
            'parameters' => $parameters,
            'timeout_seconds' => max(1, (int) config('creative.media.timeout_seconds', 30)),
            'renderer' => 'deterministic-local-v1',
        ]));

        return ['job_id' => $jobId, 'state' => $state];
    }

    /** @return array<string, mixed> */
    public function process(string $project, string $run, string $phase, string $jobId): array
    {
        $claim = $this->jobs->begin($project, $run, $phase, $jobId);
        $state = $claim['state'];
        if (! $claim['claimed']) {
            return $state;
        }

        $request = $this->jobs->request($project, $run, $phase, $jobId);
        $mediaType = $request['media_type'] ?? null;
        $assetPath = $request['asset_path'] ?? null;
        if (! is_string($mediaType) || ! is_string($assetPath)) {
            return $this->jobs->finish(
                $project,
                $run,
                $phase,
                $jobId,
                MediaJobStatus::Failed,
                ['error' => 'The immutable media request is incomplete.'],
                'The media job could not run because its request contract is incomplete.',
            );
        }

        $attempt = max(1, (int) ($state['attempt'] ?? 1));
        $target = $this->jobs->assetPath($project, $run, $phase, $assetPath);
        $staging = $this->jobs->jobPath($project, $run, $phase, $jobId)
            .'/staging/attempt-'.str_pad((string) $attempt, 3, '0', STR_PAD_LEFT).'/'.basename($assetPath);

        try {
            if ($this->cancelRequested($project, $run, $phase, $jobId)) {
                return $this->jobs->finish(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    MediaJobStatus::Cancelled,
                    [],
                    'Cancellation was honored before local rendering began.',
                );
            }

            $this->renderer->render($request, $staging);
            $probe = $this->probe->probe($staging, $mediaType);

            $state = $this->jobs->commitCompletion(
                $project,
                $run,
                $phase,
                $jobId,
                function () use ($request, $assetPath, $mediaType, $staging, $target, $probe): array {
                    File::ensureDirectoryExists(dirname($target));
                    if (File::exists($target) || is_link($target) || ! link($staging, $target)) {
                        throw new RuntimeException('The immutable media asset already exists or could not be promoted.');
                    }

                    $targetProbe = $this->probe->probe($target, $mediaType);
                    if ($probe['asset_sha256'] !== $targetProbe['asset_sha256'] || $probe['asset_bytes'] !== $targetProbe['asset_bytes']) {
                        throw new RuntimeException('The promoted media binary does not match its staged identity.');
                    }

                    return [
                        'artifact_id' => $request['artifact_id'] ?? null,
                        'asset_path' => $assetPath,
                        'asset_sha256' => $targetProbe['asset_sha256'],
                        'asset_bytes' => $targetProbe['asset_bytes'],
                        'media_probe' => $this->contractProbe($targetProbe),
                    ];
                },
                'The staged deterministic binary passed local probing and was promoted immutably.',
                ['staging_path' => $this->relativeJobPath($staging, $jobId)],
                static function () use ($staging, $target): void {
                    $sourceInode = is_file($staging) ? fileinode($staging) : false;
                    $targetInode = is_file($target) ? fileinode($target) : false;
                    if (is_int($sourceInode) && $sourceInode === $targetInode) {
                        File::delete($target);
                    }
                },
            );

            if (($state['status'] ?? null) === MediaJobStatus::Completed->value && is_file($staging)) {
                unlink($staging);
            }

            return $state;
        } catch (MediaJobTimedOut|ProcessTimedOutException $exception) {
            return $this->jobs->finish(
                $project,
                $run,
                $phase,
                $jobId,
                MediaJobStatus::TimedOut,
                ['error' => Str::limit($exception->getMessage(), 500)],
                'The deterministic fixture crossed its configured deadline.',
            );
        } catch (Throwable $exception) {
            return $this->jobs->finish(
                $project,
                $run,
                $phase,
                $jobId,
                MediaJobStatus::Failed,
                ['error' => Str::limit($exception->getMessage(), 500)],
                'The deterministic media job failed before a valid asset was promoted.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function cancel(string $project, string $run, string $phase, string $jobId): array
    {
        return $this->jobs->requestCancellation($project, $run, $phase, $jobId);
    }

    /** @return array<string, mixed> */
    public function terminalizeWorkerFailure(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        Throwable $exception,
    ): array {
        $state = $this->jobs->reconcileTerminalResult($project, $run, $phase, $jobId);
        $status = MediaJobStatus::tryFrom((string) ($state['status'] ?? ''));
        if ($status?->terminal() === true) {
            return $state;
        }

        $failure = Str::lower($exception::class.' '.$exception->getMessage());
        $terminalStatus = str_contains($failure, 'timeout') || str_contains($failure, 'timed out')
            ? MediaJobStatus::TimedOut
            : MediaJobStatus::Failed;

        return $this->jobs->finish(
            $project,
            $run,
            $phase,
            $jobId,
            $terminalStatus,
            ['error' => Str::limit($exception->getMessage(), 500), 'worker_failure' => true],
            'The queue worker terminalized an interrupted deterministic media job.',
        );
    }

    /**
     * Recover every stale running job in a phase. A binary that was already promoted is
     * reconciled to completed; otherwise the expired job becomes explicitly timed out.
     *
     * @return array{completed: list<string>, timed_out: list<string>, skipped: list<string>}
     */
    public function recover(string $project, string $run, string $phase): array
    {
        $recovered = ['completed' => [], 'timed_out' => [], 'skipped' => []];
        $now = now('UTC');

        foreach ($this->jobs->jobs($project, $run, $phase) as $job) {
            $jobId = $job['job_id'];
            $state = $job['state'];
            $request = $job['request'];
            if (! is_array($state) || ! is_array($request) || ($state['status'] ?? null) !== MediaJobStatus::Running->value) {
                $recovered['skipped'][] = $jobId;

                continue;
            }

            $startedAt = is_string($state['started_at'] ?? null) ? $state['started_at'] : null;
            $timeout = max(1, (int) ($request['timeout_seconds'] ?? config('creative.media.timeout_seconds', 30)));
            if ($startedAt === null || CarbonImmutable::parse($startedAt)->addSeconds($timeout)->isFuture()) {
                $recovered['skipped'][] = $jobId;

                continue;
            }

            $assetPath = $request['asset_path'] ?? null;
            $mediaType = $request['media_type'] ?? null;
            if (is_string($assetPath) && is_string($mediaType)) {
                $target = $this->jobs->assetPath($project, $run, $phase, $assetPath);
                if (File::isFile($target)) {
                    try {
                        $completion = $this->jobs->commitCompletion(
                            $project,
                            $run,
                            $phase,
                            $jobId,
                            function () use ($request, $assetPath, $target, $mediaType): array {
                                $probe = $this->probe->probe($target, $mediaType);

                                return [
                                    'artifact_id' => $request['artifact_id'] ?? null,
                                    'asset_path' => $assetPath,
                                    'asset_sha256' => $probe['asset_sha256'],
                                    'asset_bytes' => $probe['asset_bytes'],
                                    'media_probe' => $this->contractProbe($probe),
                                    'recovered' => true,
                                ];
                            },
                            'Recovery found and probed the already-promoted deterministic binary.',
                            ['asset_path' => $assetPath, 'recovered' => true],
                        );
                        if (($completion['status'] ?? null) === MediaJobStatus::Completed->value) {
                            $recovered['completed'][] = $jobId;
                        } else {
                            $recovered['skipped'][] = $jobId;
                        }

                        continue;
                    } catch (Throwable) {
                        // The invalid promoted binary is left intact for the integrity checker.
                    }
                }
            }

            $this->jobs->finish(
                $project,
                $run,
                $phase,
                $jobId,
                MediaJobStatus::TimedOut,
                [
                    'error' => 'The running checkpoint expired without a valid promoted binary.',
                    'recovered' => true,
                    'recovered_at' => $now->format('Y-m-d\TH:i:s.uP'),
                ],
                'Recovery terminalized an expired running job that had no valid promoted binary.',
            );
            $recovered['timed_out'][] = $jobId;
        }

        return $recovered;
    }

    /**
     * Close every non-terminal job owned by an interrupted generation attempt before
     * automation spends another bounded attempt. Pending work is cancelled. Running
     * work is reconciled to completed only when its promoted binary probes cleanly;
     * otherwise it is terminalized as timed out because its owning process is gone.
     *
     * @return array{completed: list<string>, timed_out: list<string>, cancelled: list<string>, skipped: list<string>}
     */
    public function recoverAttempt(
        string $project,
        string $run,
        string $phase,
        string $generationAttemptId,
    ): array {
        $recovered = ['completed' => [], 'timed_out' => [], 'cancelled' => [], 'skipped' => []];

        foreach ($this->jobs->jobs($project, $run, $phase) as $job) {
            $jobId = $job['job_id'];
            $state = $job['state'];
            $request = $job['request'];
            if (! is_array($state)
                || ! is_array($request)
                || ($request['generation_attempt_id'] ?? null) !== $generationAttemptId) {
                $recovered['skipped'][] = $jobId;

                continue;
            }

            $status = MediaJobStatus::tryFrom((string) ($state['status'] ?? ''));
            if ($status?->terminal() === true) {
                $recovered['skipped'][] = $jobId;

                continue;
            }

            if ($status === MediaJobStatus::Pending) {
                $this->jobs->finish(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    MediaJobStatus::Cancelled,
                    [
                        'generation_attempt_id' => $generationAttemptId,
                        'recovered' => true,
                        'error' => 'The parent generation attempt ended before this reserved media job started.',
                    ],
                    'Recovery cancelled pending media work owned by an interrupted generation attempt.',
                );
                $recovered['cancelled'][] = $jobId;

                continue;
            }

            if ($status !== MediaJobStatus::Running) {
                $recovered['skipped'][] = $jobId;

                continue;
            }

            $assetPath = $request['asset_path'] ?? null;
            $mediaType = $request['media_type'] ?? null;
            if (is_string($assetPath) && is_string($mediaType)) {
                $target = $this->jobs->assetPath($project, $run, $phase, $assetPath);
                if (File::isFile($target)) {
                    try {
                        $completion = $this->jobs->commitCompletion(
                            $project,
                            $run,
                            $phase,
                            $jobId,
                            function () use ($request, $assetPath, $target, $mediaType, $generationAttemptId): array {
                                $probe = $this->probe->probe($target, $mediaType);

                                return [
                                    'artifact_id' => $request['artifact_id'] ?? null,
                                    'asset_path' => $assetPath,
                                    'asset_sha256' => $probe['asset_sha256'],
                                    'asset_bytes' => $probe['asset_bytes'],
                                    'media_probe' => $this->contractProbe($probe),
                                    'generation_attempt_id' => $generationAttemptId,
                                    'recovered' => true,
                                ];
                            },
                            'Recovery found and probed a binary promoted by the interrupted generation attempt.',
                            [
                                'asset_path' => $assetPath,
                                'generation_attempt_id' => $generationAttemptId,
                                'recovered' => true,
                            ],
                        );
                        if (($completion['status'] ?? null) === MediaJobStatus::Completed->value) {
                            $recovered['completed'][] = $jobId;
                        } elseif (($completion['status'] ?? null) === MediaJobStatus::Cancelled->value) {
                            $recovered['cancelled'][] = $jobId;
                        } else {
                            $recovered['skipped'][] = $jobId;
                        }

                        continue;
                    } catch (Throwable) {
                        // Preserve invalid bytes for the integrity checker and close the abandoned job below.
                    }
                }
            }

            $this->jobs->finish(
                $project,
                $run,
                $phase,
                $jobId,
                MediaJobStatus::TimedOut,
                [
                    'generation_attempt_id' => $generationAttemptId,
                    'recovered' => true,
                    'error' => 'The parent generation attempt ended while this job was running without a valid promoted binary.',
                ],
                'Recovery timed out abandoned running media work before the next generation attempt.',
            );
            $recovered['timed_out'][] = $jobId;
        }

        return $recovered;
    }

    private function newJobId(): string
    {
        return 'media-job-'.now('UTC')->format('Ymd-His-u').'--'.Str::lower(Str::random(8));
    }

    /**
     * @param  array<string, int|float|string|null>  $probe
     * @return array<string, int|float|string|null>
     */
    private function contractProbe(array $probe): array
    {
        unset($probe['asset_bytes'], $probe['asset_sha256']);

        return $probe;
    }

    private function relativeJobPath(string $path, string $jobId): string
    {
        $marker = '/'.$jobId.'/';
        $position = strpos($path, $marker);

        return $position === false ? basename($path) : substr($path, $position + strlen($marker));
    }

    /** @phpstan-impure */
    private function cancelRequested(string $project, string $run, string $phase, string $jobId): bool
    {
        return ($this->jobs->state($project, $run, $phase, $jobId)['cancel_requested'] ?? false) === true;
    }
}
