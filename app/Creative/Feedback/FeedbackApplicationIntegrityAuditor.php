<?php

namespace App\Creative\Feedback;

use App\Creative\CreativePathResolver;
use App\Creative\CreativePhaseCatalog;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class FeedbackApplicationIntegrityAuditor
{
    /** @var list<string> */
    private const APPLIED_STATUSES = ['applied', 'applied_snapshot_current_preserved'];

    /** @var list<string> */
    private const RESULT_STATUSES = [...self::APPLIED_STATUSES, 'not_applied'];

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativePhaseCatalog $phases,
    ) {}

    /** @return list<array<string, mixed>> */
    public function audit(string $project, string $run): array
    {
        $issues = [];
        $root = $this->paths->feedbackApplicationsPath($project, $run);
        $applicationPaths = File::isDirectory($root) ? File::directories($root) : [];

        foreach ($applicationPaths as $applicationPath) {
            $attemptId = basename($applicationPath);
            if (! $this->safeIdentifier($attemptId)) {
                $this->issue(
                    $issues,
                    'invalid_feedback_application_directory',
                    $project,
                    $run,
                    null,
                    $applicationPath,
                    $attemptId,
                    'The feedback application directory is not a valid immutable attempt ID.',
                    'Preserve and quarantine the directory; do not promote its records.',
                );
            }

            $requestPath = $applicationPath.'/request.md';
            $resultPath = $applicationPath.'/result.md';
            $request = $this->document(
                $requestPath,
                'feedback_application_request_missing',
                'malformed_feedback_application_request',
                $project,
                $run,
                null,
                $attemptId,
                $issues,
            );
            $result = File::isFile($resultPath)
                ? $this->document(
                    $resultPath,
                    'feedback_application_result_missing',
                    'malformed_feedback_application_result',
                    $project,
                    $run,
                    null,
                    $attemptId,
                    $issues,
                )
                : null;

            $requestPhase = is_array($request) && $this->validPhase($request['phase'] ?? null)
                ? $request['phase']
                : null;
            $resultPhase = is_array($result) && $this->validPhase($result['phase'] ?? null)
                ? $result['phase']
                : null;
            $phase = is_string($requestPhase) ? $requestPhase : $resultPhase;

            if (is_array($request)) {
                $this->invalidRecordIssue(
                    $issues,
                    $this->requestErrors($request, $project, $run, $attemptId),
                    'invalid_feedback_application_request',
                    $project,
                    $run,
                    $phase,
                    $requestPath,
                    $attemptId,
                );
            }
            if (is_array($result)) {
                $this->invalidRecordIssue(
                    $issues,
                    $this->resultErrors($result, $attemptId),
                    'invalid_feedback_application_result',
                    $project,
                    $run,
                    $phase,
                    $resultPath,
                    $attemptId,
                );
            }

            if (is_array($request) && is_array($result)
                && ($request['phase'] ?? null) !== ($result['phase'] ?? null)) {
                $this->issue(
                    $issues,
                    'feedback_application_phase_mismatch',
                    $project,
                    $run,
                    $phase,
                    $resultPath,
                    $attemptId,
                    'The feedback application request and result name different phases.',
                    'Keep the application ineligible and create a new coherent application record.',
                );
            }

            if (is_array($request) && is_array($result)) {
                $this->auditPreservedScopes(
                    $project,
                    $run,
                    $phase,
                    $attemptId,
                    $resultPath,
                    $request,
                    $result,
                    $issues,
                );
            }

            if ($result === null && ! File::isFile($resultPath)) {
                $this->issue(
                    $issues,
                    'incomplete_feedback_application',
                    $project,
                    $run,
                    $phase,
                    $applicationPath,
                    $attemptId,
                    'The reserved feedback application has no terminal result.',
                    'Resume recovery or append a truthful applied or not_applied terminal result.',
                );

                continue;
            }

            if (! is_array($request) || ! is_array($result) || ! is_string($phase)) {
                continue;
            }

            $attempt = $this->attemptDocuments($project, $run, $phase, $attemptId);
            $this->auditHashes(
                $project,
                $run,
                $phase,
                $attemptId,
                $requestPath,
                $resultPath,
                $request,
                $result,
                $attempt['request'],
                $issues,
            );

            $status = $result['status'] ?? null;
            if (is_string($status) && in_array($status, self::APPLIED_STATUSES, true)) {
                $this->auditApplied(
                    $project,
                    $run,
                    $phase,
                    $attemptId,
                    $resultPath,
                    $request,
                    $result,
                    $attempt,
                    $issues,
                );
            } elseif ($status === 'not_applied') {
                $this->auditNotApplied(
                    $project,
                    $run,
                    $phase,
                    $attemptId,
                    $resultPath,
                    $attempt,
                    $issues,
                );
            }
        }

        $this->auditOrphanedAppliedHistory($project, $run, $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    private function requestErrors(array $request, string $project, string $run, string $attemptId): array
    {
        $errors = $this->identityErrors(
            $request,
            $attemptId.'--feedback-application',
            'feedback_application_request',
            $attemptId,
        );
        $phase = $request['phase'] ?? null;
        if (! $this->validPhase($phase)) {
            $errors[] = 'phase is not a configured creative phase.';
        }
        if (($request['status'] ?? null) !== 'reserved') {
            $errors[] = 'request status must be reserved.';
        }
        if (! $this->hash($request['generation_feedback_sha256'] ?? null)) {
            $errors[] = 'generation_feedback_sha256 must be a lowercase SHA-256.';
        }
        if (! $this->timestamp($request['created_at'] ?? null)) {
            $errors[] = 'created_at must be an ISO-8601 timestamp.';
        }

        $sources = $request['sources'] ?? null;
        if (! is_array($sources) || ! array_is_list($sources) || $sources === []) {
            $errors[] = 'sources must be a non-empty list.';

            return $errors;
        }

        $seenScopes = [];
        foreach ($sources as $index => $source) {
            if (! is_array($source) || array_is_list($source)) {
                $errors[] = "sources[{$index}] must be an object.";

                continue;
            }
            $scope = $source['scope'] ?? null;
            if (! is_string($scope) || ! in_array($scope, ['phase', 'cycle'], true)) {
                $errors[] = "sources[{$index}].scope must be phase or cycle.";
            } elseif (in_array($scope, $seenScopes, true)) {
                $errors[] = "sources[{$index}].scope is duplicated.";
            } else {
                $seenScopes[] = $scope;
            }

            $body = $source['pending_body'] ?? null;
            if (! is_string($body) || trim($body) === '') {
                $errors[] = "sources[{$index}].pending_body must be non-empty.";
            }
            if (! $this->hash($source['pending_sha256'] ?? null)) {
                $errors[] = "sources[{$index}].pending_sha256 must be a lowercase SHA-256.";
            }

            if (is_string($scope) && in_array($scope, ['phase', 'cycle'], true) && is_string($phase)) {
                $expectedPath = $scope === 'cycle'
                    ? $this->relative($this->paths->cycleFeedbackPath($project, $run))
                    : ($this->validPhase($phase)
                        ? $this->relative($this->paths->currentFeedbackPath($project, $run, $phase))
                        : null);
                if (! is_string($expectedPath) || ($source['path'] ?? null) !== $expectedPath) {
                    $errors[] = "sources[{$index}].path does not identify the expected feedback inbox.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function resultErrors(array $result, string $attemptId): array
    {
        $errors = $this->identityErrors(
            $result,
            $attemptId.'--feedback-application-result',
            'feedback_application_result',
            $attemptId,
        );
        if (! $this->validPhase($result['phase'] ?? null)) {
            $errors[] = 'phase is not a configured creative phase.';
        }
        $status = $result['status'] ?? null;
        if (! in_array($status, self::RESULT_STATUSES, true)) {
            $errors[] = 'status must be applied, applied_snapshot_current_preserved, or not_applied.';
        }
        if (! $this->timestamp($result['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be an ISO-8601 timestamp.';
        }
        if (! $this->referenceList($result['implementation_references'] ?? null)) {
            $errors[] = 'implementation_references must be a list of valid target references.';
        }

        if (is_string($status) && in_array($status, self::APPLIED_STATUSES, true)) {
            if (! $this->nonEmptyUniqueStringList($result['artifact_ids'] ?? null)) {
                $errors[] = 'applied result artifact_ids must be a non-empty unique list.';
            }
            if (! $this->nonEmptyUniqueStringList($result['history_ids'] ?? null)) {
                $errors[] = 'applied result history_ids must be a non-empty unique list.';
            }
            if (! $this->hash($result['generation_feedback_sha256'] ?? null)) {
                $errors[] = 'applied result generation_feedback_sha256 must be a lowercase SHA-256.';
            }
            $preserved = $result['preserved_current_scopes'] ?? null;
            if (! $this->uniqueStringList($preserved) || ! $this->validPreservedScopes($preserved)) {
                $errors[] = 'preserved_current_scopes must be a unique list containing only phase or cycle.';
            } elseif ($status === 'applied' && $preserved !== []) {
                $errors[] = 'applied status cannot preserve a current scope.';
            } elseif ($status === 'applied_snapshot_current_preserved' && $preserved === []) {
                $errors[] = 'applied_snapshot_current_preserved requires at least one preserved scope.';
            }
        } elseif ($status === 'not_applied') {
            if (($result['implementation_references'] ?? null) !== []) {
                $errors[] = 'not_applied must not claim implementation references.';
            }
            if (! is_string($result['error'] ?? null) || trim($result['error']) === '') {
                $errors[] = 'not_applied requires a non-empty error.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $attemptRequest
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditHashes(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $requestPath,
        string $resultPath,
        array $request,
        array $result,
        ?array $attemptRequest,
        array &$issues,
    ): void {
        $requestHash = $request['generation_feedback_sha256'] ?? null;
        foreach (is_array($request['sources'] ?? null) ? $request['sources'] : [] as $source) {
            if (! is_array($source)
                || ! is_string($source['pending_body'] ?? null)
                || ! $this->hash($source['pending_sha256'] ?? null)) {
                continue;
            }
            if (! hash_equals($source['pending_sha256'], hash('sha256', $source['pending_body']))) {
                $this->issue(
                    $issues,
                    'feedback_application_source_hash_mismatch',
                    $project,
                    $run,
                    $phase,
                    $requestPath,
                    $attemptId,
                    'A reserved feedback source body no longer matches its SHA-256.',
                    'Preserve the corrupted record and start a new generation attempt from the current inbox.',
                );
            }
        }

        $attemptHash = is_array($attemptRequest) && $this->hash($attemptRequest['generation_feedback_sha256'] ?? null)
            ? $attemptRequest['generation_feedback_sha256']
            : null;
        $reconstructedHash = $this->reconstructedFeedbackHash($request, $attemptRequest);
        if ($this->hash($requestHash)
            && ((is_string($attemptHash) && ! hash_equals($attemptHash, $requestHash))
                || (is_string($reconstructedHash) && ! hash_equals($reconstructedHash, $requestHash)))) {
            $this->issue(
                $issues,
                'feedback_application_generation_hash_mismatch',
                $project,
                $run,
                $phase,
                $requestPath,
                $attemptId,
                'The reserved generation feedback hash does not match its exact source or generation attempt.',
                'Treat the application as ineligible and create a new attempt from trustworthy feedback.',
            );
        }

        if (in_array($result['status'] ?? null, self::APPLIED_STATUSES, true)
            && $this->hash($requestHash)
            && $this->hash($result['generation_feedback_sha256'] ?? null)
            && ! hash_equals($requestHash, $result['generation_feedback_sha256'])) {
            $this->issue(
                $issues,
                'feedback_application_result_hash_mismatch',
                $project,
                $run,
                $phase,
                $resultPath,
                $attemptId,
                'The applied result names a different generation feedback hash than its request.',
                'Do not treat the feedback as applied; generate a new coherent attempt.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $result
     * @param  array{request: array<string, mixed>|null, result: array<string, mixed>|null, request_path: string, result_path: string}  $attempt
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditApplied(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $resultPath,
        array $request,
        array $result,
        array $attempt,
        array &$issues,
    ): void {
        if (! is_array($attempt['request']) || ! is_array($attempt['result'])) {
            $this->issue(
                $issues,
                'feedback_application_attempt_missing',
                $project,
                $run,
                $phase,
                $resultPath,
                $attemptId,
                'Applied feedback does not resolve to a readable generation attempt request and result.',
                'Restore the exact attempt records or stop treating this feedback application as proven.',
            );
        } elseif (($attempt['result']['status'] ?? null) !== 'committed') {
            $this->issue(
                $issues,
                'feedback_application_attempt_not_committed',
                $project,
                $run,
                $phase,
                $attempt['result_path'],
                $attemptId,
                'Applied feedback points to a generation attempt that did not commit.',
                'Retain the terminal attempt truth and create a new application from the still-pending feedback.',
            );
        }

        $artifactIds = $this->stringList($result['artifact_ids'] ?? null);
        $historyIds = $this->stringList($result['history_ids'] ?? null);
        $feedbackHash = is_string($result['generation_feedback_sha256'] ?? null)
            ? $result['generation_feedback_sha256']
            : '';
        $expectedReferences = $this->expectedReferences($phase, $attemptId, $artifactIds, $feedbackHash);
        if (($result['implementation_references'] ?? null) != $expectedReferences) {
            $this->issue(
                $issues,
                'feedback_application_references_mismatch',
                $project,
                $run,
                $phase,
                $resultPath,
                $attemptId,
                'Applied feedback does not contain the exact generation-attempt and artifact references.',
                'Do not rewrite history; create a new application record with complete immutable references.',
            );
        }

        $attemptArtifacts = is_array($attempt['result'])
            ? $this->stringList($attempt['result']['artifact_ids'] ?? null)
            : [];
        if (is_array($attempt['result']) && $attemptArtifacts !== $artifactIds) {
            $this->issue(
                $issues,
                'feedback_application_artifact_manifest_mismatch',
                $project,
                $run,
                $phase,
                $attempt['result_path'],
                $attemptId,
                'The applied artifact IDs do not exactly match the committed attempt batch.',
                'Treat the application as unproven and regenerate a coherent immutable batch.',
            );
        }

        $this->auditAppliedArtifacts(
            $project,
            $run,
            $phase,
            $attemptId,
            $resultPath,
            $artifactIds,
            $attempt['result'],
            $issues,
        );
        $this->auditAppliedHistory(
            $project,
            $run,
            $phase,
            $attemptId,
            $resultPath,
            $request,
            $historyIds,
            $expectedReferences,
            $feedbackHash,
            $issues,
        );
    }

    /**
     * @param  list<string>  $artifactIds
     * @param  array<string, mixed>|null  $attemptResult
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAppliedArtifacts(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $resultPath,
        array $artifactIds,
        ?array $attemptResult,
        array &$issues,
    ): void {
        $hashes = is_array($attemptResult['artifact_sha256'] ?? null)
            ? $attemptResult['artifact_sha256']
            : [];
        foreach ($artifactIds as $artifactId) {
            if (! $this->safeIdentifier($artifactId)) {
                continue;
            }
            $path = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
            if (! File::isFile($path)) {
                $this->issue(
                    $issues,
                    'feedback_application_artifact_missing',
                    $project,
                    $run,
                    $phase,
                    $resultPath,
                    $attemptId,
                    "Applied feedback artifact [{$artifactId}] is missing.",
                    'Restore the exact immutable artifact or stop treating the application as proven.',
                );

                continue;
            }

            try {
                $artifact = MarkdownDocument::read($path)['metadata'];
            } catch (Throwable) {
                $this->issue(
                    $issues,
                    'feedback_application_artifact_malformed',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback artifact [{$artifactId}] cannot be parsed.",
                    'Preserve the bytes for investigation and generate a new immutable artifact.',
                );

                continue;
            }
            if (($artifact['id'] ?? null) !== $artifactId
                || ($artifact['phase'] ?? null) !== $phase
                || ($artifact['generation_attempt_id'] ?? null) !== $attemptId) {
                $this->issue(
                    $issues,
                    'feedback_application_artifact_identity_mismatch',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback artifact [{$artifactId}] does not belong to the referenced attempt and phase.",
                    'Keep the application ineligible and generate a coherent replacement batch.',
                );
            }

            $expectedHash = $hashes[$artifactId] ?? null;
            if (! $this->hash($expectedHash)) {
                $this->issue(
                    $issues,
                    'feedback_application_artifact_hash_invalid',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback artifact [{$artifactId}] has no valid committed SHA-256.",
                    'Recover the immutable attempt manifest or generate a new attempt.',
                );
            } else {
                $actualHash = hash_file('sha256', $path);
                if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                    $this->issue(
                        $issues,
                        'feedback_application_artifact_hash_mismatch',
                        $project,
                        $run,
                        $phase,
                        $path,
                        $attemptId,
                        "Applied feedback artifact [{$artifactId}] no longer matches the committed SHA-256.",
                        'Restore the exact immutable bytes or create a new attempt and application.',
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  list<string>  $historyIds
     * @param  list<array<string, string>>  $expectedReferences
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAppliedHistory(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $resultPath,
        array $request,
        array $historyIds,
        array $expectedReferences,
        string $feedbackHash,
        array &$issues,
    ): void {
        $sources = is_array($request['sources'] ?? null) && array_is_list($request['sources'])
            ? $request['sources']
            : [];
        if (count($historyIds) !== count($sources)) {
            $this->issue(
                $issues,
                'feedback_application_history_manifest_mismatch',
                $project,
                $run,
                $phase,
                $resultPath,
                $attemptId,
                'Applied feedback does not name exactly one history snapshot per reserved source.',
                'Treat the application as unproven and create a new coherent application.',
            );
        }

        foreach ($historyIds as $index => $historyId) {
            if (! $this->safeIdentifier($historyId)) {
                continue;
            }
            $path = $this->paths->feedbackHistoryPath($project, $run).'/'.$historyId.'.md';
            if (! File::isFile($path)) {
                $this->issue(
                    $issues,
                    'feedback_application_history_missing',
                    $project,
                    $run,
                    $phase,
                    $resultPath,
                    $attemptId,
                    "Applied feedback history [{$historyId}] is missing.",
                    'Restore the exact immutable snapshot or stop treating the application as proven.',
                );

                continue;
            }

            try {
                $history = MarkdownDocument::read($path);
            } catch (Throwable) {
                $this->issue(
                    $issues,
                    'feedback_application_history_malformed',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback history [{$historyId}] cannot be parsed.",
                    'Preserve the bytes and stop treating this application as proven.',
                );

                continue;
            }

            $metadata = $history['metadata'];
            $source = is_array($sources[$index] ?? null) ? $sources[$index] : [];
            if (($metadata['schema_version'] ?? null) !== 1
                || ($metadata['id'] ?? null) !== $historyId
                || ($metadata['type'] ?? null) !== 'feedback_history'
                || ($metadata['status'] ?? null) !== 'applied'
                || ($metadata['phase'] ?? null) !== $phase
                || ($metadata['feedback_application_id'] ?? null) !== $attemptId.'--feedback-application'
                || ($metadata['generation_attempt_id'] ?? null) !== $attemptId
                || ($metadata['scope'] ?? null) !== ($source['scope'] ?? null)
                || ($metadata['feedback_sha256'] ?? null) !== ($source['pending_sha256'] ?? null)
                || ($metadata['generation_feedback_sha256'] ?? null) !== $feedbackHash
                || ! $this->timestamp($metadata['applied_at'] ?? null)
                || ! $this->timestamp($metadata['archived_at'] ?? null)) {
                $this->issue(
                    $issues,
                    'feedback_application_history_identity_mismatch',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback history [{$historyId}] does not match its request, phase, source, or hash.",
                    'Keep the application ineligible and preserve all records for investigation.',
                );
            }
            if (($metadata['implementation_references'] ?? null) != $expectedReferences) {
                $this->issue(
                    $issues,
                    'feedback_history_references_mismatch',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback history [{$historyId}] lacks the exact implementation references.",
                    'Do not rewrite immutable history; create a new truthful application and snapshot.',
                );
            }

            $snapshot = $source['pending_body'] ?? null;
            if (is_string($snapshot)
                && $history['body'] !== $this->expectedHistoryBody($snapshot, $expectedReferences)) {
                $this->issue(
                    $issues,
                    'feedback_application_history_snapshot_mismatch',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $attemptId,
                    "Applied feedback history [{$historyId}] does not contain the exact reserved snapshot.",
                    'Stop treating the application as proof and create a new immutable snapshot.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditPreservedScopes(
        string $project,
        string $run,
        ?string $phase,
        string $attemptId,
        string $resultPath,
        array $request,
        array $result,
        array &$issues,
    ): void {
        if (! in_array($result['status'] ?? null, self::APPLIED_STATUSES, true)) {
            return;
        }

        $reservedScopes = collect(is_array($request['sources'] ?? null) ? $request['sources'] : [])
            ->map(fn (mixed $source): mixed => is_array($source) ? ($source['scope'] ?? null) : null)
            ->filter(fn (mixed $scope): bool => is_string($scope))
            ->values()
            ->all();
        $preservedScopes = $result['preserved_current_scopes'] ?? null;
        if (is_array($preservedScopes)
            && collect($preservedScopes)->contains(
                fn (mixed $scope): bool => ! is_string($scope) || ! in_array($scope, $reservedScopes, true),
            )) {
            $this->issue(
                $issues,
                'feedback_application_preserved_scope_mismatch',
                $project,
                $run,
                $phase,
                $resultPath,
                $attemptId,
                'The result claims that an inbox scope was preserved even though that scope was not reserved.',
                'Keep the application ineligible and create a coherent lifecycle record from the current inboxes.',
            );
        }
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditOrphanedAppliedHistory(string $project, string $run, array &$issues): void
    {
        $historyRoot = $this->paths->feedbackHistoryPath($project, $run);
        if (! File::isDirectory($historyRoot)) {
            return;
        }

        foreach (File::files($historyRoot) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            try {
                $metadata = MarkdownDocument::read($file->getPathname())['metadata'];
            } catch (Throwable) {
                continue;
            }
            $applicationId = $metadata['feedback_application_id'] ?? null;
            if (($metadata['type'] ?? null) !== 'feedback_history'
                || ($metadata['status'] ?? null) !== 'applied'
                || ! is_string($applicationId)
                || ! str_ends_with($applicationId, '--feedback-application')) {
                continue;
            }

            $attemptId = substr($applicationId, 0, -strlen('--feedback-application'));
            if (! $this->safeIdentifier($attemptId)) {
                continue;
            }
            $resultPath = $this->paths->feedbackApplicationsPath($project, $run).'/'.$attemptId.'/result.md';
            $result = $this->readMetadata($resultPath);
            $historyIds = is_array($result) ? $this->stringList($result['history_ids'] ?? null) : [];
            if (is_array($result)
                && in_array($result['status'] ?? null, self::APPLIED_STATUSES, true)
                && in_array($file->getBasename('.md'), $historyIds, true)) {
                continue;
            }

            $this->issue(
                $issues,
                'orphaned_applied_feedback_history',
                $project,
                $run,
                is_string($metadata['phase'] ?? null) ? $metadata['phase'] : null,
                $file->getPathname(),
                $attemptId,
                "Applied feedback history [{$file->getBasename('.md')}] is not named by a readable applied terminal result.",
                'Preserve the snapshot for recovery and append or restore the truthful terminal application result.',
            );
        }
    }

    /**
     * @param  array{request: array<string, mixed>|null, result: array<string, mixed>|null, request_path: string, result_path: string}  $attempt
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditNotApplied(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $resultPath,
        array $attempt,
        array &$issues,
    ): void {
        if (! is_array($attempt['request']) || ! is_array($attempt['result'])) {
            $this->issue(
                $issues,
                'feedback_application_attempt_missing',
                $project,
                $run,
                $phase,
                $resultPath,
                $attemptId,
                'A not_applied result has no complete, readable generation attempt to substantiate it.',
                'Restore the truthful attempt request and terminal result or keep this lifecycle record ineligible.',
            );
        }

        if (! is_array($attempt['result'])) {
            return;
        }

        $status = $attempt['result']['status'] ?? null;
        if ($status === 'committed') {
            $this->issue(
                $issues,
                'feedback_not_applied_committed_attempt',
                $project,
                $run,
                $phase,
                $attempt['result_path'],
                $attemptId,
                'Feedback is marked not_applied even though its generation attempt committed artifacts.',
                'Investigate the contradictory terminal records; do not silently archive or discard the feedback.',
            );

            return;
        }

        if (! is_string($status) || ! in_array($status, ['failed', 'cancelled', 'interrupted'], true)) {
            $this->issue(
                $issues,
                'feedback_not_applied_non_terminal_attempt',
                $project,
                $run,
                $phase,
                $attempt['result_path'],
                $attemptId,
                'Feedback is marked not_applied without a failed, cancelled, or interrupted generation attempt.',
                'Recover the generation attempt to a truthful terminal status before relying on this lifecycle result.',
            );
        }
    }

    /**
     * @return array{request: array<string, mixed>|null, result: array<string, mixed>|null, request_path: string, result_path: string}
     */
    private function attemptDocuments(string $project, string $run, string $phase, string $attemptId): array
    {
        $root = dirname($this->paths->outputsPath($project, $run, $phase)).'/attempts/'.$attemptId;
        $requestPath = $root.'/request.md';
        $resultPath = $root.'/result.md';

        return [
            'request' => $this->readMetadata($requestPath),
            'result' => $this->readMetadata($resultPath),
            'request_path' => $requestPath,
            'result_path' => $resultPath,
        ];
    }

    /** @return array<string, mixed>|null */
    private function readMetadata(string $path): ?array
    {
        if (! File::isFile($path)) {
            return null;
        }

        try {
            return MarkdownDocument::read($path)['metadata'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $attemptRequest
     */
    private function reconstructedFeedbackHash(array $request, ?array $attemptRequest): ?string
    {
        $parts = [];
        $sources = $request['sources'] ?? null;
        if (! is_array($sources) || ! array_is_list($sources) || $sources === []) {
            return null;
        }
        foreach ($sources as $source) {
            if (! is_array($source)
                || ! is_string($source['scope'] ?? null)
                || ! in_array($source['scope'], ['phase', 'cycle'], true)
                || ! is_string($source['pending_body'] ?? null)) {
                return null;
            }
            $label = $source['scope'] === 'phase' ? 'Phase feedback' : 'Whole-run feedback';
            $parts[] = "# {$label}\n\n".$source['pending_body'];
        }

        $feedback = implode("\n\n", $parts);
        if (is_array($attemptRequest)
            && (($attemptRequest['retry_context_hash'] ?? null) !== null
                || ($attemptRequest['retry_decision_event_id'] ?? null) !== null)) {
            $diagnosis = trim((string) ($attemptRequest['prior_evaluation_diagnosis']
                ?? 'A person requested a bounded retry with explicit feedback.'));
            $priorEvaluationId = is_string($attemptRequest['prior_evaluation_id'] ?? null)
                ? $attemptRequest['prior_evaluation_id']
                : 'none';
            $marker = "[automatic-retry-context]\nPrior evaluation: {$priorEvaluationId}\nDiagnosis: {$diagnosis}";
            $feedback = trim($feedback) === '' ? $marker : trim($feedback)."\n\n{$marker}";
        }

        return hash('sha256', $feedback);
    }

    /** @param list<array<string, string>> $references */
    private function expectedHistoryBody(string $snapshot, array $references): string
    {
        $referenceBody = collect($references)->map(
            fn (array $reference): string => '- '.Str::headline($reference['type'])." `{$reference['id']}` at `{$reference['path']}`",
        )->implode("\n");

        return "# Applied feedback snapshot\n\n{$snapshot}\n\n## Implementation references\n\n{$referenceBody}\n\n## Lifecycle\n\nThis exact snapshot was supplied to the committed generation attempt above. Feedback added concurrently remains in the current inbox.";
    }

    /**
     * @param  list<string>  $artifactIds
     * @return list<array<string, string>>
     */
    private function expectedReferences(
        string $phase,
        string $attemptId,
        array $artifactIds,
        string $feedbackHash,
    ): array {
        $references = [[
            'type' => 'generation_attempt',
            'id' => $attemptId,
            'path' => $this->phases->directory($phase)."/attempts/{$attemptId}",
            'generation_feedback_sha256' => $feedbackHash,
        ]];
        foreach ($artifactIds as $artifactId) {
            $references[] = [
                'type' => 'artifact',
                'id' => $artifactId,
                'path' => $this->phases->directory($phase)."/outputs/{$artifactId}.md",
                'applied_feedback_sha256' => $feedbackHash,
            ];
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function identityErrors(
        array $record,
        string $expectedId,
        string $expectedType,
        string $attemptId,
    ): array {
        $errors = [];
        if (($record['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }
        if (($record['id'] ?? null) !== $expectedId) {
            $errors[] = 'id does not match the application directory.';
        }
        if (($record['type'] ?? null) !== $expectedType) {
            $errors[] = "type must be {$expectedType}.";
        }
        if (($record['attempt_id'] ?? null) !== $attemptId) {
            $errors[] = 'attempt_id does not match the application directory.';
        }

        return $errors;
    }

    private function referenceList(mixed $references): bool
    {
        if (! is_array($references) || ! array_is_list($references)) {
            return false;
        }

        return collect($references)->every(function (mixed $reference): bool {
            if (! is_array($reference)
                || array_is_list($reference)
                || ! in_array($reference['type'] ?? null, ['generation_attempt', 'artifact'], true)
                || ! is_string($reference['id'] ?? null)
                || ! $this->safeIdentifier($reference['id'])
                || ! is_string($reference['path'] ?? null)
                || ! $this->safeRelativePath($reference['path'])) {
                return false;
            }

            return ($reference['type'] === 'generation_attempt'
                    && $this->hash($reference['generation_feedback_sha256'] ?? null)
                    && ! array_key_exists('applied_feedback_sha256', $reference))
                || ($reference['type'] === 'artifact'
                    && $this->hash($reference['applied_feedback_sha256'] ?? null)
                    && ! array_key_exists('generation_feedback_sha256', $reference));
        });
    }

    private function validPreservedScopes(mixed $scopes): bool
    {
        if (! is_array($scopes) || ! array_is_list($scopes)) {
            return false;
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, ['phase', 'cycle'], true)) {
                return false;
            }
        }

        return true;
    }

    private function validPhase(mixed $phase): bool
    {
        return is_string($phase) && array_key_exists($phase, $this->phases->all());
    }

    private function hash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }

    private function timestamp(mixed $value): bool
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

    private function safeIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*(?:--[a-z0-9-]+)+$/', $identifier) === 1;
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

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            fn (mixed $item): bool => is_string($item) && $this->safeIdentifier($item),
        ));
    }

    private function uniqueStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && collect($value)->every(fn (mixed $item): bool => is_string($item) && $this->safeIdentifier($item))
            && count($value) === count(array_unique($value));
    }

    private function nonEmptyUniqueStringList(mixed $value): bool
    {
        return $this->uniqueStringList($value) && $value !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>|null
     */
    private function document(
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
                'A required feedback application record is missing.',
                'Restore the immutable record or keep the application ineligible.',
            );

            return null;
        }

        try {
            return MarkdownDocument::read($path)['metadata'];
        } catch (Throwable) {
            $this->issue(
                $issues,
                $malformedCode,
                $project,
                $run,
                $phase,
                $path,
                $entity,
                'A feedback application record cannot be parsed.',
                'Preserve the malformed bytes and create a new immutable lifecycle record.',
            );

            return null;
        }
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
            'Preserve the record and create a new coherent application rather than rewriting history.',
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
            'path' => $this->relative($path),
            'entity' => $entity,
            'message' => $message,
            'remediation' => $remediation,
        ];
    }

    private function relative(string $path): string
    {
        return Str::after($path, $this->paths->root().'/');
    }
}
