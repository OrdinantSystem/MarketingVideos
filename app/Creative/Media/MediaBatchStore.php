<?php

namespace App\Creative\Media;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePathResolver;
use App\Creative\CreativePhaseCatalog;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class MediaBatchStore
{
    /** @var list<string> */
    private const STATUSES = ['pending', 'running', 'completed', 'failed', 'cancelled', 'timed_out'];

    /** @var list<string> */
    private const FAILURE_STATUSES = ['failed', 'cancelled', 'timed_out'];

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativePhaseCatalog $phases,
        private readonly CreativeLockManager $locks,
    ) {}

    /**
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @param  array<string, string>  $mediaJobIds
     * @param  list<string>  $requiredSlots
     * @param  array<string, list<string>>  $expectedSourcesBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @return array{request: array<string, mixed>, state: array<string, mixed>}
     */
    public function create(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $generationAttemptId,
        array $documents,
        array $mediaJobIds,
        array $requiredSlots,
        array $expectedSourcesBySlot,
        array $expectedShotsBySlot,
    ): array {
        $this->assertIdentity($phase, $batchId, $generationAttemptId);
        $artifactIds = $this->artifactIds($documents);
        if (array_keys($mediaJobIds) !== $artifactIds || ! $this->identifierMap($mediaJobIds)) {
            throw new RuntimeException('Media batch job IDs must map the exact ordered artifact set.');
        }

        return $this->locks->exclusive(
            $this->lockKey($project, $run, $phase, $batchId),
            function () use ($project, $run, $phase, $batchId, $generationAttemptId, $documents, $mediaJobIds, $requiredSlots, $expectedSourcesBySlot, $expectedShotsBySlot, $artifactIds): array {
                $draftHashes = [];
                foreach ($documents as $document) {
                    $path = $this->draftPath($project, $run, $phase, $batchId, $document['id']);
                    $this->writeOrVerifyDocument($path, $document['metadata'], $document['body']);
                    $hash = hash_file('sha256', $path);
                    if (! is_string($hash)) {
                        throw new RuntimeException('Unable to hash a staged media batch draft.');
                    }
                    $draftHashes[$document['id']] = $hash;
                }

                $requestPath = $this->requestPath($project, $run, $phase, $batchId);
                if (File::isFile($requestPath)) {
                    $request = $this->readMetadata($requestPath, 'Media batch request');
                    $expected = [
                        'generation_attempt_id' => $generationAttemptId,
                        'phase' => $phase,
                        'artifact_ids' => $artifactIds,
                        'media_job_ids' => $mediaJobIds,
                        'draft_sha256' => $draftHashes,
                        'required_slots' => $requiredSlots,
                        'expected_sources_by_slot' => $expectedSourcesBySlot,
                        'expected_shots_by_slot' => $expectedShotsBySlot,
                    ];
                    foreach ($expected as $field => $value) {
                        if (($request[$field] ?? null) !== $value) {
                            throw new RuntimeException("Existing media batch request disagrees on {$field}.");
                        }
                    }
                } else {
                    $createdAt = $this->timestamp();
                    $request = [
                        'schema_version' => 1,
                        'id' => $batchId,
                        'type' => 'simulated_media_batch_request',
                        'batch_id' => $batchId,
                        'generation_attempt_id' => $generationAttemptId,
                        'project_id' => $project,
                        'run_id' => $run,
                        'phase' => $phase,
                        'driver' => 'simulation',
                        'artifact_ids' => $artifactIds,
                        'media_job_ids' => $mediaJobIds,
                        'draft_sha256' => $draftHashes,
                        'required_slots' => $requiredSlots,
                        'expected_sources_by_slot' => $expectedSourcesBySlot,
                        'expected_shots_by_slot' => $expectedShotsBySlot,
                        'expected_output_count' => count($artifactIds),
                        'created_at' => $createdAt,
                    ];
                    MarkdownDocument::write(
                        $requestPath,
                        $request,
                        "# Queued simulated media batch\n\nEvery intended sidecar and child job is reserved here before dispatch. Drafts remain staged until the complete batch succeeds.",
                        false,
                    );
                }

                $statePath = $this->statePath($project, $run, $phase, $batchId);
                if (! File::isFile($statePath)) {
                    $createdAt = is_string($request['created_at'] ?? null) ? $request['created_at'] : $this->timestamp();
                    $this->writeState($statePath, [
                        'schema_version' => 1,
                        'id' => $batchId.'--state',
                        'type' => 'simulated_media_batch_state',
                        'batch_id' => $batchId,
                        'status' => 'pending',
                        'media_job_statuses' => array_fill_keys(array_values($mediaJobIds), 'pending'),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                        'finished_at' => null,
                        'last_error' => null,
                    ], false);
                }
                if ($this->events($project, $run, $phase, $batchId) === []) {
                    $this->appendEventUnlocked(
                        $project,
                        $run,
                        $phase,
                        $batchId,
                        'pending',
                        'pending',
                        array_fill_keys(array_values($mediaJobIds), 'pending'),
                        'The complete immutable batch intent exists and every child may now be reserved before dispatch.',
                    );
                }

                return [
                    'request' => $request,
                    'state' => $this->state($project, $run, $phase, $batchId),
                ];
            },
        );
    }

    public function exists(string $project, string $run, string $phase, string $batchId): bool
    {
        return File::isFile($this->requestPath($project, $run, $phase, $batchId));
    }

    /** @return array<string, mixed> */
    public function request(string $project, string $run, string $phase, string $batchId): array
    {
        return $this->readMetadata(
            $this->requestPath($project, $run, $phase, $batchId),
            'Media batch request',
        );
    }

    /** @return array<string, mixed> */
    public function state(string $project, string $run, string $phase, string $batchId): array
    {
        return $this->readMetadata(
            $this->statePath($project, $run, $phase, $batchId),
            'Media batch state',
        );
    }

    /** @return array<string, mixed>|null */
    public function result(string $project, string $run, string $phase, string $batchId): ?array
    {
        $path = $this->resultPath($project, $run, $phase, $batchId);

        return File::isFile($path) ? $this->readMetadata($path, 'Media batch result') : null;
    }

    /**
     * @return array{
     *     batch_id: string,
     *     request: array<string, mixed>|null,
     *     state: array<string, mixed>|null,
     *     result: array<string, mixed>|null,
     *     drafts: list<array{id: string, metadata: array<string, mixed>, body: string}>,
     *     events: list<array<string, mixed>>,
     *     path: string,
     *     parse_errors: list<string>
     * }
     */
    public function batch(string $project, string $run, string $phase, string $batchId): array
    {
        $errors = [];
        $request = null;
        $state = null;
        $result = null;
        $drafts = [];

        try {
            $request = $this->request($project, $run, $phase, $batchId);
        } catch (Throwable $exception) {
            $errors[] = 'Request: '.$exception->getMessage();
        }
        try {
            $state = $this->state($project, $run, $phase, $batchId);
        } catch (Throwable $exception) {
            $errors[] = 'State: '.$exception->getMessage();
        }
        try {
            $result = $this->result($project, $run, $phase, $batchId);
        } catch (Throwable $exception) {
            $errors[] = 'Result: '.$exception->getMessage();
        }

        $draftRoot = $this->batchPath($project, $run, $phase, $batchId).'/drafts';
        if (File::isDirectory($draftRoot)) {
            foreach (File::files($draftRoot) as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }
                try {
                    $document = MarkdownDocument::read($file->getPathname());
                    $drafts[] = [
                        'id' => $file->getBasename('.md'),
                        'metadata' => $document['metadata'],
                        'body' => $document['body'],
                    ];
                } catch (Throwable $exception) {
                    $errors[] = 'Draft '.$file->getFilename().': '.$exception->getMessage();
                }
            }
        }

        $order = is_array($request['artifact_ids'] ?? null) ? $request['artifact_ids'] : [];
        usort($drafts, static function (array $left, array $right) use ($order): int {
            return array_search($left['id'], $order, true) <=> array_search($right['id'], $order, true);
        });

        return [
            'batch_id' => $batchId,
            'request' => $request,
            'state' => $state,
            'result' => $result,
            'drafts' => $drafts,
            'events' => $this->events($project, $run, $phase, $batchId),
            'path' => $this->batchPath($project, $run, $phase, $batchId),
            'parse_errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $mediaJobStatuses
     * @param  list<array<string, mixed>>  $retainedAssets
     * @return array<string, mixed>
     */
    public function recordAggregate(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $status,
        array $mediaJobStatuses,
        ?string $error = null,
        array $retainedAssets = [],
    ): array {
        if (! in_array($status, self::STATUSES, true) || $status === 'completed') {
            throw new RuntimeException('Completed media batches must use the atomic completion path.');
        }

        return $this->locks->exclusive(
            $this->lockKey($project, $run, $phase, $batchId),
            function () use ($project, $run, $phase, $batchId, $status, $mediaJobStatuses, $error, $retainedAssets): array {
                $terminal = $this->terminalResultState($project, $run, $phase, $batchId);
                if ($terminal !== null) {
                    return $terminal;
                }

                $request = $this->request($project, $run, $phase, $batchId);
                $this->assertMediaJobStatuses($request, $mediaJobStatuses);
                $state = $this->state($project, $run, $phase, $batchId);
                $current = is_string($state['status'] ?? null) ? $state['status'] : 'pending';
                if (in_array($current, self::FAILURE_STATUSES, true) && $current !== $status) {
                    throw new RuntimeException('A terminal media batch cannot change its failure status.');
                }

                if ($current === 'running' && $status === 'pending') {
                    $status = 'running';
                }
                $now = $this->timestamp();
                $changed = $current !== $status
                    || ($state['media_job_statuses'] ?? null) !== $mediaJobStatuses
                    || ($state['last_error'] ?? null) !== $error;
                $state = array_merge($state, [
                    'status' => $status,
                    'media_job_statuses' => $mediaJobStatuses,
                    'updated_at' => $now,
                    'finished_at' => in_array($status, self::FAILURE_STATUSES, true) ? $now : null,
                    'last_error' => $error,
                ]);

                if (in_array($status, self::FAILURE_STATUSES, true)) {
                    MarkdownDocument::write(
                        $this->resultPath($project, $run, $phase, $batchId),
                        [
                            'schema_version' => 1,
                            'id' => $batchId.'--result',
                            'type' => 'simulated_media_batch_result',
                            'batch_id' => $batchId,
                            'generation_attempt_id' => $request['generation_attempt_id'] ?? null,
                            'phase' => $phase,
                            'status' => $status,
                            'artifact_ids' => [],
                            'output_count' => 0,
                            'expected_output_count' => $request['expected_output_count'],
                            'media_job_statuses' => $mediaJobStatuses,
                            'retained_failed_attempt_assets' => $retainedAssets,
                            'error' => $error,
                            'finished_at' => $now,
                        ],
                        '# Queued media batch '.str_replace('_', ' ', $status)."\n\nNo artifact sidecar was committed because the complete child-job set did not succeed.",
                        false,
                    );
                    $this->writeState($this->statePath($project, $run, $phase, $batchId), $state);
                    if ($changed) {
                        $this->appendEventUnlocked(
                            $project,
                            $run,
                            $phase,
                            $batchId,
                            $status,
                            $status,
                            $mediaJobStatuses,
                            $error ?? 'The aggregate child-job outcome terminalized the batch without sidecars.',
                        );
                    }

                    return $state;
                }

                $this->writeState($this->statePath($project, $run, $phase, $batchId), $state);
                if ($changed) {
                    $this->appendEventUnlocked(
                        $project,
                        $run,
                        $phase,
                        $batchId,
                        'aggregate_updated',
                        $status,
                        $mediaJobStatuses,
                        'The aggregate batch checkpoint was reconstructed from current child-job records.',
                    );
                }

                return $state;
            },
        );
    }

    /**
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @param  array<string, string>  $mediaJobStatuses
     * @return array<string, mixed>
     */
    public function complete(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        array $documents,
        array $mediaJobStatuses,
    ): array {
        return $this->locks->exclusive(
            $this->lockKey($project, $run, $phase, $batchId),
            function () use ($project, $run, $phase, $batchId, $documents, $mediaJobStatuses): array {
                $existingResult = $this->result($project, $run, $phase, $batchId);
                if ($existingResult !== null) {
                    if (($existingResult['status'] ?? null) !== 'completed') {
                        throw new RuntimeException('An unsuccessful terminal media batch cannot later commit artifacts.');
                    }

                    return $this->restoreCompletedResult(
                        $project,
                        $run,
                        $phase,
                        $batchId,
                        $documents,
                        $existingResult,
                    );
                }

                $request = $this->request($project, $run, $phase, $batchId);
                $this->assertDraftHashes($project, $run, $phase, $batchId, $request);
                $this->assertMediaJobStatuses($request, $mediaJobStatuses);
                $artifactIds = $this->artifactIds($documents);
                if (($request['artifact_ids'] ?? null) !== $artifactIds
                    || count($artifactIds) !== ($request['expected_output_count'] ?? null)) {
                    throw new RuntimeException('Completed media documents do not match the immutable batch request.');
                }
                if (collect($mediaJobStatuses)->contains(fn (string $status): bool => $status !== 'completed')) {
                    throw new RuntimeException('Every child media job must be completed before sidecars are committed.');
                }

                $readyHashes = [];
                foreach ($documents as $document) {
                    $readyPath = $this->readyPath($project, $run, $phase, $batchId, $document['id']);
                    $this->writeOrVerifyDocument($readyPath, $document['metadata'], $document['body']);
                    $hash = hash_file('sha256', $readyPath);
                    if (! is_string($hash)) {
                        throw new RuntimeException('Unable to hash a ready media sidecar.');
                    }
                    $readyHashes[$document['id']] = $hash;
                }

                $promoted = [];
                $resultCommitted = false;
                try {
                    foreach ($artifactIds as $artifactId) {
                        $source = $this->readyPath($project, $run, $phase, $batchId, $artifactId);
                        $target = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
                        $sourceHash = File::isFile($source) ? hash_file('sha256', $source) : false;
                        if (! is_string($sourceHash) || ! hash_equals($readyHashes[$artifactId], $sourceHash)) {
                            throw new RuntimeException("Ready media sidecar [{$artifactId}] is missing or corrupt before promotion.");
                        }
                        if (File::isFile($target)) {
                            $actual = hash_file('sha256', $target);
                            if (! is_string($actual) || ! hash_equals($readyHashes[$artifactId], $actual)) {
                                throw new RuntimeException("Output [{$artifactId}] conflicts with the immutable ready sidecar.");
                            }
                        } elseif (File::exists($target) || is_link($target)) {
                            throw new RuntimeException("Output [{$artifactId}] is not a safe regular-file promotion target.");
                        }
                    }

                    foreach ($artifactIds as $artifactId) {
                        $source = $this->readyPath($project, $run, $phase, $batchId, $artifactId);
                        $target = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
                        if (File::isFile($target)) {
                            continue;
                        }
                        if (! link($source, $target)) {
                            throw new RuntimeException("Unable to promote media sidecar [{$artifactId}].");
                        }
                        $promoted[] = $target;
                    }

                    $now = $this->timestamp();
                    MarkdownDocument::write(
                        $this->resultPath($project, $run, $phase, $batchId),
                        [
                            'schema_version' => 1,
                            'id' => $batchId.'--result',
                            'type' => 'simulated_media_batch_result',
                            'batch_id' => $batchId,
                            'generation_attempt_id' => $request['generation_attempt_id'] ?? null,
                            'phase' => $phase,
                            'status' => 'completed',
                            'artifact_ids' => $artifactIds,
                            'artifact_sha256' => $readyHashes,
                            'output_count' => count($artifactIds),
                            'expected_output_count' => $request['expected_output_count'],
                            'media_job_statuses' => $mediaJobStatuses,
                            'finished_at' => $now,
                        ],
                        "# Queued media batch completed\n\nEvery child job completed before the exact immutable sidecar batch was promoted.",
                        false,
                    );
                    $resultCommitted = true;
                    $state = array_merge($this->state($project, $run, $phase, $batchId), [
                        'status' => 'completed',
                        'media_job_statuses' => $mediaJobStatuses,
                        'updated_at' => $now,
                        'finished_at' => $now,
                        'last_error' => null,
                    ]);
                    $this->writeState($this->statePath($project, $run, $phase, $batchId), $state);
                    $this->appendEventUnlocked(
                        $project,
                        $run,
                        $phase,
                        $batchId,
                        'completed',
                        'completed',
                        $mediaJobStatuses,
                        'Every expected child completed and the exact ready sidecars were promoted.',
                    );

                    return $state;
                } catch (Throwable $exception) {
                    if (! $resultCommitted) {
                        foreach (array_reverse($promoted) as $path) {
                            $artifactId = pathinfo($path, PATHINFO_FILENAME);
                            $source = $this->readyPath($project, $run, $phase, $batchId, $artifactId);
                            $sourceInode = is_file($source) ? fileinode($source) : false;
                            $targetInode = is_file($path) ? fileinode($path) : false;
                            if (is_int($sourceInode) && $sourceInode === $targetInode) {
                                File::delete($path);
                            }
                        }
                    }

                    throw $exception;
                }
            },
        );
    }

    public function batchPath(string $project, string $run, string $phase, string $batchId): string
    {
        $this->assertIdentity($phase, $batchId, $batchId);

        return dirname($this->paths->outputsPath($project, $run, $phase)).'/media-batches/'.$batchId;
    }

    /** @return list<array<string, mixed>> */
    public function events(string $project, string $run, string $phase, string $batchId): array
    {
        $directory = $this->batchPath($project, $run, $phase, $batchId).'/events';
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename())
            ->map(function (\SplFileInfo $file): array {
                try {
                    return MarkdownDocument::read($file->getPathname())['metadata'];
                } catch (Throwable $exception) {
                    return [
                        'parse_error' => $exception->getMessage(),
                        '_path' => $file->getPathname(),
                    ];
                }
            })->all());
    }

    private function requestPath(string $project, string $run, string $phase, string $batchId): string
    {
        return $this->batchPath($project, $run, $phase, $batchId).'/request.md';
    }

    private function statePath(string $project, string $run, string $phase, string $batchId): string
    {
        return $this->batchPath($project, $run, $phase, $batchId).'/state.md';
    }

    private function resultPath(string $project, string $run, string $phase, string $batchId): string
    {
        return $this->batchPath($project, $run, $phase, $batchId).'/result.md';
    }

    private function draftPath(string $project, string $run, string $phase, string $batchId, string $artifactId): string
    {
        $this->assertIdentifier($artifactId, 'artifact');

        return $this->batchPath($project, $run, $phase, $batchId).'/drafts/'.$artifactId.'.md';
    }

    private function readyPath(string $project, string $run, string $phase, string $batchId, string $artifactId): string
    {
        $this->assertIdentifier($artifactId, 'artifact');

        return $this->batchPath($project, $run, $phase, $batchId).'/ready/'.$artifactId.'.md';
    }

    private function lockKey(string $project, string $run, string $phase, string $batchId): string
    {
        return "media-batch:{$project}:{$run}:{$phase}:{$batchId}";
    }

    /**
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @return list<string>
     */
    private function artifactIds(array $documents): array
    {
        if ($documents === []) {
            throw new RuntimeException('A media batch must contain at least one document.');
        }

        $ids = [];
        foreach ($documents as $document) {
            $id = $document['id'];
            if (! $this->safeIdentifier($id) || ($document['metadata']['id'] ?? null) !== $id) {
                throw new RuntimeException('A media batch document has an invalid identity.');
            }
            $ids[] = $id;
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('A media batch cannot contain duplicate artifact IDs.');
        }

        return $ids;
    }

    /** @param array<string, string> $values */
    private function identifierMap(array $values): bool
    {
        return $values !== []
            && collect($values)->every(fn (string $value): bool => $this->safeIdentifier($value))
            && count($values) === count(array_unique($values));
    }

    private function assertIdentity(string $phase, string $batchId, string $generationAttemptId): void
    {
        $this->phases->assertValid($phase);
        if (! in_array($phase, ['images', 'scenes'], true)) {
            throw new RuntimeException('Queued media batches are supported only for Images and Scenes.');
        }
        $this->assertIdentifier($batchId, 'batch');
        $this->assertIdentifier($generationAttemptId, 'generation attempt');
    }

    private function assertIdentifier(string $identifier, string $label): void
    {
        if (! $this->safeIdentifier($identifier)) {
            throw new RuntimeException("The media {$label} identifier is unsafe.");
        }
    }

    private function safeIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $identifier) === 1
            && ! in_array($identifier, ['.', '..'], true);
    }

    /** @param array<string, mixed> $metadata */
    private function writeOrVerifyDocument(string $path, array $metadata, string $body): void
    {
        if (! File::isFile($path)) {
            MarkdownDocument::write($path, $metadata, $body, false);

            return;
        }

        $existing = MarkdownDocument::read($path);
        if ($existing['metadata'] !== $metadata || trim($existing['body']) !== trim($body)) {
            throw new RuntimeException('An immutable staged media document already exists with different content.');
        }
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path, string $label): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("{$label} is missing.");
        }

        try {
            return MarkdownDocument::read($path)['metadata'];
        } catch (Throwable $exception) {
            throw new RuntimeException("{$label} is malformed.", previous: $exception);
        }
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $path, array $state, bool $overwrite = true): void
    {
        MarkdownDocument::write(
            $path,
            $state,
            "# Queued simulated media batch state\n\nStatus: **".(string) ($state['status'] ?? 'unknown').'**. This mutable checkpoint is verified against immutable child jobs and the terminal batch result.',
            $overwrite,
        );
    }

    /** @return array<string, mixed>|null */
    private function terminalResultState(string $project, string $run, string $phase, string $batchId): ?array
    {
        $result = $this->result($project, $run, $phase, $batchId);
        if ($result === null) {
            return null;
        }

        $status = $result['status'] ?? null;
        if (! is_string($status) || ! in_array($status, ['completed', ...self::FAILURE_STATUSES], true)) {
            throw new RuntimeException('Media batch terminal result has an invalid status.');
        }

        $state = $this->state($project, $run, $phase, $batchId);
        if (($state['status'] ?? null) !== $status) {
            $finishedAt = is_string($result['finished_at'] ?? null) ? $result['finished_at'] : $this->timestamp();
            $state = array_merge($state, [
                'status' => $status,
                'media_job_statuses' => $result['media_job_statuses'] ?? [],
                'updated_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'last_error' => $result['error'] ?? null,
            ]);
            $this->writeState($this->statePath($project, $run, $phase, $batchId), $state);
        }
        $this->ensureTerminalEvent($project, $run, $phase, $batchId, $result);

        return $state;
    }

    /**
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function restoreCompletedResult(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        array $documents,
        array $result,
    ): array {
        $artifactIds = $this->artifactIds($documents);
        $hashes = $result['artifact_sha256'] ?? null;
        if (($result['artifact_ids'] ?? null) !== $artifactIds
            || ! is_array($hashes)
            || array_keys($hashes) !== $artifactIds) {
            throw new RuntimeException('Completed media batch result does not cover the exact prepared sidecars.');
        }

        foreach ($artifactIds as $artifactId) {
            $expectedHash = $hashes[$artifactId] ?? null;
            $ready = $this->readyPath($project, $run, $phase, $batchId, $artifactId);
            $target = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
            $readyHash = File::isFile($ready) ? hash_file('sha256', $ready) : false;
            if (! is_string($expectedHash)
                || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
                || ! is_string($readyHash)
                || ! hash_equals($expectedHash, $readyHash)) {
                throw new RuntimeException("Completed media batch ready sidecar [{$artifactId}] is missing or corrupt.");
            }
            if (! File::isFile($target)) {
                if (! link($ready, $target)) {
                    throw new RuntimeException("Unable to restore committed media sidecar [{$artifactId}].");
                }
                $restored[] = $artifactId;
            }
            $targetHash = hash_file('sha256', $target);
            if (! is_string($targetHash) || ! hash_equals($expectedHash, $targetHash)) {
                throw new RuntimeException("Committed media sidecar [{$artifactId}] conflicts with its terminal result.");
            }
        }

        $state = $this->state($project, $run, $phase, $batchId);
        if (($state['status'] ?? null) !== 'completed') {
            $finishedAt = is_string($result['finished_at'] ?? null) ? $result['finished_at'] : $this->timestamp();
            $state = array_merge($state, [
                'status' => 'completed',
                'media_job_statuses' => $result['media_job_statuses'] ?? [],
                'updated_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'last_error' => null,
            ]);
            $this->writeState($this->statePath($project, $run, $phase, $batchId), $state);
        }
        if (($restored ?? []) !== []) {
            $this->appendEventUnlocked(
                $project,
                $run,
                $phase,
                $batchId,
                'outputs_recovered',
                'completed',
                is_array($result['media_job_statuses'] ?? null) ? $result['media_job_statuses'] : [],
                'Missing committed sidecars were restored from exact hash-matching ready files: '.implode(', ', $restored).'.',
            );
        }
        $this->ensureTerminalEvent($project, $run, $phase, $batchId, $result);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, string>  $mediaJobStatuses
     */
    private function assertMediaJobStatuses(array $request, array $mediaJobStatuses): void
    {
        $mediaJobIds = is_array($request['media_job_ids'] ?? null)
            ? array_values($request['media_job_ids'])
            : [];
        if ($mediaJobIds === []
            || count($mediaJobIds) !== count(array_unique($mediaJobIds))
            || array_keys($mediaJobStatuses) !== $mediaJobIds) {
            throw new RuntimeException('Media batch statuses must cover the exact immutable child-job set in order.');
        }
        foreach ($mediaJobStatuses as $status) {
            if (! in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Media batch contains an unsupported child-job status.');
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function assertDraftHashes(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        array $request,
    ): void {
        $artifactIds = is_array($request['artifact_ids'] ?? null)
            ? $request['artifact_ids']
            : [];
        $draftHashes = is_array($request['draft_sha256'] ?? null)
            ? $request['draft_sha256']
            : [];
        if ($artifactIds === []
            || ! array_is_list($artifactIds)
            || ! collect($artifactIds)->every(fn (mixed $id): bool => is_string($id) && $this->safeIdentifier($id))
            || array_keys($draftHashes) !== $artifactIds) {
            throw new RuntimeException('Media batch draft hashes must cover the exact immutable artifact set in order.');
        }
        /** @var list<string> $artifactIds */
        $draftRoot = $this->batchPath($project, $run, $phase, $batchId).'/drafts';
        $actualNames = array_values(collect(File::isDirectory($draftRoot) ? File::files($draftRoot) : [])
            ->map(fn (\SplFileInfo $file): string => $file->getFilename())
            ->sort()
            ->all());
        $expectedNames = array_map(fn (string $id): string => $id.'.md', $artifactIds);
        sort($expectedNames);
        if ($actualNames !== $expectedNames) {
            throw new RuntimeException('Staged media drafts do not match the immutable batch membership.');
        }

        foreach ($artifactIds as $artifactId) {
            $expectedHash = $draftHashes[$artifactId] ?? null;
            $path = $this->draftPath($project, $run, $phase, $batchId, $artifactId);
            $actualHash = File::isFile($path) ? hash_file('sha256', $path) : false;
            if (is_link($path)
                || ! is_string($expectedHash)
                || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
                || ! is_string($actualHash)
                || ! hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException("Staged media draft [{$artifactId}] does not match its immutable SHA-256.");
            }
        }
    }

    /**
     * @param  array<string, string>  $mediaJobStatuses
     */
    private function appendEventUnlocked(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $event,
        string $status,
        array $mediaJobStatuses,
        string $message,
    ): void {
        $sequence = count($this->events($project, $run, $phase, $batchId)) + 1;
        $eventId = $batchId.'--event-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        MarkdownDocument::write(
            $this->batchPath($project, $run, $phase, $batchId).'/events/'.$eventId.'.md',
            [
                'schema_version' => 1,
                'id' => $eventId,
                'type' => 'simulated_media_batch_event',
                'batch_id' => $batchId,
                'sequence' => $sequence,
                'event' => $event,
                'status' => $status,
                'media_job_statuses' => $mediaJobStatuses,
                'created_at' => $this->timestamp(),
            ],
            "# Queued media batch event\n\n{$message}",
            false,
        );
    }

    /** @param array<string, mixed> $result */
    private function ensureTerminalEvent(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        array $result,
    ): void {
        $status = is_string($result['status'] ?? null) ? $result['status'] : 'failed';
        if (collect($this->events($project, $run, $phase, $batchId))->contains(
            fn (array $event): bool => ($event['status'] ?? null) === $status
                && in_array($event['event'] ?? null, [$status, 'terminal_recovered'], true),
        )) {
            return;
        }

        $this->appendEventUnlocked(
            $project,
            $run,
            $phase,
            $batchId,
            'terminal_recovered',
            $status,
            is_array($result['media_job_statuses'] ?? null) ? $result['media_job_statuses'] : [],
            'The append-only terminal event was recovered from the immutable batch result commit fence.',
        );
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
