<?php

namespace App\Creative\Media;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePathResolver;
use App\Creative\CreativePhaseCatalog;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MediaJobStore
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativePhaseCatalog $phases,
        private readonly CreativeLockManager $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function create(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        array $metadata,
    ): array {
        $this->assertJobId($jobId);
        $this->phases->assertValid($phase);

        return $this->locks->exclusive($this->lockKey($project, $run, $phase, $jobId), function () use ($project, $run, $phase, $jobId, $metadata): array {
            if ($this->exists($project, $run, $phase, $jobId)) {
                throw new RuntimeException('The immutable media job already exists.');
            }

            $mediaType = $metadata['media_type'] ?? null;
            if (! is_string($mediaType) || ! in_array($mediaType, ['image', 'video', 'audio'], true)) {
                throw new RuntimeException('The media job type must be image, video, or audio.');
            }

            $assetPath = $metadata['asset_path'] ?? null;
            if (! is_string($assetPath) || ! $this->validRelativePath($assetPath)) {
                throw new RuntimeException('The media job asset path must be a safe relative path.');
            }

            $createdAt = $this->timestamp();
            $request = array_merge($metadata, [
                'schema_version' => 1,
                'id' => $jobId,
                'type' => 'media_job_request',
                'job_id' => $jobId,
                'project_id' => $project,
                'run_id' => $run,
                'phase' => $phase,
                'simulation' => true,
                'created_at' => $createdAt,
            ]);
            MarkdownDocument::write(
                $this->requestPath($project, $run, $phase, $jobId),
                $request,
                "# Simulated media job request\n\nThis immutable request describes one local, deterministic media operation. It cannot contact a provider.",
                false,
            );

            $state = [
                'schema_version' => 1,
                'id' => $jobId.'--state',
                'type' => 'media_job_state',
                'job_id' => $jobId,
                'status' => MediaJobStatus::Pending->value,
                'attempt' => 0,
                'cancel_requested' => false,
                'started_at' => null,
                'finished_at' => null,
                'last_error' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $this->writeState($project, $run, $phase, $jobId, $state, false);
            $this->appendEventUnlocked(
                $project,
                $run,
                $phase,
                $jobId,
                'pending',
                ['status' => MediaJobStatus::Pending->value],
                'The deterministic media job is waiting to run.',
            );

            return $state;
        });
    }

    public function exists(string $project, string $run, string $phase, string $jobId): bool
    {
        return File::isFile($this->requestPath($project, $run, $phase, $jobId));
    }

    /** @return array<string, mixed> */
    public function request(string $project, string $run, string $phase, string $jobId): array
    {
        return $this->readMetadata($this->requestPath($project, $run, $phase, $jobId), 'Media job request');
    }

    /** @return array<string, mixed> */
    public function state(string $project, string $run, string $phase, string $jobId): array
    {
        return $this->readMetadata($this->statePath($project, $run, $phase, $jobId), 'Media job state');
    }

    /** @return array<string, mixed>|null */
    public function result(string $project, string $run, string $phase, string $jobId): ?array
    {
        $path = $this->resultPath($project, $run, $phase, $jobId);

        return File::isFile($path) ? $this->readMetadata($path, 'Media job result') : null;
    }

    /**
     * @return array{job_id: string, request: array<string, mixed>|null, state: array<string, mixed>|null, result: array<string, mixed>|null, events: list<array<string, mixed>>, path: string, parse_errors: list<string>}
     */
    public function job(string $project, string $run, string $phase, string $jobId): array
    {
        $errors = [];
        $request = null;
        $state = null;
        $result = null;

        try {
            $request = $this->request($project, $run, $phase, $jobId);
        } catch (Throwable $exception) {
            $errors[] = 'Request: '.$exception->getMessage();
        }

        try {
            $state = $this->state($project, $run, $phase, $jobId);
        } catch (Throwable $exception) {
            $errors[] = 'State: '.$exception->getMessage();
        }

        try {
            $result = $this->result($project, $run, $phase, $jobId);
        } catch (Throwable $exception) {
            $errors[] = 'Result: '.$exception->getMessage();
        }

        return [
            'job_id' => $jobId,
            'request' => $request,
            'state' => $state,
            'result' => $result,
            'events' => $this->events($project, $run, $phase, $jobId),
            'path' => $this->jobPath($project, $run, $phase, $jobId),
            'parse_errors' => $errors,
        ];
    }

    /**
     * Rebuild the mutable state/event projection when an immutable terminal result
     * was committed immediately before a worker interruption. The result is the
     * commit fence: a later failure callback must not replace a completed result
     * with a contradictory failure.
     *
     * @return array<string, mixed>
     */
    public function reconcileTerminalResult(
        string $project,
        string $run,
        string $phase,
        string $jobId,
    ): array {
        return $this->locks->exclusive(
            $this->lockKey($project, $run, $phase, $jobId),
            fn (): array => $this->reconcileTerminalResultUnlocked($project, $run, $phase, $jobId),
        );
    }

    /**
     * @return list<array{job_id: string, request: array<string, mixed>|null, state: array<string, mixed>|null, result: array<string, mixed>|null, events: list<array<string, mixed>>, path: string, parse_errors: list<string>}>
     */
    public function jobs(string $project, string $run, string $phase): array
    {
        $directory = $this->jobsPath($project, $run, $phase);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::directories($directory))
            ->map(fn (string $path): array => $this->job($project, $run, $phase, basename($path)))
            ->sortBy(fn (array $job): string => $job['job_id'])
            ->all());
    }

    /** @return array{claimed: bool, state: array<string, mixed>} */
    public function begin(string $project, string $run, string $phase, string $jobId): array
    {
        return $this->locks->exclusive($this->lockKey($project, $run, $phase, $jobId), function () use ($project, $run, $phase, $jobId): array {
            $state = $this->reconcileTerminalResultUnlocked($project, $run, $phase, $jobId);
            $status = $this->status($state);
            if ($status !== MediaJobStatus::Pending) {
                return ['claimed' => false, 'state' => $state];
            }

            if (($state['cancel_requested'] ?? false) === true) {
                return [
                    'claimed' => false,
                    'state' => $this->finishUnlocked(
                        $project,
                        $run,
                        $phase,
                        $jobId,
                        MediaJobStatus::Cancelled,
                        [],
                        'The pending media job was cancelled before it started.',
                    ),
                ];
            }

            $startedAt = $this->timestamp();
            $state = array_merge($state, [
                'status' => MediaJobStatus::Running->value,
                'attempt' => ((int) ($state['attempt'] ?? 0)) + 1,
                'started_at' => $startedAt,
                'finished_at' => null,
                'last_error' => null,
                'updated_at' => $startedAt,
            ]);
            $this->writeState($project, $run, $phase, $jobId, $state);
            $this->appendEventUnlocked(
                $project,
                $run,
                $phase,
                $jobId,
                'running',
                ['status' => MediaJobStatus::Running->value, 'attempt' => $state['attempt']],
                'The deterministic renderer claimed the job.',
            );

            return ['claimed' => true, 'state' => $state];
        });
    }

    /** @return array<string, mixed> */
    public function requestCancellation(string $project, string $run, string $phase, string $jobId): array
    {
        return $this->locks->exclusive($this->lockKey($project, $run, $phase, $jobId), function () use ($project, $run, $phase, $jobId): array {
            $state = $this->reconcileTerminalResultUnlocked($project, $run, $phase, $jobId);
            $status = $this->status($state);
            if ($status->terminal()) {
                return $state;
            }

            if ($status === MediaJobStatus::Pending) {
                return $this->finishUnlocked(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    MediaJobStatus::Cancelled,
                    [],
                    'The pending media job was cancelled before it started.',
                );
            }

            $state = array_merge($state, [
                'cancel_requested' => true,
                'updated_at' => $this->timestamp(),
            ]);
            $this->writeState($project, $run, $phase, $jobId, $state);
            $this->appendEventUnlocked(
                $project,
                $run,
                $phase,
                $jobId,
                'cancellation_requested',
                ['status' => $status->value],
                'Cancellation will be honored at the next safe checkpoint.',
            );

            return $state;
        });
    }

    /**
     * Commit the last filesystem operation and the completed terminal records while
     * holding the same job lock used by cancellation. The callback is never invoked
     * when cancellation or another terminal transition won the race.
     *
     * @param  callable(): array<string, mixed>  $commit
     * @param  array<string, mixed>  $cancellationMetadata
     * @param  (callable(): void)|null  $rollback
     * @return array<string, mixed>
     */
    public function commitCompletion(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        callable $commit,
        string $message,
        array $cancellationMetadata = [],
        ?callable $rollback = null,
    ): array {
        return $this->locks->exclusive($this->lockKey($project, $run, $phase, $jobId), function () use ($project, $run, $phase, $jobId, $commit, $message, $cancellationMetadata, $rollback): array {
            $state = $this->reconcileTerminalResultUnlocked($project, $run, $phase, $jobId);
            $status = $this->status($state);
            if ($status->terminal()) {
                return $state;
            }
            if ($status !== MediaJobStatus::Running) {
                throw new RuntimeException('Only a running media job may commit a completed asset.');
            }

            if (($state['cancel_requested'] ?? false) === true) {
                return $this->finishUnlocked(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    MediaJobStatus::Cancelled,
                    $cancellationMetadata,
                    'Cancellation won the final commit lock before the media asset was promoted.',
                );
            }

            try {
                return $this->finishUnlocked(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    MediaJobStatus::Completed,
                    $commit(),
                    $message,
                );
            } catch (Throwable $exception) {
                if ($rollback !== null && ! $this->hasCommittedResult($project, $run, $phase, $jobId, MediaJobStatus::Completed)) {
                    $rollback();
                }

                throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function finish(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        MediaJobStatus $status,
        array $metadata,
        string $message,
    ): array {
        if (! $status->terminal()) {
            throw new RuntimeException('A media job may only be finished with a terminal status.');
        }

        return $this->locks->exclusive(
            $this->lockKey($project, $run, $phase, $jobId),
            fn (): array => $this->finishUnlocked($project, $run, $phase, $jobId, $status, $metadata, $message),
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(string $project, string $run, string $phase, string $jobId): array
    {
        $directory = $this->eventsPath($project, $run, $phase, $jobId);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename())
            ->map(function (\SplFileInfo $file): array {
                try {
                    $document = MarkdownDocument::read($file->getPathname());

                    return $document['metadata'] + [
                        '_body' => $document['body'],
                        '_path' => $file->getPathname(),
                    ];
                } catch (Throwable $exception) {
                    return [
                        'parse_error' => $exception->getMessage(),
                        '_path' => $file->getPathname(),
                    ];
                }
            })->all());
    }

    public function jobPath(string $project, string $run, string $phase, string $jobId): string
    {
        $this->assertJobId($jobId);

        return $this->jobsPath($project, $run, $phase).'/'.$jobId;
    }

    public function assetPath(string $project, string $run, string $phase, string $relativePath): string
    {
        if (! $this->validRelativePath($relativePath)) {
            throw new RuntimeException('The media asset path is unsafe.');
        }

        $phaseRoot = dirname($this->paths->outputsPath($project, $run, $phase));
        $runRoot = realpath($this->paths->runPath($project, $run));
        $realPhaseRoot = realpath($phaseRoot);
        if ($runRoot === false
            || $realPhaseRoot === false
            || ! str_starts_with($realPhaseRoot, $runRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The media phase root cannot be resolved inside the creative run.');
        }

        $parent = $realPhaseRoot;
        foreach (explode('/', dirname($relativePath)) as $segment) {
            if ($segment === '.') {
                continue;
            }

            $parent .= '/'.$segment;
            if (is_link($parent)) {
                throw new RuntimeException('The media asset path traverses a symbolic link.');
            }
            if (File::exists($parent)) {
                $realParent = realpath($parent);
                if ($realParent === false || ! str_starts_with($realParent, $runRoot.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('The media asset path resolves outside the creative run.');
                }
            }
        }

        return $realPhaseRoot.'/'.$relativePath;
    }

    /** @return array<string, mixed> */
    private function reconcileTerminalResultUnlocked(
        string $project,
        string $run,
        string $phase,
        string $jobId,
    ): array {
        $state = $this->state($project, $run, $phase, $jobId);
        $result = $this->result($project, $run, $phase, $jobId);
        if ($result === null) {
            return $state;
        }

        $resultStatus = is_string($result['status'] ?? null)
            ? MediaJobStatus::tryFrom($result['status'])
            : null;
        $finishedAt = $result['finished_at'] ?? null;
        $attempt = $result['attempt'] ?? null;
        if (($result['schema_version'] ?? null) !== 1
            || ($result['id'] ?? null) !== $jobId.'--result'
            || ($result['type'] ?? null) !== 'media_job_result'
            || ($result['job_id'] ?? null) !== $jobId
            || ($result['phase'] ?? null) !== $phase
            || ($result['simulation'] ?? null) !== true
            || $resultStatus?->terminal() !== true
            || ! is_int($attempt)
            || $attempt < 0
            || ! is_string($finishedAt)
            || ! $this->validTimestamp($finishedAt)) {
            throw new RuntimeException('The immutable media result cannot be used as a terminal recovery fence.');
        }

        if ($resultStatus === MediaJobStatus::Completed) {
            $request = $this->request($project, $run, $phase, $jobId);
            $resultArtifactId = $result['artifact_id'] ?? null;
            $resultAssetPath = $result['asset_path'] ?? null;
            if (! is_string($resultArtifactId)
                || $resultArtifactId !== ($request['artifact_id'] ?? null)
                || ! is_string($resultAssetPath)
                || $resultAssetPath !== ($request['asset_path'] ?? null)) {
                throw new RuntimeException('The completed media result conflicts with its immutable request.');
            }
        }

        $currentStatus = $this->status($state);
        if ($currentStatus->terminal() && $currentStatus !== $resultStatus) {
            throw new RuntimeException('Media job state conflicts with its immutable terminal result.');
        }

        $lastError = is_string($result['error'] ?? null) ? $result['error'] : null;
        $needsStateRecovery = $currentStatus !== $resultStatus
            || ($state['attempt'] ?? null) !== $attempt
            || ($state['finished_at'] ?? null) !== $finishedAt
            || ($state['last_error'] ?? null) !== $lastError;
        if ($needsStateRecovery) {
            $state = array_merge($state, [
                'status' => $resultStatus->value,
                'attempt' => $attempt,
                'cancel_requested' => $resultStatus === MediaJobStatus::Cancelled
                    ? true
                    : (bool) ($state['cancel_requested'] ?? false),
                'finished_at' => $finishedAt,
                'last_error' => $lastError,
                'updated_at' => $finishedAt,
            ]);
            $this->writeState($project, $run, $phase, $jobId, $state);
        }

        $hasTerminalEvent = collect($this->events($project, $run, $phase, $jobId))->contains(
            fn (array $event): bool => ($event['status'] ?? null) === $resultStatus->value
                && in_array($event['event'] ?? null, [$resultStatus->value, 'terminal_recovered'], true),
        );
        if (! $hasTerminalEvent) {
            $this->appendEventUnlocked(
                $project,
                $run,
                $phase,
                $jobId,
                'terminal_recovered',
                [
                    'status' => $resultStatus->value,
                    'attempt' => $attempt,
                    'recovered_from_result' => true,
                ],
                'The mutable terminal checkpoint was recovered from the immutable media result commit fence.',
            );
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function finishUnlocked(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        MediaJobStatus $status,
        array $metadata,
        string $message,
    ): array {
        $state = $this->reconcileTerminalResultUnlocked($project, $run, $phase, $jobId);
        $currentStatus = $this->status($state);
        if ($currentStatus->terminal()) {
            if ($currentStatus !== $status) {
                throw new RuntimeException('The media job already has a different terminal result.');
            }

            if (! File::isFile($this->resultPath($project, $run, $phase, $jobId))) {
                $this->writeResult(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    $status,
                    $state,
                    $metadata,
                    $message,
                    is_string($state['finished_at'] ?? null) ? $state['finished_at'] : $this->timestamp(),
                );
            }

            return $state;
        }

        $finishedAt = $this->timestamp();
        if (File::isFile($this->resultPath($project, $run, $phase, $jobId))) {
            $existing = $this->readMetadata(
                $this->resultPath($project, $run, $phase, $jobId),
                'Media job result',
            );
            if (($existing['job_id'] ?? null) !== $jobId || ($existing['status'] ?? null) !== $status->value) {
                throw new RuntimeException('The immutable media result conflicts with the requested terminal transition.');
            }
            $finishedAt = is_string($existing['finished_at'] ?? null) ? $existing['finished_at'] : $finishedAt;
            $metadata = $existing;
        } else {
            $this->writeResult(
                $project,
                $run,
                $phase,
                $jobId,
                $status,
                $state,
                $metadata,
                $message,
                $finishedAt,
            );
        }

        $state = array_merge($state, [
            'status' => $status->value,
            'cancel_requested' => $status === MediaJobStatus::Cancelled
                ? true
                : (bool) ($state['cancel_requested'] ?? false),
            'finished_at' => $finishedAt,
            'last_error' => is_string($metadata['error'] ?? null) ? $metadata['error'] : null,
            'updated_at' => $finishedAt,
        ]);
        $this->writeState($project, $run, $phase, $jobId, $state);
        $this->appendEventUnlocked(
            $project,
            $run,
            $phase,
            $jobId,
            $status->value,
            ['status' => $status->value, 'attempt' => $state['attempt']],
            $message,
        );

        return $state;
    }

    private function hasCommittedResult(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        MediaJobStatus $status,
    ): bool {
        try {
            $result = $this->result($project, $run, $phase, $jobId);

            return is_array($result) && ($result['status'] ?? null) === $status->value;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $metadata
     */
    private function writeResult(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        MediaJobStatus $status,
        array $state,
        array $metadata,
        string $message,
        string $finishedAt,
    ): void {
        $resultMetadata = array_merge($metadata, [
            'schema_version' => 1,
            'id' => $jobId.'--result',
            'type' => 'media_job_result',
            'job_id' => $jobId,
            'phase' => $phase,
            'status' => $status->value,
            'simulation' => true,
            'attempt' => (int) ($state['attempt'] ?? 0),
            'finished_at' => $finishedAt,
        ]);
        MarkdownDocument::write(
            $this->resultPath($project, $run, $phase, $jobId),
            $resultMetadata,
            '# Simulated media job '.Str::headline($status->value)."\n\n{$message}",
            false,
        );
    }

    /** @param array<string, mixed> $state */
    private function status(array $state): MediaJobStatus
    {
        $status = $state['status'] ?? null;
        if (! is_string($status) || MediaJobStatus::tryFrom($status) === null) {
            throw new RuntimeException('The media job state has an invalid status.');
        }

        return MediaJobStatus::from($status);
    }

    /** @param array<string, mixed> $state */
    private function writeState(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        array $state,
        bool $overwrite = true,
    ): void {
        $body = "# Simulated media job state\n\n- Status: **".(string) ($state['status'] ?? 'unknown')."**\n- Attempt: **".(string) ($state['attempt'] ?? 0)."**\n\nThis atomic checkpoint can be reconstructed from the immutable request, result, and events.";
        MarkdownDocument::write($this->statePath($project, $run, $phase, $jobId), $state, $body, $overwrite);
    }

    /** @param array<string, mixed> $metadata */
    private function appendEventUnlocked(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        string $event,
        array $metadata,
        string $message,
    ): void {
        $events = $this->events($project, $run, $phase, $jobId);
        $sequence = ((int) collect($events)->max(fn (array $item): int => (int) ($item['sequence'] ?? 0))) + 1;
        $eventId = sprintf('%06d', $sequence).'--'.Str::slug($event).'--'.Str::lower(Str::random(6));
        MarkdownDocument::write(
            $this->eventsPath($project, $run, $phase, $jobId).'/'.$eventId.'.md',
            array_merge($metadata, [
                'schema_version' => 1,
                'id' => $eventId,
                'type' => 'media_job_event',
                'job_id' => $jobId,
                'event' => $event,
                'sequence' => $sequence,
                'created_at' => $this->timestamp(),
            ]),
            '# '.Str::headline($event)."\n\n{$message}",
            false,
        );
    }

    private function jobsPath(string $project, string $run, string $phase): string
    {
        return dirname($this->paths->outputsPath($project, $run, $phase)).'/media-jobs';
    }

    private function requestPath(string $project, string $run, string $phase, string $jobId): string
    {
        return $this->jobPath($project, $run, $phase, $jobId).'/request.md';
    }

    private function statePath(string $project, string $run, string $phase, string $jobId): string
    {
        return $this->jobPath($project, $run, $phase, $jobId).'/state.md';
    }

    private function resultPath(string $project, string $run, string $phase, string $jobId): string
    {
        return $this->jobPath($project, $run, $phase, $jobId).'/result.md';
    }

    private function eventsPath(string $project, string $run, string $phase, string $jobId): string
    {
        return $this->jobPath($project, $run, $phase, $jobId).'/events';
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path, string $label): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("{$label} does not exist.");
        }

        try {
            $metadata = MarkdownDocument::read($path)['metadata'];
        } catch (Throwable $exception) {
            throw new RuntimeException("{$label} is malformed.", previous: $exception);
        }

        if ($metadata === []) {
            throw new RuntimeException("{$label} has no metadata.");
        }

        return $metadata;
    }

    private function assertJobId(string $jobId): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*(?:--[a-z0-9-]+)+$/', $jobId) !== 1) {
            throw new RuntimeException('The media job identifier is invalid.');
        }
    }

    private function validRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! in_array('..', explode('/', $path), true)
            && preg_match('/^[a-zA-Z0-9._\/-]+$/', $path) === 1;
    }

    private function validTimestamp(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function lockKey(string $project, string $run, string $phase, string $jobId): string
    {
        return "media-job:{$project}:{$run}:{$phase}:{$jobId}";
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
