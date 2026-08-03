<?php

namespace App\Creative\Rendering;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePathResolver;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RenderStore
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativeLockManager $locks,
        private readonly RenderCompletionValidator $completion,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function create(string $project, string $run, string $renderId, array $metadata): void
    {
        $this->assertRenderId($renderId);
        $this->locks->exclusive($this->lockKey($project, $run, $renderId), function () use ($project, $run, $renderId, $metadata): void {
            if (File::isFile($this->requestPath($project, $run, $renderId))) {
                throw new RuntimeException('The immutable render request already exists.');
            }

            $createdAt = $this->timestamp();
            MarkdownDocument::write(
                $this->requestPath($project, $run, $renderId),
                array_merge($metadata, [
                    'schema_version' => 1,
                    'id' => $renderId,
                    'type' => 'render_request',
                    'render_id' => $renderId,
                    'project_id' => $project,
                    'run_id' => $run,
                    'simulation' => true,
                    'created_at' => $createdAt,
                ]),
                "# Deterministic render request\n\nThis immutable request assembles only selected local scene binaries. It cannot contact a provider.",
                false,
            );
            $this->writeState($project, $run, $renderId, [
                'schema_version' => 1,
                'id' => $renderId.'--state',
                'type' => 'render_state',
                'render_id' => $renderId,
                'status' => 'pending',
                'cancel_requested' => false,
                'started_at' => null,
                'finished_at' => null,
                'last_error' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ], false);
        });
    }

    /** @return array{claimed: bool, state: array<string, mixed>} */
    public function begin(string $project, string $run, string $renderId): array
    {
        return $this->locks->exclusive($this->lockKey($project, $run, $renderId), function () use ($project, $run, $renderId): array {
            $state = $this->reconciledStateUnlocked($project, $run, $renderId);
            if (($state['status'] ?? null) !== 'pending') {
                return ['claimed' => false, 'state' => $state];
            }

            if (($state['cancel_requested'] ?? false) === true) {
                return [
                    'claimed' => false,
                    'state' => $this->finishUnlocked(
                        $project,
                        $run,
                        $renderId,
                        'cancelled',
                        [],
                        'The render was cancelled before it started.',
                    ),
                ];
            }

            $state = array_merge($state, [
                'status' => 'running',
                'started_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);
            $this->writeState($project, $run, $renderId, $state);

            return ['claimed' => true, 'state' => $state];
        });
    }

    /** @return array<string, mixed> */
    public function cancel(string $project, string $run, string $renderId): array
    {
        return $this->locks->exclusive($this->lockKey($project, $run, $renderId), function () use ($project, $run, $renderId): array {
            $state = $this->reconciledStateUnlocked($project, $run, $renderId);
            if (in_array($state['status'] ?? null, ['completed', 'failed', 'cancelled', 'timed_out'], true)) {
                return $state;
            }

            if (($state['status'] ?? null) === 'pending') {
                return $this->finishUnlocked($project, $run, $renderId, 'cancelled', [], 'The pending render was cancelled.');
            }

            $state = array_merge($state, [
                'cancel_requested' => true,
                'updated_at' => $this->timestamp(),
            ]);
            $this->writeState($project, $run, $renderId, $state);

            return $state;
        });
    }

    /**
     * Promote the staged delivery and commit its immutable manifest while holding
     * the same render lock used by cancellation. The callback is skipped when a
     * cancellation or another terminal transition won the race.
     *
     * @param  callable(): array<string, mixed>  $commit
     * @param  array<string, mixed>  $cancellationMetadata
     * @param  (callable(): void)|null  $rollback
     * @return array<string, mixed>
     */
    public function commitCompletion(
        string $project,
        string $run,
        string $renderId,
        callable $commit,
        string $message,
        array $cancellationMetadata = [],
        ?callable $rollback = null,
    ): array {
        return $this->locks->exclusive($this->lockKey($project, $run, $renderId), function () use ($project, $run, $renderId, $commit, $message, $cancellationMetadata, $rollback): array {
            $state = $this->reconciledStateUnlocked($project, $run, $renderId);
            $status = $state['status'] ?? null;
            if (in_array($status, ['completed', 'failed', 'cancelled', 'timed_out'], true)) {
                return $state;
            }
            if ($status !== 'running') {
                throw new RuntimeException('Only a running render may commit a completed delivery.');
            }

            if (($state['cancel_requested'] ?? false) === true) {
                return $this->finishUnlocked(
                    $project,
                    $run,
                    $renderId,
                    'cancelled',
                    $cancellationMetadata,
                    'Cancellation won the final commit lock before render outputs were promoted.',
                );
            }

            try {
                return $this->finishUnlocked(
                    $project,
                    $run,
                    $renderId,
                    'completed',
                    $commit(),
                    $message,
                );
            } catch (Throwable $exception) {
                if ($rollback !== null && ! $this->hasCommittedResult($project, $run, $renderId, 'completed')) {
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
        string $renderId,
        string $status,
        array $metadata,
        string $message,
    ): array {
        if (! in_array($status, ['completed', 'failed', 'cancelled', 'timed_out'], true)) {
            throw new RuntimeException('The render result status is invalid.');
        }

        return $this->locks->exclusive(
            $this->lockKey($project, $run, $renderId),
            fn (): array => $this->finishUnlocked($project, $run, $renderId, $status, $metadata, $message),
        );
    }

    /** @return array<string, mixed> */
    public function request(string $project, string $run, string $renderId): array
    {
        return $this->read($this->requestPath($project, $run, $renderId), 'Render request');
    }

    /** @return array<string, mixed> */
    public function state(string $project, string $run, string $renderId): array
    {
        return $this->locks->exclusive(
            $this->lockKey($project, $run, $renderId),
            fn (): array => $this->reconciledStateUnlocked($project, $run, $renderId),
        );
    }

    /** @return array<string, mixed>|null */
    public function result(string $project, string $run, string $renderId): ?array
    {
        $path = $this->resultPath($project, $run, $renderId);

        return File::isFile($path) ? $this->read($path, 'Render result') : null;
    }

    /** @return list<array<string, mixed>> */
    public function renders(string $project, string $run): array
    {
        $directory = $this->rendersPath($project, $run);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::directories($directory))->map(function (string $path) use ($project, $run): array {
            $renderId = basename($path);
            try {
                return [
                    'id' => $renderId,
                    'request' => $this->request($project, $run, $renderId),
                    'state' => $this->state($project, $run, $renderId),
                    'result' => $this->result($project, $run, $renderId),
                    'path' => Str::after($path, $this->paths->root().'/'),
                ];
            } catch (Throwable $exception) {
                return [
                    'id' => $renderId,
                    'request' => null,
                    'state' => null,
                    'result' => null,
                    'path' => Str::after($path, $this->paths->root().'/'),
                    'parse_error' => $exception->getMessage(),
                ];
            }
        })->sortByDesc('id')->values()->all());
    }

    public function path(string $project, string $run, string $renderId): string
    {
        $this->assertRenderId($renderId);

        return $this->rendersPath($project, $run).'/'.$renderId;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function finishUnlocked(
        string $project,
        string $run,
        string $renderId,
        string $status,
        array $metadata,
        string $message,
    ): array {
        $state = $this->reconciledStateUnlocked($project, $run, $renderId);
        $current = $state['status'] ?? null;
        if (in_array($current, ['completed', 'failed', 'cancelled', 'timed_out'], true)) {
            if ($current !== $status) {
                if ($current === 'completed' && File::isFile($this->resultPath($project, $run, $renderId))) {
                    return $state;
                }

                throw new RuntimeException('The render already has a different terminal result.');
            }

            if (! File::isFile($this->resultPath($project, $run, $renderId))) {
                $this->writeResult(
                    $project,
                    $run,
                    $renderId,
                    $status,
                    $metadata,
                    $message,
                    is_string($state['finished_at'] ?? null) ? $state['finished_at'] : $this->timestamp(),
                );
            }

            return $state;
        }

        $finishedAt = $this->timestamp();
        if (File::isFile($this->resultPath($project, $run, $renderId))) {
            $existing = $this->read($this->resultPath($project, $run, $renderId), 'Render result');
            if (($existing['render_id'] ?? null) !== $renderId || ($existing['status'] ?? null) !== $status) {
                throw new RuntimeException('The immutable render result conflicts with the requested terminal transition.');
            }
            $finishedAt = is_string($existing['finished_at'] ?? null) ? $existing['finished_at'] : $finishedAt;
            $metadata = $existing;
        } else {
            $this->writeResult($project, $run, $renderId, $status, $metadata, $message, $finishedAt);
        }

        $state = array_merge($state, [
            'status' => $status,
            'cancel_requested' => $status === 'cancelled' ? true : (bool) ($state['cancel_requested'] ?? false),
            'finished_at' => $finishedAt,
            'last_error' => is_string($metadata['error'] ?? null) ? $metadata['error'] : null,
            'updated_at' => $finishedAt,
        ]);
        $this->writeState($project, $run, $renderId, $state);

        return $state;
    }

    /** @return array<string, mixed> */
    private function reconciledStateUnlocked(string $project, string $run, string $renderId): array
    {
        $state = null;
        $stateFailure = null;
        try {
            $state = $this->read($this->statePath($project, $run, $renderId), 'Render state');
        } catch (Throwable $exception) {
            $stateFailure = $exception;
        }

        if (is_array($state) && ($state['status'] ?? null) === 'completed') {
            return $state;
        }

        $resultPath = $this->resultPath($project, $run, $renderId);
        if (File::isFile($resultPath)) {
            $result = $this->read($resultPath, 'Render result');
            if (($result['status'] ?? null) === 'completed') {
                $request = $this->request($project, $run, $renderId);
                $this->completion->validate($project, $run, $renderId, $request, $result);

                $finishedAt = (string) $result['finished_at'];
                $createdAt = is_string($state['created_at'] ?? null)
                    ? $state['created_at']
                    : (string) ($request['created_at'] ?? $finishedAt);
                $startedAt = is_string($state['started_at'] ?? null)
                    ? $state['started_at']
                    : $createdAt;
                $state = array_merge(is_array($state) ? $state : [], [
                    'schema_version' => 1,
                    'id' => $renderId.'--state',
                    'type' => 'render_state',
                    'render_id' => $renderId,
                    'status' => 'completed',
                    'cancel_requested' => false,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'last_error' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $finishedAt,
                    'recovered_from_manifest' => true,
                    'recovered_at' => $this->timestamp(),
                ]);
                $this->writeState($project, $run, $renderId, $state);

                return $state;
            }
        }

        if (! is_array($state)) {
            throw $stateFailure;
        }

        return $state;
    }

    private function hasCommittedResult(string $project, string $run, string $renderId, string $status): bool
    {
        try {
            $result = $this->result($project, $run, $renderId);

            return is_array($result) && ($result['status'] ?? null) === $status;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $metadata */
    private function writeResult(
        string $project,
        string $run,
        string $renderId,
        string $status,
        array $metadata,
        string $message,
        string $finishedAt,
    ): void {
        MarkdownDocument::write(
            $this->resultPath($project, $run, $renderId),
            array_merge($metadata, [
                'schema_version' => 1,
                'id' => $renderId.'--result',
                'type' => 'render_result',
                'render_id' => $renderId,
                'status' => $status,
                'simulation' => true,
                'finished_at' => $finishedAt,
            ]),
            '# Deterministic render '.ucfirst(str_replace('_', ' ', $status))."\n\n{$message}",
            false,
        );
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $project, string $run, string $renderId, array $state, bool $overwrite = true): void
    {
        MarkdownDocument::write(
            $this->statePath($project, $run, $renderId),
            $state,
            "# Deterministic render state\n\n- Status: **".(string) ($state['status'] ?? 'unknown')."**\n\nThis atomic checkpoint does not replace the immutable request and result.",
            $overwrite,
        );
    }

    /** @return array<string, mixed> */
    private function read(string $path, string $label): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("{$label} does not exist.");
        }

        try {
            $metadata = MarkdownDocument::read($path)['metadata'];
        } catch (Throwable $exception) {
            throw new RuntimeException("{$label} is malformed.", previous: $exception);
        }

        return $metadata;
    }

    private function rendersPath(string $project, string $run): string
    {
        return $this->paths->runPath($project, $run).'/renders';
    }

    private function requestPath(string $project, string $run, string $renderId): string
    {
        return $this->path($project, $run, $renderId).'/request.md';
    }

    private function statePath(string $project, string $run, string $renderId): string
    {
        return $this->path($project, $run, $renderId).'/state.md';
    }

    private function resultPath(string $project, string $run, string $renderId): string
    {
        return $this->path($project, $run, $renderId).'/manifest.md';
    }

    private function assertRenderId(string $renderId): void
    {
        if (preg_match('/^render-[a-z0-9-]+--[a-z0-9]+$/', $renderId) !== 1) {
            throw new RuntimeException('The render identifier is invalid.');
        }
    }

    private function lockKey(string $project, string $run, string $renderId): string
    {
        return "render:{$project}:{$run}:{$renderId}";
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
