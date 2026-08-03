<?php

namespace App\Creative;

use App\Creative\Media\MediaBatchIntegrityAuditor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class CreativeOperationalIntegrityAuditor
{
    /** @var list<string> */
    private const STATUSES = ['pending', 'running', 'completed', 'failed', 'cancelled', 'timed_out'];

    /** @var list<string> */
    private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'timed_out'];

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativePhaseCatalog $phases,
        private readonly MediaBatchIntegrityAuditor $mediaBatches,
    ) {}

    /** @return list<array<string, mixed>> */
    public function audit(string $project, string $run): array
    {
        $issues = [];
        foreach (array_keys($this->phases->all()) as $phase) {
            $this->auditMediaJobs($project, $run, $phase, $issues);
            $issues = array_merge($issues, $this->mediaBatches->audit($project, $run, $phase));
        }
        $this->auditRenders($project, $run, $issues);

        return $issues;
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditMediaJobs(string $project, string $run, string $phase, array &$issues): void
    {
        $root = dirname($this->paths->outputsPath($project, $run, $phase)).'/media-jobs';
        $jobPaths = File::isDirectory($root) ? File::directories($root) : [];

        foreach ($jobPaths as $jobPath) {
            $jobId = basename($jobPath);
            if (preg_match('/^[a-z0-9][a-z0-9-]*(?:--[a-z0-9-]+)+$/', $jobId) !== 1) {
                $this->issue(
                    $issues,
                    'invalid_media_job_directory',
                    $project,
                    $run,
                    $phase,
                    $jobPath,
                    $jobId,
                    'The media job directory name is not a valid immutable job ID.',
                    'Quarantine the directory after preserving it for investigation.',
                );

                continue;
            }

            $request = $this->operationalDocument(
                $jobPath.'/request.md',
                'media_job_request_missing',
                'malformed_media_job_request',
                $project,
                $run,
                $phase,
                $jobId,
                $issues,
            );
            $state = $this->operationalDocument(
                $jobPath.'/state.md',
                'media_job_state_missing',
                'malformed_media_job_state',
                $project,
                $run,
                $phase,
                $jobId,
                $issues,
            );
            $resultPath = $jobPath.'/result.md';
            $result = File::isFile($resultPath)
                ? $this->operationalDocument(
                    $resultPath,
                    'media_job_result_missing',
                    'malformed_media_job_result',
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    $issues,
                )
                : null;

            if (is_array($request)) {
                $errors = $this->mediaRequestErrors($request, $project, $run, $phase, $jobId);
                $this->invalidRecordIssue(
                    $issues,
                    $errors,
                    'invalid_media_job_request',
                    $project,
                    $run,
                    $phase,
                    $jobPath.'/request.md',
                    $jobId,
                );
            }
            if (is_array($state)) {
                $errors = $this->mediaStateErrors($state, $jobId);
                $this->invalidRecordIssue(
                    $issues,
                    $errors,
                    'invalid_media_job_state',
                    $project,
                    $run,
                    $phase,
                    $jobPath.'/state.md',
                    $jobId,
                );
            }
            if (is_array($result)) {
                $errors = $this->mediaResultErrors($result, $phase, $jobId);
                $this->invalidRecordIssue(
                    $issues,
                    $errors,
                    'invalid_media_job_result',
                    $project,
                    $run,
                    $phase,
                    $resultPath,
                    $jobId,
                );
            }

            $this->auditMediaEvents($project, $run, $phase, $jobId, $jobPath, $state, $issues);
            $this->auditMediaStatus($project, $run, $phase, $jobId, $jobPath, $state, $result, $issues);

            if (is_array($request) && is_array($result)) {
                $requestArtifact = $request['artifact_id'] ?? null;
                $resultArtifact = $result['artifact_id'] ?? null;
                if (is_string($requestArtifact)
                    && is_string($resultArtifact)
                    && $requestArtifact !== $resultArtifact) {
                    $this->issue(
                        $issues,
                        'media_job_artifact_mismatch',
                        $project,
                        $run,
                        $phase,
                        $resultPath,
                        $jobId,
                        'Media job request and result reference different artifact IDs.',
                        'Preserve both records and regenerate a coherent media job.',
                    );
                }
                if (is_string($request['asset_path'] ?? null)
                    && is_string($result['asset_path'] ?? null)
                    && $request['asset_path'] !== $result['asset_path']) {
                    $this->issue(
                        $issues,
                        'media_job_asset_path_mismatch',
                        $project,
                        $run,
                        $phase,
                        $resultPath,
                        $jobId,
                        'Media job request and completed result reference different asset paths.',
                        'Preserve both records and regenerate a coherent media job.',
                    );
                }
            }

            if (is_array($result) && ($result['status'] ?? null) === 'completed') {
                $this->auditCompletedMediaJob(
                    $project,
                    $run,
                    $phase,
                    $jobId,
                    $jobPath,
                    $request,
                    $result,
                    $issues,
                );
            }
        }

        $this->auditOrphanedMediaSidecars($project, $run, $phase, $root, $issues);
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditOrphanedMediaSidecars(
        string $project,
        string $run,
        string $phase,
        string $jobsRoot,
        array &$issues,
    ): void {
        foreach ($this->mediaArtifacts($project, $run, $phase) as $artifactId => $metadata) {
            $jobId = $metadata['media_job_id'] ?? null;
            if (! is_string($jobId) || $jobId === '') {
                continue;
            }

            $requestPath = $jobsRoot.'/'.$jobId.'/request.md';
            if (File::isFile($requestPath)) {
                continue;
            }

            $this->issue(
                $issues,
                'media_sidecar_job_missing',
                $project,
                $run,
                $phase,
                $requestPath,
                $jobId,
                "Media artifact [{$artifactId}] references a missing immutable media job request.",
                'Preserve the sidecar and restore its exact job records, or regenerate a coherent immutable artifact batch.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditMediaEvents(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        string $jobPath,
        ?array $state,
        array &$issues,
    ): void {
        $eventsPath = $jobPath.'/events';
        $events = [];
        if (File::isDirectory($eventsPath)) {
            foreach (File::files($eventsPath) as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }

                try {
                    $event = MarkdownDocument::read($file->getPathname())['metadata'];
                } catch (Throwable) {
                    $this->issue(
                        $issues,
                        'malformed_media_job_event',
                        $project,
                        $run,
                        $phase,
                        $file->getPathname(),
                        $jobId,
                        'A media job event cannot be parsed.',
                        'Preserve the event and append a newer truthful terminal event if recovery is required.',
                    );

                    continue;
                }

                $errors = $this->mediaEventErrors($event, $jobId, $file->getBasename('.md'));
                $this->invalidRecordIssue(
                    $issues,
                    $errors,
                    'invalid_media_job_event',
                    $project,
                    $run,
                    $phase,
                    $file->getPathname(),
                    $jobId,
                );
                if ($errors === []) {
                    $events[] = $event;
                }
            }
        }

        if ($events === []) {
            $this->issue(
                $issues,
                'media_job_events_missing',
                $project,
                $run,
                $phase,
                $eventsPath,
                $jobId,
                'The media job has no readable valid lifecycle events.',
                'Restore the append-only event records or terminalize the job through recovery.',
            );

            return;
        }

        $sequences = array_column($events, 'sequence');
        if (count($sequences) !== count(array_unique($sequences))) {
            $this->issue(
                $issues,
                'duplicate_media_job_event_sequence',
                $project,
                $run,
                $phase,
                $eventsPath,
                $jobId,
                'The media job has duplicate event sequence numbers.',
                'Preserve the conflicting records and append a newer unambiguous recovery event.',
            );
        }

        $status = is_array($state) && is_string($state['status'] ?? null) ? $state['status'] : null;
        if (in_array($status, self::TERMINAL_STATUSES, true)
            && ! collect($events)->contains(fn (array $event): bool => ($event['status'] ?? null) === $status
                && in_array($event['event'] ?? null, [$status, 'terminal_recovered'], true))) {
            $this->issue(
                $issues,
                'media_job_terminal_event_missing',
                $project,
                $run,
                $phase,
                $eventsPath,
                $jobId,
                "Terminal media job status [{$status}] has no matching immutable event.",
                'Restore the event or record a newer recovery event without deleting existing history.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, mixed>|null  $result
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditMediaStatus(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        string $jobPath,
        ?array $state,
        ?array $result,
        array &$issues,
    ): void {
        $status = is_array($state) && is_string($state['status'] ?? null) ? $state['status'] : null;
        $resultStatus = is_array($result) && is_string($result['status'] ?? null) ? $result['status'] : null;
        if (in_array($status, ['pending', 'running'], true)) {
            $this->issue(
                $issues,
                'incomplete_media_job',
                $project,
                $run,
                $phase,
                $jobPath.'/state.md',
                $jobId,
                "Media job remains {$status} and requires processing, cancellation, or recovery.",
                'Run the local worker, cancel the job, or use recovery to record a truthful terminal result.',
            );
        }

        if (in_array($status, self::TERMINAL_STATUSES, true) && $result === null) {
            $this->issue(
                $issues,
                'media_job_result_missing',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                'Terminal media job state has no immutable result record.',
                'Restore or reconstruct the terminal result without changing the recorded outcome.',
            );
        }

        if ($result !== null && $status !== null && $resultStatus !== $status) {
            $this->issue(
                $issues,
                'media_job_status_mismatch',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                'Media job state and result statuses disagree.',
                'Preserve both records and recover the job to one truthful terminal status.',
            );
        }
        if (is_array($state)
            && is_array($result)
            && is_int($state['attempt'] ?? null)
            && is_int($result['attempt'] ?? null)
            && $state['attempt'] !== $result['attempt']) {
            $this->issue(
                $issues,
                'media_job_attempt_mismatch',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                'Media job state and result attempt numbers disagree.',
                'Preserve both records and recover the job to one truthful terminal attempt.',
            );
        }

        if ($result !== null && in_array($status, ['pending', 'running'], true)) {
            $this->issue(
                $issues,
                'unexpected_media_job_result',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                'A non-terminal media job already has a terminal result record.',
                'Treat the records as contradictory and recover them without deleting evidence.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditCompletedMediaJob(
        string $project,
        string $run,
        string $phase,
        string $jobId,
        string $jobPath,
        ?array $request,
        array $result,
        array &$issues,
    ): void {
        $assetPath = $result['asset_path'] ?? null;
        $asset = is_string($assetPath)
            ? $this->containedFile(
                dirname($this->paths->outputsPath($project, $run, $phase)),
                $this->paths->runPath($project, $run),
                $assetPath,
            )
            : ['path' => null, 'error' => 'missing'];
        if ($asset['error'] !== null) {
            $this->issue(
                $issues,
                $asset['error'] === 'unsafe' ? 'media_job_asset_path_invalid' : 'media_job_asset_missing',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                $asset['error'] === 'unsafe'
                    ? 'Completed media job asset_path is unsafe or resolves outside the run.'
                    : 'Completed media job binary is missing.',
                'Restore the exact immutable binary or create a new media generation attempt.',
            );
        } elseif (is_string($asset['path'])) {
            $this->verifyFileIdentity(
                $project,
                $run,
                $phase,
                $jobId,
                $jobPath.'/result.md',
                $asset['path'],
                $result['asset_sha256'] ?? null,
                $result['asset_bytes'] ?? null,
                'media_job_asset',
                $issues,
            );
        }

        $requestArtifact = is_array($request) ? ($request['artifact_id'] ?? null) : null;
        $resultArtifact = $result['artifact_id'] ?? null;
        if (! is_string($resultArtifact) || trim($resultArtifact) === '') {
            $this->issue(
                $issues,
                'invalid_media_job_result',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                'Completed media result has no artifact_id.',
                'Restore the immutable result identity from the request and generated sidecar.',
            );
        }
        $artifactId = is_string($resultArtifact) && trim($resultArtifact) !== ''
            ? $resultArtifact
            : $requestArtifact;
        if (! is_string($artifactId) || trim($artifactId) === '') {
            return;
        }

        $artifacts = $this->mediaArtifacts($project, $run, $phase);
        $byId = $artifacts[$artifactId] ?? null;
        if (is_array($byId) && ($byId['media_job_id'] ?? null) !== $jobId) {
            $this->issue(
                $issues,
                'media_job_artifact_mismatch',
                $project,
                $run,
                $phase,
                $jobPath.'/result.md',
                $jobId,
                'Completed media job points to an artifact sidecar linked to a different media_job_id.',
                'Preserve both records and regenerate a coherent immutable artifact batch.',
            );
        }

        foreach ($artifacts as $id => $metadata) {
            if (($metadata['media_job_id'] ?? null) === $jobId && $id !== $artifactId) {
                $this->issue(
                    $issues,
                    'media_job_artifact_mismatch',
                    $project,
                    $run,
                    $phase,
                    $jobPath.'/result.md',
                    $jobId,
                    "Media job is linked from artifact [{$id}] but records artifact [{$artifactId}].",
                    'Preserve both records and regenerate a coherent immutable artifact batch.',
                );
            }
        }
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditRenders(string $project, string $run, array &$issues): void
    {
        $root = $this->paths->runPath($project, $run).'/renders';
        if (! File::isDirectory($root)) {
            return;
        }

        foreach (File::directories($root) as $renderPath) {
            $renderId = basename($renderPath);
            if (preg_match('/^render-[a-z0-9-]+--[a-z0-9]+$/', $renderId) !== 1) {
                $this->issue(
                    $issues,
                    'invalid_render_directory',
                    $project,
                    $run,
                    null,
                    $renderPath,
                    $renderId,
                    'The render directory name is not a valid immutable render ID.',
                    'Quarantine the directory after preserving it for investigation.',
                );

                continue;
            }

            $request = $this->operationalDocument(
                $renderPath.'/request.md',
                'render_request_missing',
                'malformed_render_request',
                $project,
                $run,
                null,
                $renderId,
                $issues,
            );
            $state = $this->operationalDocument(
                $renderPath.'/state.md',
                'render_state_missing',
                'malformed_render_state',
                $project,
                $run,
                null,
                $renderId,
                $issues,
            );
            $manifestPath = $renderPath.'/manifest.md';
            $manifest = File::isFile($manifestPath)
                ? $this->operationalDocument(
                    $manifestPath,
                    'render_manifest_missing',
                    'malformed_render_manifest',
                    $project,
                    $run,
                    null,
                    $renderId,
                    $issues,
                )
                : null;

            if (is_array($request)) {
                $this->invalidRecordIssue(
                    $issues,
                    $this->renderRequestErrors($request, $project, $run, $renderId),
                    'invalid_render_request',
                    $project,
                    $run,
                    null,
                    $renderPath.'/request.md',
                    $renderId,
                );
            }
            if (is_array($state)) {
                $this->invalidRecordIssue(
                    $issues,
                    $this->renderStateErrors($state, $renderId),
                    'invalid_render_state',
                    $project,
                    $run,
                    null,
                    $renderPath.'/state.md',
                    $renderId,
                );
            }
            if (is_array($manifest)) {
                $this->invalidRecordIssue(
                    $issues,
                    $this->renderManifestErrors($manifest, $renderId),
                    'invalid_render_manifest',
                    $project,
                    $run,
                    null,
                    $manifestPath,
                    $renderId,
                );
            }

            $this->auditRenderStatus($project, $run, $renderId, $renderPath, $state, $manifest, $issues);
            if (is_array($request) && is_array($manifest)) {
                $this->auditRenderLineage($project, $run, $renderId, $manifestPath, $request, $manifest, $issues);
            }
            if (is_array($manifest) && ($manifest['status'] ?? null) === 'completed') {
                $this->auditCompletedRender($project, $run, $renderId, $manifestPath, $manifest, $issues);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, mixed>|null  $manifest
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditRenderStatus(
        string $project,
        string $run,
        string $renderId,
        string $renderPath,
        ?array $state,
        ?array $manifest,
        array &$issues,
    ): void {
        $status = is_array($state) && is_string($state['status'] ?? null) ? $state['status'] : null;
        $manifestStatus = is_array($manifest) && is_string($manifest['status'] ?? null)
            ? $manifest['status']
            : null;
        if (in_array($status, ['pending', 'running'], true)) {
            $this->issue(
                $issues,
                'incomplete_render',
                $project,
                $run,
                null,
                $renderPath.'/state.md',
                $renderId,
                "Render remains {$status} and requires processing, cancellation, or recovery.",
                'Run the local render worker, cancel the render, or recover it to a truthful terminal state.',
            );
        }
        if (in_array($status, self::TERMINAL_STATUSES, true) && $manifest === null) {
            $this->issue(
                $issues,
                'render_manifest_missing',
                $project,
                $run,
                null,
                $renderPath.'/manifest.md',
                $renderId,
                'Terminal render state has no immutable manifest.',
                'Restore or reconstruct the terminal manifest without changing the recorded outcome.',
            );
        }
        if ($manifest !== null && $status !== null && $manifestStatus !== $status) {
            $this->issue(
                $issues,
                'render_status_mismatch',
                $project,
                $run,
                null,
                $renderPath.'/manifest.md',
                $renderId,
                'Render state and manifest statuses disagree.',
                'Preserve both records and recover the render to one truthful terminal status.',
            );
        }
        if ($manifest !== null && in_array($status, ['pending', 'running'], true)) {
            $this->issue(
                $issues,
                'unexpected_render_manifest',
                $project,
                $run,
                null,
                $renderPath.'/manifest.md',
                $renderId,
                'A non-terminal render already has a terminal manifest.',
                'Treat the records as contradictory and recover them without deleting evidence.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditRenderLineage(
        string $project,
        string $run,
        string $renderId,
        string $manifestPath,
        array $request,
        array $manifest,
        array &$issues,
    ): void {
        foreach (['renderer', 'sequence_artifact_id', 'sequence_selection_id', 'scene_artifact_ids', 'scene_selection_ids', 'scene_asset_sha256'] as $field) {
            if (array_key_exists($field, $request)
                && array_key_exists($field, $manifest)
                && $request[$field] !== $manifest[$field]) {
                $this->issue(
                    $issues,
                    'render_lineage_mismatch',
                    $project,
                    $run,
                    null,
                    $manifestPath,
                    $renderId,
                    "Render manifest does not match immutable request field [{$field}].",
                    'Preserve the records and submit a new render from the current exact selections.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditCompletedRender(
        string $project,
        string $run,
        string $renderId,
        string $manifestPath,
        array $manifest,
        array &$issues,
    ): void {
        $specs = [
            ['name' => 'final', 'path' => 'final_path', 'hash' => 'final_sha256', 'bytes' => 'final_bytes'],
            ['name' => 'captions', 'path' => 'captions_path', 'hash' => 'captions_sha256', 'bytes' => null],
            ['name' => 'voice_script', 'path' => 'voice_script_path', 'hash' => 'voice_script_sha256', 'bytes' => null],
            ['name' => 'audio', 'path' => 'audio_path', 'hash' => 'audio_sha256', 'bytes' => 'audio_bytes'],
        ];
        $runPath = $this->paths->runPath($project, $run);
        foreach ($specs as $spec) {
            $relative = $manifest[$spec['path']] ?? null;
            $file = is_string($relative)
                ? $this->containedFile($runPath, $runPath, $relative)
                : ['path' => null, 'error' => 'missing'];
            if (is_string($relative) && ! str_starts_with($relative, "renders/{$renderId}/")) {
                $file = ['path' => null, 'error' => 'unsafe'];
            }
            if ($file['error'] !== null) {
                $this->issue(
                    $issues,
                    $file['error'] === 'unsafe' ? 'render_output_path_invalid' : 'render_output_missing',
                    $project,
                    $run,
                    null,
                    $manifestPath,
                    $renderId,
                    "Completed render {$spec['name']} output is missing or outside its render directory.",
                    'Restore the exact immutable output or submit a new deterministic render.',
                );

                continue;
            }

            if (is_string($file['path'])) {
                $expectedBytes = is_string($spec['bytes']) ? ($manifest[$spec['bytes']] ?? null) : null;
                $this->verifyFileIdentity(
                    $project,
                    $run,
                    null,
                    $renderId,
                    $manifestPath,
                    $file['path'],
                    $manifest[$spec['hash']] ?? null,
                    $expectedBytes,
                    'render_output',
                    $issues,
                    verifyBytes: is_string($spec['bytes']),
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>|null
     */
    private function operationalDocument(
        string $path,
        string $missingCode,
        string $malformedCode,
        string $project,
        string $run,
        ?string $phase,
        string $entity,
        array &$issues,
    ): ?array {
        if (! File::isFile($path)) {
            $this->issue(
                $issues,
                $missingCode,
                $project,
                $run,
                $phase,
                $path,
                $entity,
                'Required operational record is missing.',
                'Restore or reconstruct the record without deleting immutable evidence.',
            );

            return null;
        }

        try {
            $metadata = MarkdownDocument::read($path)['metadata'];
        } catch (Throwable) {
            $this->issue(
                $issues,
                $malformedCode,
                $project,
                $run,
                $phase,
                $path,
                $entity,
                'Operational Markdown or YAML cannot be parsed.',
                'Preserve the malformed record and recover with a newer truthful record where supported.',
            );

            return null;
        }

        if ($metadata === []) {
            $this->issue(
                $issues,
                $malformedCode,
                $project,
                $run,
                $phase,
                $path,
                $entity,
                'Operational record has no metadata.',
                'Preserve the record and recover with complete machine-readable metadata.',
            );

            return null;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function mediaRequestErrors(array $record, string $project, string $run, string $phase, string $jobId): array
    {
        $errors = $this->identityErrors($record, $jobId, 'media_job_request', $jobId);
        foreach ([
            'project_id' => $project,
            'run_id' => $run,
            'phase' => $phase,
        ] as $field => $expected) {
            if (($record[$field] ?? null) !== $expected) {
                $errors[] = "{$field} does not match its directory.";
            }
        }
        if (($record['simulation'] ?? null) !== true) {
            $errors[] = 'simulation must be true.';
        }
        if (! in_array($record['media_type'] ?? null, ['image', 'video', 'audio'], true)) {
            $errors[] = 'media_type must be image, video, or audio.';
        }
        if (! is_string($record['asset_path'] ?? null) || ! $this->safeRelativePath($record['asset_path'])) {
            $errors[] = 'asset_path must be a safe relative path.';
        }
        if (! $this->isTimestamp($record['created_at'] ?? null)) {
            $errors[] = 'created_at must be an ISO-8601 timestamp.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function mediaStateErrors(array $record, string $jobId): array
    {
        $errors = $this->identityErrors($record, $jobId.'--state', 'media_job_state', $jobId);
        $status = $record['status'] ?? null;
        if (! in_array($status, self::STATUSES, true)) {
            $errors[] = 'status is unsupported.';
        }
        if (! is_int($record['attempt'] ?? null) || $record['attempt'] < 0) {
            $errors[] = 'attempt must be a non-negative integer.';
        }
        if (! is_bool($record['cancel_requested'] ?? null)) {
            $errors[] = 'cancel_requested must be a boolean.';
        }
        foreach (['created_at', 'updated_at'] as $field) {
            if (! $this->isTimestamp($record[$field] ?? null)) {
                $errors[] = "{$field} must be an ISO-8601 timestamp.";
            }
        }
        if (! $this->isNullableTimestamp($record['started_at'] ?? null)) {
            $errors[] = 'started_at must be null or an ISO-8601 timestamp.';
        }
        if (! $this->isNullableTimestamp($record['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be null or an ISO-8601 timestamp.';
        }
        if ($status === 'running' && ! $this->isTimestamp($record['started_at'] ?? null)) {
            $errors[] = 'running state requires started_at.';
        }
        if (in_array($status, self::TERMINAL_STATUSES, true)
            && ! $this->isTimestamp($record['finished_at'] ?? null)) {
            $errors[] = 'terminal state requires finished_at.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function mediaResultErrors(array $record, string $phase, string $jobId): array
    {
        $errors = $this->identityErrors($record, $jobId.'--result', 'media_job_result', $jobId);
        if (($record['phase'] ?? null) !== $phase) {
            $errors[] = 'phase does not match its directory.';
        }
        if (! in_array($record['status'] ?? null, self::TERMINAL_STATUSES, true)) {
            $errors[] = 'result status must be terminal.';
        }
        if (($record['simulation'] ?? null) !== true) {
            $errors[] = 'simulation must be true.';
        }
        if (! is_int($record['attempt'] ?? null) || $record['attempt'] < 0) {
            $errors[] = 'attempt must be a non-negative integer.';
        }
        if (! $this->isTimestamp($record['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be an ISO-8601 timestamp.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function mediaEventErrors(array $record, string $jobId, string $filenameId): array
    {
        $errors = $this->identityErrors($record, $filenameId, 'media_job_event', $jobId);
        if (! in_array($record['event'] ?? null, [...self::STATUSES, 'cancellation_requested', 'terminal_recovered'], true)) {
            $errors[] = 'event is unsupported.';
        }
        if (! is_int($record['sequence'] ?? null) || $record['sequence'] < 1) {
            $errors[] = 'sequence must be a positive integer.';
        }
        if (! $this->isTimestamp($record['created_at'] ?? null)) {
            $errors[] = 'created_at must be an ISO-8601 timestamp.';
        }
        if (array_key_exists('status', $record) && ! in_array($record['status'], self::STATUSES, true)) {
            $errors[] = 'event status is unsupported.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function renderRequestErrors(array $record, string $project, string $run, string $renderId): array
    {
        $errors = $this->identityErrors($record, $renderId, 'render_request', $renderId, 'render_id');
        if (($record['project_id'] ?? null) !== $project) {
            $errors[] = 'project_id does not match its directory.';
        }
        if (($record['run_id'] ?? null) !== $run) {
            $errors[] = 'run_id does not match its directory.';
        }
        if (($record['simulation'] ?? null) !== true) {
            $errors[] = 'simulation must be true.';
        }
        if (! is_string($record['renderer'] ?? null) || trim($record['renderer']) === '') {
            $errors[] = 'renderer must be a non-empty string.';
        }
        if (! $this->isTimestamp($record['created_at'] ?? null)) {
            $errors[] = 'created_at must be an ISO-8601 timestamp.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function renderStateErrors(array $record, string $renderId): array
    {
        $errors = $this->identityErrors($record, $renderId.'--state', 'render_state', $renderId, 'render_id');
        $status = $record['status'] ?? null;
        if (! in_array($status, self::STATUSES, true)) {
            $errors[] = 'status is unsupported.';
        }
        if (! is_bool($record['cancel_requested'] ?? null)) {
            $errors[] = 'cancel_requested must be a boolean.';
        }
        foreach (['created_at', 'updated_at'] as $field) {
            if (! $this->isTimestamp($record[$field] ?? null)) {
                $errors[] = "{$field} must be an ISO-8601 timestamp.";
            }
        }
        if (! $this->isNullableTimestamp($record['started_at'] ?? null)) {
            $errors[] = 'started_at must be null or an ISO-8601 timestamp.';
        }
        if (! $this->isNullableTimestamp($record['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be null or an ISO-8601 timestamp.';
        }
        if ($status === 'running' && ! $this->isTimestamp($record['started_at'] ?? null)) {
            $errors[] = 'running state requires started_at.';
        }
        if (in_array($status, self::TERMINAL_STATUSES, true)
            && ! $this->isTimestamp($record['finished_at'] ?? null)) {
            $errors[] = 'terminal state requires finished_at.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function renderManifestErrors(array $record, string $renderId): array
    {
        $errors = $this->identityErrors($record, $renderId.'--result', 'render_result', $renderId, 'render_id');
        $status = $record['status'] ?? null;
        if (! in_array($status, self::TERMINAL_STATUSES, true)) {
            $errors[] = 'manifest status must be terminal.';
        }
        if (($record['simulation'] ?? null) !== true) {
            $errors[] = 'simulation must be true.';
        }
        if (! $this->isTimestamp($record['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be an ISO-8601 timestamp.';
        }
        if ($status === 'completed') {
            foreach (['renderer', 'sequence_artifact_id'] as $field) {
                if (! is_string($record[$field] ?? null) || trim($record[$field]) === '') {
                    $errors[] = "completed manifest requires {$field}.";
                }
            }
            $sceneIds = $record['scene_artifact_ids'] ?? null;
            $selectionIds = $record['scene_selection_ids'] ?? null;
            $assetHashes = $record['scene_asset_sha256'] ?? null;
            if (! $this->isNonEmptyStringList($sceneIds)) {
                $errors[] = 'completed manifest requires scene_artifact_ids.';
            }
            if (! $this->isNonEmptyStringList($selectionIds)
                || (is_array($sceneIds) && count($selectionIds) !== count($sceneIds))) {
                $errors[] = 'completed manifest requires one scene_selection_id per scene.';
            }
            if (! is_array($assetHashes)
                || array_is_list($assetHashes)
                || ! is_array($sceneIds)
                || collect($sceneIds)->contains(fn (mixed $id): bool => ! is_string($id)
                    || ! is_string($assetHashes[$id] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/', $assetHashes[$id]) !== 1)) {
                $errors[] = 'completed manifest requires one valid scene asset SHA-256 per scene.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function identityErrors(
        array $record,
        string $expectedId,
        string $expectedType,
        string $expectedOwnerId,
        string $ownerField = 'job_id',
    ): array {
        $errors = [];
        if (($record['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }
        if (($record['id'] ?? null) !== $expectedId) {
            $errors[] = 'id does not match its filename or directory.';
        }
        if (($record['type'] ?? null) !== $expectedType) {
            $errors[] = "type must be {$expectedType}.";
        }
        if (($record[$ownerField] ?? null) !== $expectedOwnerId) {
            $errors[] = "{$ownerField} does not match its directory.";
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function verifyFileIdentity(
        string $project,
        string $run,
        ?string $phase,
        string $entity,
        string $recordPath,
        string $file,
        mixed $expectedHash,
        mixed $expectedBytes,
        string $codePrefix,
        array &$issues,
        bool $verifyBytes = true,
    ): void {
        if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            $this->issue(
                $issues,
                'invalid_'.$codePrefix.'_identity',
                $project,
                $run,
                $phase,
                $recordPath,
                $entity,
                'Completed output has no valid lowercase SHA-256 identity.',
                'Restore the immutable record from the exact output bytes or create a new operation.',
            );
        } else {
            $actualHash = hash_file('sha256', $file);
            if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                $this->issue(
                    $issues,
                    $codePrefix.'_hash_mismatch',
                    $project,
                    $run,
                    $phase,
                    $recordPath,
                    $entity,
                    'Completed output bytes no longer match the recorded SHA-256.',
                    'Restore the exact immutable bytes or create a new operation.',
                );
            }
        }

        if (! $verifyBytes) {
            return;
        }
        if (! is_int($expectedBytes) || $expectedBytes < 1) {
            $this->issue(
                $issues,
                'invalid_'.$codePrefix.'_identity',
                $project,
                $run,
                $phase,
                $recordPath,
                $entity,
                'Completed output has no positive integer byte count.',
                'Restore the immutable record from the exact output bytes or create a new operation.',
            );

            return;
        }
        if (File::size($file) !== $expectedBytes) {
            $this->issue(
                $issues,
                $codePrefix.'_size_mismatch',
                $project,
                $run,
                $phase,
                $recordPath,
                $entity,
                'Completed output byte size no longer matches its record.',
                'Restore the exact immutable bytes or create a new operation.',
            );
        }
    }

    /** @return array{path: string|null, error: 'unsafe'|'missing'|null} */
    private function containedFile(string $base, string $runPath, string $relative): array
    {
        if (! $this->safeRelativePath($relative)) {
            return ['path' => null, 'error' => 'unsafe'];
        }

        $realRun = realpath($runPath);
        $candidate = rtrim($base, '/').'/'.$relative;
        if (! File::isFile($candidate)) {
            return ['path' => null, 'error' => 'missing'];
        }

        $real = realpath($candidate);
        if ($realRun === false
            || $real === false
            || ! str_starts_with($real, $realRun.DIRECTORY_SEPARATOR)) {
            return ['path' => null, 'error' => 'unsafe'];
        }

        return ['path' => $real, 'error' => null];
    }

    /** @return array<string, array<string, mixed>> */
    private function mediaArtifacts(string $project, string $run, string $phase): array
    {
        $artifacts = [];
        $directory = $this->paths->outputsPath($project, $run, $phase);
        if (! File::isDirectory($directory)) {
            return [];
        }
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            try {
                $metadata = MarkdownDocument::read($file->getPathname())['metadata'];
            } catch (Throwable) {
                continue;
            }
            if (is_string($metadata['id'] ?? null)) {
                $artifacts[$metadata['id']] = $metadata;
            }
        }

        return $artifacts;
    }

    private function safeRelativePath(string $path): bool
    {
        $segments = explode('/', $path);

        return $path !== ''
            && ! str_contains($path, "\0")
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) !== 1
            && ! collect($segments)->contains(fn (string $segment): bool => in_array($segment, ['', '.', '..'], true));
    }

    private function isTimestamp(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isNullableTimestamp(mixed $value): bool
    {
        return $value === null || $this->isTimestamp($value);
    }

    private function isNonEmptyStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && $value !== []
            && collect($value)->every(fn (mixed $item): bool => is_string($item) && trim($item) !== '');
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $errors
     */
    private function invalidRecordIssue(
        array &$issues,
        array $errors,
        string $code,
        string $project,
        string $run,
        ?string $phase,
        string $path,
        string $entity,
    ): void {
        if ($errors === []) {
            return;
        }

        $this->issue(
            $issues,
            $code,
            $project,
            $run,
            $phase,
            $path,
            $entity,
            implode(' ', array_values(array_unique($errors))),
            'Preserve the record and recover with a new operation rather than rewriting history.',
        );
    }

    /** @param list<array<string, mixed>> $issues */
    private function issue(
        array &$issues,
        string $code,
        string $project,
        string $run,
        ?string $phase,
        string $path,
        string $entity,
        string $message,
        string $remediation,
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
            'remediation' => $remediation,
        ];
    }
}
