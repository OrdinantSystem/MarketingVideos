<?php

namespace App\Creative\Media;

use App\Creative\CreativePathResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class MediaBatchIntegrityAuditor
{
    /** @var list<string> */
    private const STATUSES = ['pending', 'running', 'completed', 'failed', 'cancelled', 'timed_out'];

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly MediaBatchStore $batches,
        private readonly MediaJobStore $jobs,
    ) {}

    /** @return list<array<string, mixed>> */
    public function audit(string $project, string $run, string $phase): array
    {
        $issues = [];
        $root = dirname($this->paths->outputsPath($project, $run, $phase)).'/media-batches';
        foreach (File::isDirectory($root) ? File::directories($root) : [] as $path) {
            $batchId = basename($path);
            if (preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $batchId) !== 1) {
                $this->issue($issues, 'invalid_media_batch_directory', $project, $run, $phase, $path, $batchId,
                    'The media batch directory has an unsafe identity.');

                continue;
            }

            $batch = $this->batches->batch($project, $run, $phase, $batchId);
            foreach ($batch['parse_errors'] as $error) {
                $this->issue($issues, 'malformed_media_batch_record', $project, $run, $phase, $path, $batchId, $error);
            }
            $request = $batch['request'];
            $state = $batch['state'];
            $result = $batch['result'];
            if (! is_array($request) || ! is_array($state)) {
                continue;
            }

            $artifactIds = $this->stringList($request['artifact_ids'] ?? null);
            $jobMap = is_array($request['media_job_ids'] ?? null) ? $request['media_job_ids'] : [];
            $draftHashes = is_array($request['draft_sha256'] ?? null) ? $request['draft_sha256'] : [];
            if (($request['schema_version'] ?? null) !== 1
                || ($request['id'] ?? null) !== $batchId
                || ($request['batch_id'] ?? null) !== $batchId
                || ($request['type'] ?? null) !== 'simulated_media_batch_request'
                || ($request['phase'] ?? null) !== $phase
                || ($request['generation_attempt_id'] ?? null) !== $batchId
                || $artifactIds === null
                || $artifactIds === []
                || array_keys($jobMap) !== $artifactIds
                || count($jobMap) !== count(array_unique($jobMap))
                || array_keys($draftHashes) !== $artifactIds
                || ! collect($draftHashes)->every(fn (mixed $hash): bool => is_string($hash)
                    && preg_match('/^[a-f0-9]{64}$/', $hash) === 1)) {
                $this->issue($issues, 'invalid_media_batch_request', $project, $run, $phase, $path.'/request.md', $batchId,
                    'Media batch request identity or exact artifact/job membership is invalid.');

                continue;
            }
            $this->auditDrafts($issues, $project, $run, $phase, $batchId, $path, $artifactIds, $draftHashes);

            $jobStatuses = [];
            foreach ($jobMap as $artifactId => $jobId) {
                if (! is_string($jobId) || ! $this->jobs->exists($project, $run, $phase, $jobId)) {
                    $this->issue($issues, 'media_batch_job_missing', $project, $run, $phase, $path, $batchId,
                        "Expected child job for [{$artifactId}] is missing.");
                    $jobStatuses[(string) $jobId] = 'pending';

                    continue;
                }
                $job = $this->jobs->job($project, $run, $phase, $jobId);
                if ($job['parse_errors'] !== [] || ! is_array($job['request']) || ! is_array($job['state'])) {
                    $this->issue($issues, 'invalid_media_batch_job', $project, $run, $phase, $job['path'], $jobId,
                        'A child job cannot be reconstructed from its request and state.');

                    continue;
                }
                if (($job['request']['artifact_id'] ?? null) !== $artifactId
                    || ($job['request']['batch_id'] ?? null) !== $batchId
                    || ($job['request']['generation_attempt_id'] ?? null) !== $batchId
                    || ($job['request']['phase'] ?? null) !== $phase) {
                    $this->issue($issues, 'media_batch_job_lineage_mismatch', $project, $run, $phase, $job['path'], $jobId,
                        'A child job does not match its immutable batch, phase, attempt, and artifact membership.');
                }
                $jobStatuses[$jobId] = is_string($job['state']['status'] ?? null)
                    ? $job['state']['status']
                    : 'invalid';
            }

            $stateStatus = is_string($state['status'] ?? null) ? $state['status'] : '';
            if (($state['schema_version'] ?? null) !== 1
                || ($state['batch_id'] ?? null) !== $batchId
                || ($state['type'] ?? null) !== 'simulated_media_batch_state'
                || ! in_array($stateStatus, self::STATUSES, true)
                || ($state['media_job_statuses'] ?? null) !== $jobStatuses) {
                $this->issue($issues, 'invalid_media_batch_state', $project, $run, $phase, $path.'/state.md', $batchId,
                    'Media batch state does not match the exact current child-job aggregate.');
            }

            $events = $batch['events'];
            $sequences = array_column($events, 'sequence');
            if ($events === [] || $sequences !== range(1, count($events))
                || collect($events)->contains(fn (array $event): bool => ($event['batch_id'] ?? null) !== $batchId
                    || ($event['type'] ?? null) !== 'simulated_media_batch_event')) {
                $this->issue($issues, 'invalid_media_batch_events', $project, $run, $phase, $path.'/events', $batchId,
                    'Media batch events are missing, malformed, or have a non-contiguous sequence.');
            }

            $terminal = in_array($stateStatus, ['completed', 'failed', 'cancelled', 'timed_out'], true);
            if ($terminal && ! is_array($result)) {
                $this->issue($issues, 'media_batch_result_missing', $project, $run, $phase, $path.'/result.md', $batchId,
                    'Terminal media batch state has no immutable result commit fence.');

                continue;
            }
            if (! $terminal && is_array($result)) {
                $this->issue($issues, 'media_batch_result_contradiction', $project, $run, $phase, $path.'/result.md', $batchId,
                    'A non-terminal media batch already has an immutable terminal result.');
            }
            if (! is_array($result)) {
                continue;
            }
            if (($result['schema_version'] ?? null) !== 1
                || ($result['id'] ?? null) !== $batchId.'--result'
                || ($result['batch_id'] ?? null) !== $batchId
                || ($result['type'] ?? null) !== 'simulated_media_batch_result'
                || ($result['phase'] ?? null) !== $phase
                || ($result['status'] ?? null) !== $stateStatus
                || ($result['media_job_statuses'] ?? null) !== $jobStatuses) {
                $this->issue($issues, 'invalid_media_batch_result', $project, $run, $phase, $path.'/result.md', $batchId,
                    'Media batch result identity, status, or child membership is inconsistent.');
            }
            if ($stateStatus === 'completed') {
                $this->auditCompleted($issues, $project, $run, $phase, $batchId, $path, $artifactIds, $result, $events);
            } elseif (in_array($stateStatus, ['failed', 'cancelled', 'timed_out'], true)) {
                $this->auditRetainedFailureAssets(
                    $issues,
                    $project,
                    $run,
                    $phase,
                    $batchId,
                    $path,
                    $jobStatuses,
                    $jobMap,
                    $result,
                );
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $artifactIds
     * @param  array<string, mixed>  $draftHashes
     */
    private function auditDrafts(
        array &$issues,
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $path,
        array $artifactIds,
        array $draftHashes,
    ): void {
        $draftRoot = $path.'/drafts';
        $actualNames = array_values(collect(File::isDirectory($draftRoot) ? File::files($draftRoot) : [])
            ->map(fn (\SplFileInfo $file): string => $file->getFilename())
            ->sort()
            ->all());
        $expectedNames = array_map(fn (string $artifactId): string => $artifactId.'.md', $artifactIds);
        sort($expectedNames);
        if ($actualNames !== $expectedNames) {
            $this->issue($issues, 'media_batch_draft_membership_mismatch', $project, $run, $phase, $draftRoot, $batchId,
                'Staged media drafts do not match the exact immutable artifact membership.');
        }

        foreach ($artifactIds as $artifactId) {
            $draft = $draftRoot.'/'.$artifactId.'.md';
            $expectedHash = $draftHashes[$artifactId] ?? null;
            $actualHash = File::isFile($draft) ? hash_file('sha256', $draft) : false;
            if (is_link($draft)
                || ! is_string($expectedHash)
                || ! is_string($actualHash)
                || ! hash_equals($expectedHash, $actualHash)) {
                $this->issue($issues, 'media_batch_draft_hash_mismatch', $project, $run, $phase, $draft, $artifactId,
                    'A staged media draft is missing, linked, or no longer matches its immutable request SHA-256.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, string>  $jobStatuses
     * @param  array<string, string>  $jobMap
     * @param  array<string, mixed>  $result
     */
    private function auditRetainedFailureAssets(
        array &$issues,
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $path,
        array $jobStatuses,
        array $jobMap,
        array $result,
    ): void {
        $retained = $result['retained_failed_attempt_assets'] ?? null;
        $expectedCount = count(array_filter($jobStatuses, fn (string $status): bool => $status === 'completed'));
        if (! is_array($retained) || ! array_is_list($retained) || count($retained) !== $expectedCount) {
            $this->issue($issues, 'media_batch_retained_assets_invalid', $project, $run, $phase, $path.'/result.md', $batchId,
                'Failed media batch does not identify every completed child binary retained as immutable evidence.');

            return;
        }

        $expectedArtifactIds = [];
        $expectedJobIds = [];
        foreach ($jobMap as $artifactId => $jobId) {
            if (($jobStatuses[$jobId] ?? null) === 'completed') {
                $expectedArtifactIds[] = $artifactId;
                $expectedJobIds[] = $jobId;
            }
        }
        if (array_column($retained, 'artifact_id') !== $expectedArtifactIds
            || array_column($retained, 'media_job_id') !== $expectedJobIds) {
            $this->issue($issues, 'media_batch_retained_assets_invalid', $project, $run, $phase, $path.'/result.md', $batchId,
                'Failed media batch retained assets do not match completed child identities in immutable request order.');
        }

        $retainedArtifactIds = [];
        $retainedJobIds = [];

        foreach ($retained as $asset) {
            if (! is_array($asset)
                || ! is_string($artifactId = $asset['artifact_id'] ?? null)
                || ! is_string($mediaJobId = $asset['media_job_id'] ?? null)
                || ($jobMap[$artifactId] ?? null) !== $mediaJobId
                || ($jobStatuses[$mediaJobId] ?? null) !== 'completed'
                || ! is_string($assetPath = $asset['asset_path'] ?? null)
                || ! str_starts_with($assetPath, 'media-batches/'.$batchId.'/assets/')
                || ! is_string($expectedHash = $asset['asset_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
                || ! is_int($expectedBytes = $asset['asset_bytes'] ?? null)
                || $expectedBytes < 1) {
                $this->issue($issues, 'media_batch_retained_assets_invalid', $project, $run, $phase, $path.'/result.md', $batchId,
                    'A retained failed-attempt asset record is malformed or outside its batch-private directory.');

                continue;
            }
            $retainedArtifactIds[] = $artifactId;
            $retainedJobIds[] = $mediaJobId;
            try {
                $childResult = $this->jobs->result($project, $run, $phase, $mediaJobId);
                if (! is_array($childResult)
                    || ($childResult['status'] ?? null) !== 'completed'
                    || ($childResult['artifact_id'] ?? null) !== $artifactId
                    || ($childResult['asset_path'] ?? null) !== $assetPath
                    || ($childResult['asset_sha256'] ?? null) !== $expectedHash
                    || ($childResult['asset_bytes'] ?? null) !== $expectedBytes) {
                    throw new \RuntimeException('Retained evidence disagrees with its child result.');
                }
            } catch (Throwable) {
                $this->issue($issues, 'media_batch_retained_assets_invalid', $project, $run, $phase, $path.'/result.md', $batchId,
                    'A retained failed-attempt asset does not exactly match its immutable completed child result.');
            }
            try {
                $binary = $this->jobs->assetPath($project, $run, $phase, $assetPath);
                $actualHash = File::isFile($binary) ? hash_file('sha256', $binary) : false;
                if (! is_string($actualHash)
                    || ! hash_equals($expectedHash, $actualHash)
                    || File::size($binary) !== $expectedBytes) {
                    throw new \RuntimeException('Retained binary identity mismatch.');
                }
            } catch (Throwable) {
                $this->issue($issues, 'media_batch_retained_asset_mismatch', $project, $run, $phase, $assetPath, $batchId,
                    'A retained failed-attempt binary is missing, unsafe, or does not match its recorded identity.');
            }
        }
        if (count($retainedArtifactIds) !== count(array_unique($retainedArtifactIds))
            || count($retainedJobIds) !== count(array_unique($retainedJobIds))) {
            $this->issue($issues, 'media_batch_retained_assets_invalid', $project, $run, $phase, $path.'/result.md', $batchId,
                'Failed media batch retained-asset records contain duplicate artifact or media-job identities.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $artifactIds
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $events
     */
    private function auditCompleted(
        array &$issues,
        string $project,
        string $run,
        string $phase,
        string $batchId,
        string $path,
        array $artifactIds,
        array $result,
        array $events,
    ): void {
        $hashes = is_array($result['artifact_sha256'] ?? null) ? $result['artifact_sha256'] : [];
        if (($result['artifact_ids'] ?? null) !== $artifactIds
            || ($result['output_count'] ?? null) !== count($artifactIds)
            || ($result['expected_output_count'] ?? null) !== count($artifactIds)
            || array_keys($hashes) !== $artifactIds) {
            $this->issue($issues, 'media_batch_output_manifest_invalid', $project, $run, $phase, $path.'/result.md', $batchId,
                'Completed media batch does not describe the exact requested sidecar set.');

            return;
        }
        foreach ($artifactIds as $artifactId) {
            $output = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
            $actual = File::isFile($output) ? hash_file('sha256', $output) : false;
            $expected = $hashes[$artifactId] ?? null;
            if (! is_string($expected) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
                $this->issue($issues, 'media_batch_sidecar_hash_mismatch', $project, $run, $phase, $output, $artifactId,
                    'A completed batch sidecar is missing or does not match its terminal SHA-256.');
            }
        }
        if (! collect($events)->contains(fn (array $event): bool => ($event['status'] ?? null) === 'completed'
            && in_array($event['event'] ?? null, ['completed', 'terminal_recovered'], true))) {
            $this->issue($issues, 'media_batch_terminal_event_missing', $project, $run, $phase, $path.'/events', $batchId,
                'Completed media batch has no append-only terminal event.');
        }
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        return collect($value)->every(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ? $value
            : null;
    }

    /** @param list<array<string, mixed>> $issues */
    private function issue(
        array &$issues,
        string $code,
        string $project,
        string $run,
        string $phase,
        string $path,
        string $entity,
        string $message,
    ): void {
        $issues[] = [
            'severity' => 'error',
            'code' => $code,
            'project' => $project,
            'run' => $run,
            'phase' => $phase,
            'path' => Str::after($path, $this->paths->root().'/'),
            'entity' => $entity,
            'message' => $message,
            'remediation' => 'Preserve the evidence, reconcile the batch from its immutable request and child jobs, or create a new attempt.',
        ];
    }
}
