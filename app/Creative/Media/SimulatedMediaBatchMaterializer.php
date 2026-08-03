<?php

namespace App\Creative\Media;

use RuntimeException;

final class SimulatedMediaBatchMaterializer
{
    public function __construct(
        private readonly SimulatedMediaJobService $media,
        private readonly MediaJobStore $jobs,
    ) {}

    /**
     * @param  list<array{id: string, metadata: array<string, mixed>, body: string}>  $documents
     * @return list<array{id: string, metadata: array<string, mixed>, body: string}>
     */
    public function materialize(
        string $project,
        string $run,
        string $phase,
        string $generationAttemptId,
        array $documents,
    ): array {
        if (! in_array($phase, ['images', 'scenes'], true)) {
            return $documents;
        }

        $reserved = [];
        foreach ($documents as $index => $document) {
            $metadata = $document['metadata'];
            $mediaType = match ($metadata['artifact_type'] ?? null) {
                'image_sidecar' => 'image',
                'video_sidecar' => 'video',
                default => throw new RuntimeException('A simulated media batch contains a non-media artifact.'),
            };
            $shot = is_array($metadata['shot'] ?? null) ? $metadata['shot'] : [];
            $duration = is_numeric($shot['end_seconds'] ?? null) && is_numeric($shot['start_seconds'] ?? null)
                ? (float) $shot['end_seconds'] - (float) $shot['start_seconds']
                : 1.0;
            $submission = $this->media->reserve(
                $project,
                $run,
                $phase,
                $document['id'],
                $mediaType,
                [
                    'label' => (string) ($metadata['title'] ?? $document['id']),
                    'color' => (string) ($metadata['preview_color'] ?? '#557c70'),
                    'duration_seconds' => max(0.25, $duration),
                ],
                [
                    'generation_attempt_id' => $generationAttemptId,
                    'batch_id' => $generationAttemptId,
                    'batch_index' => $index + 1,
                    'batch_size' => count($documents),
                ],
            );
            $reserved[$document['id']] = $submission['job_id'];
        }

        $materialized = [];
        $failures = [];
        foreach ($documents as $document) {
            $jobId = $reserved[$document['id']];
            $state = $this->media->process($project, $run, $phase, $jobId);
            $result = $this->jobs->result($project, $run, $phase, $jobId);
            if (($state['status'] ?? null) !== MediaJobStatus::Completed->value || ! is_array($result)) {
                $failures[] = $jobId.': '.(string) ($state['status'] ?? 'unknown');

                continue;
            }

            $document['metadata'] = array_merge($document['metadata'], [
                'status' => 'ready',
                'asset_status' => 'ready',
                'asset_path' => $result['asset_path'],
                'asset_sha256' => $result['asset_sha256'],
                'asset_bytes' => $result['asset_bytes'],
                'media_probe' => $result['media_probe'],
                'media_job_id' => $jobId,
            ]);
            $document['body'] = str_replace(
                [
                    'Simulation sidecar only. No PNG was created.',
                    'Simulation sidecar only. No MP4 was created.',
                ],
                'A deterministic local simulation binary was created, probed, and hashed. No provider was contacted.',
                $document['body'],
            );
            $materialized[] = $document;
        }

        if ($failures !== []) {
            throw new RuntimeException('The simulated media batch did not complete: '.implode('; ', $failures));
        }

        return $materialized;
    }
}
