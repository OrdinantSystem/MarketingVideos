<?php

namespace App\Creative\Media;

use App\Creative\CreativeArtifactValidator;
use App\Creative\MarkdownDocument;
use App\Jobs\ProcessQueuedMediaBatchJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class QueuedMediaBatchCoordinator
{
    public function __construct(
        private readonly MediaBatchStore $batches,
        private readonly SimulatedMediaJobService $media,
        private readonly MediaJobStore $jobs,
        private readonly CreativeArtifactValidator $validator,
    ) {}

    /**
     * Persist the complete batch and reserve every child before dispatching any child.
     * Repeating this call reuses the exact immutable request and safely redispatches
     * only pending work; duplicate queue deliveries cannot claim the same job twice.
     *
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @param  list<string>  $requiredSlots
     * @param  array<string, list<string>>  $expectedSourcesBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @return array{batch_id: string, request: array<string, mixed>, state: array<string, mixed>}
     */
    public function enqueue(
        string $project,
        string $run,
        string $phase,
        string $generationAttemptId,
        array $documents,
        array $requiredSlots,
        array $expectedSourcesBySlot,
        array $expectedShotsBySlot,
    ): array {
        $batchId = $generationAttemptId;
        $mediaJobIds = [];
        foreach ($documents as $document) {
            $artifactId = $document['id'];
            $mediaJobIds[$artifactId] = $this->jobId($batchId, $artifactId);
        }

        $batch = $this->batches->create(
            $project,
            $run,
            $phase,
            $batchId,
            $generationAttemptId,
            $documents,
            $mediaJobIds,
            $requiredSlots,
            $expectedSourcesBySlot,
            $expectedShotsBySlot,
        );

        foreach ($documents as $index => $document) {
            $jobId = $mediaJobIds[$document['id']];
            $metadata = $document['metadata'];
            $mediaType = $this->mediaType($metadata['artifact_type'] ?? null);
            $shot = is_array($metadata['shot'] ?? null) ? $metadata['shot'] : [];
            $duration = is_numeric($shot['end_seconds'] ?? null) && is_numeric($shot['start_seconds'] ?? null)
                ? (float) $shot['end_seconds'] - (float) $shot['start_seconds']
                : 1.0;
            $parameters = [
                'label' => (string) ($metadata['title'] ?? $document['id']),
                'color' => (string) ($metadata['preview_color'] ?? '#557c70'),
                'duration_seconds' => max(0.25, $duration),
            ];
            if (is_string($metadata['fixture_outcome'] ?? null)) {
                $parameters['fixture_outcome'] = $metadata['fixture_outcome'];
            }
            $expectedRequest = [
                'artifact_id' => $document['id'],
                'media_type' => $mediaType,
                'generation_attempt_id' => $generationAttemptId,
                'batch_id' => $batchId,
                'batch_index' => $index + 1,
                'batch_size' => count($documents),
            ];
            $assetPath = 'media-batches/'.$batchId.'/assets/'.$document['id'].'.'.($mediaType === 'image' ? 'png' : 'mp4');
            $expectedRequest['asset_path'] = $assetPath;

            $request = null;
            if (! $this->jobs->exists($project, $run, $phase, $jobId)) {
                try {
                    $this->media->reserve(
                        $project,
                        $run,
                        $phase,
                        $document['id'],
                        $mediaType,
                        $parameters,
                        [
                            'generation_attempt_id' => $generationAttemptId,
                            'batch_id' => $batchId,
                            'batch_index' => $index + 1,
                            'batch_size' => count($documents),
                            'asset_path' => $assetPath,
                        ],
                        $jobId,
                    );
                } catch (Throwable $exception) {
                    try {
                        $request = $this->jobs->request($project, $run, $phase, $jobId);
                    } catch (Throwable) {
                        throw $exception;
                    }
                }
            }

            $request ??= $this->jobs->request($project, $run, $phase, $jobId);
            foreach ($expectedRequest as $field => $value) {
                if (($request[$field] ?? null) !== $value) {
                    throw new RuntimeException("Reserved media job [{$jobId}] disagrees on {$field}.");
                }
            }
        }

        // This loop begins only after the reservation loop above completed, so a worker
        // can never observe a partially known batch membership list.
        foreach (array_values($mediaJobIds) as $jobId) {
            $state = $this->jobs->state($project, $run, $phase, $jobId);
            if (($state['status'] ?? null) !== MediaJobStatus::Pending->value) {
                continue;
            }
            ProcessQueuedMediaBatchJob::dispatch($project, $run, $phase, $batchId, $jobId)
                ->onQueue((string) config('creative.media.queue', 'creative-media'));
        }

        return [
            'batch_id' => $batchId,
            'request' => $batch['request'],
            'state' => $this->reconcile($project, $run, $phase, $batchId),
        ];
    }

    /** @return array<string, mixed> */
    public function reconcile(string $project, string $run, string $phase, string $batchId): array
    {
        $batch = $this->batches->batch($project, $run, $phase, $batchId);
        if ($batch['parse_errors'] !== [] || ! is_array($batch['request'])) {
            throw new RuntimeException('Queued media batch cannot be reconciled: '.implode(' ', $batch['parse_errors']));
        }

        $request = $batch['request'];
        $mediaJobIds = is_array($request['media_job_ids'] ?? null) ? $request['media_job_ids'] : [];
        $artifactIds = is_array($request['artifact_ids'] ?? null) ? $request['artifact_ids'] : [];
        if ($artifactIds === [] || array_keys($mediaJobIds) !== $artifactIds) {
            throw new RuntimeException('Queued media batch has an invalid immutable membership map.');
        }

        $statuses = [];
        $jobRecords = [];
        foreach ($mediaJobIds as $artifactId => $jobId) {
            if (! is_string($artifactId) || ! is_string($jobId)
                || ! $this->jobs->exists($project, $run, $phase, $jobId)) {
                $statuses[(string) $jobId] = MediaJobStatus::Pending->value;

                continue;
            }

            $job = $this->jobRecord(
                $project,
                $run,
                $phase,
                $batchId,
                $artifactId,
                $jobId,
                $request['generation_attempt_id'] ?? null,
            );
            $status = MediaJobStatus::tryFrom((string) ($job['state']['status'] ?? ''));
            if ($status === null) {
                throw new RuntimeException("Queued media job [{$jobId}] has an invalid state.");
            }
            $statuses[$jobId] = $status->value;
            $jobRecords[$artifactId] = $job;
        }

        // Missing reservations remain pending and can be repaired by replaying enqueue().
        $orderedStatuses = [];
        foreach (array_values($mediaJobIds) as $jobId) {
            $orderedStatuses[$jobId] = $statuses[$jobId] ?? MediaJobStatus::Pending->value;
        }
        $aggregate = $this->aggregateStatus(array_values($orderedStatuses));
        if (in_array($aggregate, ['failed', 'cancelled', 'timed_out'], true)) {
            foreach ($orderedStatuses as $jobId => $status) {
                if (in_array($status, ['pending', 'running'], true)
                    && $this->jobs->exists($project, $run, $phase, $jobId)) {
                    $cancelled = $this->media->cancel($project, $run, $phase, $jobId);
                    if (($cancelled['status'] ?? null) === MediaJobStatus::Running->value) {
                        $cancelled = $this->jobs->finish(
                            $project,
                            $run,
                            $phase,
                            $jobId,
                            MediaJobStatus::Cancelled,
                            [
                                'batch_id' => $batchId,
                                'error' => 'A sibling fixed the parent batch as unsuccessful before this child could publish.',
                            ],
                            'The parent batch failure cancelled this running child at its final publication lock.',
                        );
                    }
                    $orderedStatuses[$jobId] = is_string($cancelled['status'] ?? null)
                        ? $cancelled['status']
                        : $status;
                }
            }
            // Refresh complete records, not only state strings. A running sibling can
            // finish between the first scan and cancellation; its terminal binary must
            // then appear in the immutable retained-asset evidence.
            [$orderedStatuses, $jobRecords] = $this->refreshJobRecords(
                $project,
                $run,
                $phase,
                $batchId,
                $request,
                $mediaJobIds,
                $orderedStatuses,
                $jobRecords,
            );
            if (in_array('running', $orderedStatuses, true) || in_array('pending', $orderedStatuses, true)) {
                return $this->batches->recordAggregate(
                    $project,
                    $run,
                    $phase,
                    $batchId,
                    in_array('running', $orderedStatuses, true) ? 'running' : 'pending',
                    $orderedStatuses,
                    'A failed child fixed the batch outcome; cancellation is still being honored by another worker.',
                );
            }
            $aggregate = $this->aggregateStatus(array_values($orderedStatuses));

            return $this->batches->recordAggregate(
                $project,
                $run,
                $phase,
                $batchId,
                $aggregate,
                $orderedStatuses,
                "At least one child media job ended as {$aggregate}; no sidecar batch was committed.",
                $this->retainedAssets($jobRecords, $artifactIds),
            );
        }
        if ($aggregate !== 'completed') {
            return $this->batches->recordAggregate(
                $project,
                $run,
                $phase,
                $batchId,
                $aggregate,
                $orderedStatuses,
            );
        }

        try {
            $drafts = collect($batch['drafts'])->keyBy('id');
            $readyDocuments = [];
            foreach ($artifactIds as $artifactId) {
                $draft = $drafts->get($artifactId);
                $job = $jobRecords[$artifactId] ?? null;
                $result = is_array($job) ? ($job['result'] ?? null) : null;
                if (! is_array($draft) || ! is_array($result) || ($result['status'] ?? null) !== 'completed') {
                    throw new RuntimeException("Completed queued media job for [{$artifactId}] has no valid result.");
                }

                $this->assertBinaryResult($project, $run, $phase, $artifactId, $result);
                $draft['metadata'] = array_merge($draft['metadata'], [
                    'status' => 'ready',
                    'asset_status' => 'ready',
                    'asset_path' => $result['asset_path'],
                    'asset_sha256' => $result['asset_sha256'],
                    'asset_bytes' => $result['asset_bytes'],
                    'media_probe' => $result['media_probe'],
                    'media_job_id' => $mediaJobIds[$artifactId],
                ]);
                $draft['body'] = str_replace(
                    [
                        'Simulation sidecar only. No PNG was created.',
                        'Simulation sidecar only. No MP4 was created.',
                    ],
                    'A deterministic local simulation binary was created, probed, and hashed. No provider was contacted.',
                    $draft['body'],
                );

                $slot = is_string($draft['metadata']['slot'] ?? null) ? $draft['metadata']['slot'] : '';
                $requiredSlots = $this->stringList($request['required_slots'] ?? null);
                if ($requiredSlots === null) {
                    throw new RuntimeException('Queued media batch required_slots is invalid.');
                }
                $expectedSources = $this->stringList($request['expected_sources_by_slot'][$slot] ?? null);
                $expectedShot = is_array($request['expected_shots_by_slot'][$slot] ?? null)
                    ? $request['expected_shots_by_slot'][$slot]
                    : null;
                $validation = $this->validator->validate(
                    [
                        'id' => $artifactId,
                        'metadata' => $draft['metadata'],
                        'body' => $draft['body'],
                        'sections' => MarkdownDocument::sections($draft['body']),
                        'filename' => $artifactId.'.md',
                        'slot' => $slot,
                        'asset_status' => $draft['metadata']['asset_status'],
                    ],
                    $phase,
                    $expectedSources,
                    $requiredSlots,
                    $expectedShot,
                );
                if (! $validation['valid']) {
                    throw new RuntimeException("Ready queued media artifact [{$artifactId}] is invalid: ".implode(' ', $validation['errors']));
                }

                $readyDocuments[] = $draft;
            }

            return $this->batches->complete(
                $project,
                $run,
                $phase,
                $batchId,
                $readyDocuments,
                $orderedStatuses,
            );
        } catch (Throwable $exception) {
            $terminalResult = $this->batches->result($project, $run, $phase, $batchId);
            if (($terminalResult['status'] ?? null) === 'completed') {
                // The completed result fence may have landed immediately before a
                // state/event write failed. Recover that projection, but do not turn
                // a previously committed completed batch into a new failure.
                if (! is_array($batch['result'])) {
                    return $this->batches->recordAggregate(
                        $project,
                        $run,
                        $phase,
                        $batchId,
                        'failed',
                        $orderedStatuses,
                        'The completed media batch projection was recovered from its immutable result.',
                    );
                }

                throw $exception;
            }

            [$orderedStatuses, $jobRecords] = $this->refreshJobRecords(
                $project,
                $run,
                $phase,
                $batchId,
                $request,
                $mediaJobIds,
                $orderedStatuses,
                $jobRecords,
            );

            return $this->batches->recordAggregate(
                $project,
                $run,
                $phase,
                $batchId,
                'failed',
                $orderedStatuses,
                'Post-render batch validation or promotion failed: '.Str::limit($exception->getMessage(), 500),
                $this->retainedAssets($jobRecords, $artifactIds),
            );
        }
    }

    /** @return array<string, mixed>|null */
    public function reconcileForJob(
        string $project,
        string $run,
        string $phase,
        string $jobId,
    ): ?array {
        $request = $this->jobs->request($project, $run, $phase, $jobId);
        $batchId = $request['batch_id'] ?? null;
        if (! is_string($batchId) || trim($batchId) === '') {
            return null;
        }
        if (! $this->batches->exists($project, $run, $phase, $batchId)) {
            return null;
        }

        return $this->reconcile($project, $run, $phase, $batchId);
    }

    /** @param list<string> $statuses */
    private function aggregateStatus(array $statuses): string
    {
        foreach (['failed', 'timed_out', 'cancelled'] as $terminal) {
            if (in_array($terminal, $statuses, true)) {
                return $terminal;
            }
        }
        if ($statuses !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'completed') {
            return 'completed';
        }

        return in_array('running', $statuses, true) ? 'running' : 'pending';
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, string>  $mediaJobIds
     * @param  array<string, string>  $statuses
     * @param  array<string, array<string, mixed>>  $records
     * @return array{array<string, string>, array<string, array<string, mixed>>}
     */
    private function refreshJobRecords(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        array $request,
        array $mediaJobIds,
        array $statuses,
        array $records,
    ): array {
        foreach ($mediaJobIds as $artifactId => $jobId) {
            if (! $this->jobs->exists($project, $run, $phase, $jobId)) {
                continue;
            }
            $job = $this->jobRecord(
                $project,
                $run,
                $phase,
                $batchId,
                $artifactId,
                $jobId,
                $request['generation_attempt_id'] ?? null,
            );
            $status = MediaJobStatus::tryFrom((string) ($job['state']['status'] ?? ''));
            if ($status === null) {
                throw new RuntimeException("Queued media job [{$jobId}] has an invalid state.");
            }
            $statuses[$jobId] = $status->value;
            $records[$artifactId] = $job;
        }

        return [$statuses, $records];
    }

    /** @return array<string, mixed> */
    private function jobRecord(
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $artifactId,
        string $jobId,
        mixed $generationAttemptId,
    ): array {
        $this->jobs->reconcileTerminalResult($project, $run, $phase, $jobId);
        $job = $this->jobs->job($project, $run, $phase, $jobId);
        if ($job['parse_errors'] !== [] || ! is_array($job['request']) || ! is_array($job['state'])) {
            throw new RuntimeException("Queued media job [{$jobId}] cannot be reconciled from its records.");
        }
        foreach ([
            'artifact_id' => $artifactId,
            'batch_id' => $batchId,
            'generation_attempt_id' => $generationAttemptId,
            'phase' => $phase,
        ] as $field => $expected) {
            if (($job['request'][$field] ?? null) !== $expected) {
                throw new RuntimeException("Queued media job [{$jobId}] has inconsistent {$field} lineage.");
            }
        }

        return $job;
    }

    /** @param array<string, mixed> $result */
    private function assertBinaryResult(
        string $project,
        string $run,
        string $phase,
        string $artifactId,
        array $result,
    ): void {
        $assetPath = $result['asset_path'] ?? null;
        $expectedHash = $result['asset_sha256'] ?? null;
        $expectedBytes = $result['asset_bytes'] ?? null;
        if (($result['artifact_id'] ?? null) !== $artifactId
            || ! is_string($assetPath)
            || ! is_string($expectedHash)
            || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
            || ! is_int($expectedBytes)
            || $expectedBytes < 1
            || ! is_array($result['media_probe'] ?? null)) {
            throw new RuntimeException("Completed queued media result for [{$artifactId}] is malformed.");
        }

        $binary = $this->jobs->assetPath($project, $run, $phase, $assetPath);
        $actualHash = File::isFile($binary) ? hash_file('sha256', $binary) : false;
        if (! is_string($actualHash)
            || ! hash_equals($expectedHash, $actualHash)
            || File::size($binary) !== $expectedBytes) {
            throw new RuntimeException("Completed queued media binary for [{$artifactId}] is missing or corrupt.");
        }
    }

    private function mediaType(mixed $artifactType): string
    {
        return match ($artifactType) {
            'image_sidecar' => 'image',
            'video_sidecar' => 'video',
            default => throw new RuntimeException('A queued simulated media batch contains a non-media artifact.'),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobRecords
     * @param  list<string>  $artifactIds
     * @return list<array<string, mixed>>
     */
    private function retainedAssets(array $jobRecords, array $artifactIds): array
    {
        $retained = [];
        foreach ($artifactIds as $artifactId) {
            $job = $jobRecords[$artifactId] ?? null;
            if (! is_array($job)) {
                continue;
            }
            $result = $job['result'] ?? null;
            if (! is_array($result) || ($result['status'] ?? null) !== 'completed') {
                continue;
            }
            $retained[] = [
                'artifact_id' => $artifactId,
                'media_job_id' => $job['job_id'] ?? null,
                'asset_path' => $result['asset_path'] ?? null,
                'asset_sha256' => $result['asset_sha256'] ?? null,
                'asset_bytes' => $result['asset_bytes'] ?? null,
                'reason' => 'Retained as immutable failed-attempt evidence; no artifact sidecar was committed.',
            ];
        }

        return $retained;
    }

    private function jobId(string $batchId, string $artifactId): string
    {
        return 'media-job--'.substr(hash('sha256', $batchId."\0".$artifactId), 0, 32);
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
