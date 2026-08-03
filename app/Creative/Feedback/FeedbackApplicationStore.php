<?php

namespace App\Creative\Feedback;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePathResolver;
use App\Creative\CreativePhaseCatalog;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FeedbackApplicationStore
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativeLockManager $locks,
        private readonly CreativePhaseCatalog $phases,
    ) {}

    public function reserve(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $generationFeedback,
    ): bool {
        $this->assertAttemptId($attemptId);
        $requestPath = $this->requestPath($project, $run, $attemptId);

        return $this->locks->exclusive(
            "feedback-application:{$requestPath}",
            function () use ($project, $run, $phase, $attemptId, $generationFeedback, $requestPath): bool {
                if (File::isFile($requestPath)) {
                    $existing = $this->readMetadata($requestPath, 'Feedback application request');

                    return ($existing['attempt_id'] ?? null) === $attemptId
                        && ($existing['phase'] ?? null) === $phase;
                }

                return $this->withSourceLocks($project, $run, $phase, function () use ($project, $run, $phase, $attemptId, $generationFeedback, $requestPath): bool {
                    $sources = $this->sourceSnapshots($project, $run, $phase);
                    if ($sources === []) {
                        return false;
                    }

                    $createdAt = $this->timestamp();
                    MarkdownDocument::write($requestPath, [
                        'schema_version' => 1,
                        'id' => $attemptId.'--feedback-application',
                        'type' => 'feedback_application_request',
                        'attempt_id' => $attemptId,
                        'phase' => $phase,
                        'status' => 'reserved',
                        'generation_feedback_sha256' => hash('sha256', $generationFeedback),
                        'sources' => $sources,
                        'created_at' => $createdAt,
                    ], $this->requestBody($sources), false);

                    return true;
                });
            },
        );
    }

    /**
     * Reserve the current inbox bytes and return the exact generation payload built
     * while both feedback sources are locked.
     *
     * @return array{reserved: bool, generation_feedback: string, generation_feedback_sha256: string}
     */
    public function reserveGenerationFeedback(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $generationContext = '',
    ): array {
        $this->assertAttemptId($attemptId);
        if ($generationContext !== trim($generationContext)) {
            throw new RuntimeException('Generation context must not have leading or trailing whitespace.');
        }

        $requestPath = $this->requestPath($project, $run, $attemptId);

        return $this->locks->exclusive(
            "feedback-application:{$requestPath}",
            function () use ($project, $run, $phase, $attemptId, $generationContext, $requestPath): array {
                if (File::isFile($requestPath)) {
                    $existing = $this->readMetadata($requestPath, 'Feedback application request');
                    $sources = is_array($existing['sources'] ?? null) ? $existing['sources'] : [];
                    $storedContext = is_string($existing['generation_context'] ?? null)
                        ? $existing['generation_context']
                        : '';
                    $generationFeedback = $this->generationFeedback($sources, $storedContext);
                    $generationFeedbackHash = hash('sha256', $generationFeedback);

                    if (($existing['attempt_id'] ?? null) !== $attemptId
                        || ($existing['phase'] ?? null) !== $phase
                        || $sources === []
                        || $storedContext !== $generationContext
                        || ($existing['generation_feedback_sha256'] ?? null) !== $generationFeedbackHash) {
                        throw new RuntimeException('The existing feedback application reservation conflicts with this generation payload.');
                    }

                    return [
                        'reserved' => true,
                        'generation_feedback' => $generationFeedback,
                        'generation_feedback_sha256' => $generationFeedbackHash,
                    ];
                }

                return $this->withSourceLocks(
                    $project,
                    $run,
                    $phase,
                    function () use ($project, $run, $phase, $attemptId, $generationContext, $requestPath): array {
                        $sources = $this->sourceSnapshots($project, $run, $phase);
                        $generationFeedback = $this->generationFeedback($sources, $generationContext);
                        $generationFeedbackHash = hash('sha256', $generationFeedback);
                        if ($sources === []) {
                            return [
                                'reserved' => false,
                                'generation_feedback' => $generationFeedback,
                                'generation_feedback_sha256' => $generationFeedbackHash,
                            ];
                        }

                        $createdAt = $this->timestamp();
                        MarkdownDocument::write($requestPath, [
                            'schema_version' => 1,
                            'id' => $attemptId.'--feedback-application',
                            'type' => 'feedback_application_request',
                            'attempt_id' => $attemptId,
                            'phase' => $phase,
                            'status' => 'reserved',
                            'generation_feedback_sha256' => $generationFeedbackHash,
                            'generation_context' => $generationContext !== '' ? $generationContext : null,
                            'generation_context_sha256' => $generationContext !== ''
                                ? hash('sha256', $generationContext)
                                : null,
                            'sources' => $sources,
                            'created_at' => $createdAt,
                        ], $this->requestBody($sources), false);

                        return [
                            'reserved' => true,
                            'generation_feedback' => $generationFeedback,
                            'generation_feedback_sha256' => $generationFeedbackHash,
                        ];
                    },
                );
            },
        );
    }

    /** @param list<string> $artifactIds */
    public function markApplied(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        array $artifactIds,
    ): void {
        $requestPath = $this->requestPath($project, $run, $attemptId);
        $this->locks->exclusive("feedback-application:{$requestPath}", function () use ($project, $run, $phase, $attemptId, $artifactIds, $requestPath): void {
            if (! File::isFile($requestPath)
                || File::isFile($this->resultPath($project, $run, $attemptId))) {
                return;
            }

            $this->withSourceLocks($project, $run, $phase, function () use ($project, $run, $phase, $attemptId, $artifactIds): void {
                $request = $this->readMetadata(
                    $this->requestPath($project, $run, $attemptId),
                    'Feedback application request',
                );
                $sources = is_array($request['sources'] ?? null) ? $request['sources'] : [];
                $historyIds = [];
                $preservedScopes = [];
                $appliedAt = $this->timestamp();
                $generationFeedbackHash = is_string($request['generation_feedback_sha256'] ?? null)
                    ? $request['generation_feedback_sha256']
                    : '';
                $references = [[
                    'type' => 'generation_attempt',
                    'id' => $attemptId,
                    'path' => $this->attemptRelativePath($phase, $attemptId),
                    'generation_feedback_sha256' => $generationFeedbackHash,
                ]];
                foreach ($artifactIds as $artifactId) {
                    if ($artifactId === '') {
                        continue;
                    }
                    $references[] = [
                        'type' => 'artifact',
                        'id' => $artifactId,
                        'path' => $this->artifactRelativePath($phase, $artifactId),
                        'applied_feedback_sha256' => $generationFeedbackHash,
                    ];
                }

                foreach ($sources as $source) {
                    if (! is_array($source)
                        || ! is_string($source['scope'] ?? null)
                        || ! is_string($source['pending_body'] ?? null)
                        || ! is_string($source['pending_sha256'] ?? null)) {
                        throw new RuntimeException('The feedback application source snapshot is malformed.');
                    }
                    $scope = $source['scope'];
                    $snapshot = $source['pending_body'];
                    $historyId = $attemptId.'--feedback-applied--'.$scope;
                    $historyIds[] = $historyId;
                    $historyPath = $this->paths->feedbackHistoryPath($project, $run).'/'.$historyId.'.md';
                    $historyMetadata = [
                        'schema_version' => 1,
                        'id' => $historyId,
                        'type' => 'feedback_history',
                        'scope' => $scope,
                        'phase' => $phase,
                        'status' => 'applied',
                        'feedback_application_id' => $attemptId.'--feedback-application',
                        'generation_attempt_id' => $attemptId,
                        'feedback_sha256' => $source['pending_sha256'],
                        'generation_feedback_sha256' => $generationFeedbackHash,
                        'implementation_references' => $references,
                        'applied_at' => $appliedAt,
                        'archived_at' => $appliedAt,
                    ];
                    if (File::isFile($historyPath)) {
                        $existing = $this->readMetadata($historyPath, 'Applied feedback history');
                        if (($existing['feedback_application_id'] ?? null) !== $attemptId.'--feedback-application'
                            || ($existing['generation_attempt_id'] ?? null) !== $attemptId
                            || ($existing['scope'] ?? null) !== $scope
                            || ($existing['feedback_sha256'] ?? null) !== $source['pending_sha256']
                            || ($existing['generation_feedback_sha256'] ?? null) !== $generationFeedbackHash
                            || ($existing['implementation_references'] ?? null) !== $references) {
                            throw new RuntimeException('Existing applied feedback history conflicts with the reserved application.');
                        }
                    } else {
                        MarkdownDocument::write(
                            $historyPath,
                            $historyMetadata,
                            "# Applied feedback snapshot\n\n{$snapshot}\n\n## Implementation references\n\n".$this->referenceBody($references)."\n\n## Lifecycle\n\nThis exact snapshot was supplied to the committed generation attempt above. Feedback added concurrently remains in the current inbox.",
                            false,
                        );
                    }

                    if (! $this->removeAppliedPrefix($project, $run, $phase, $scope, $snapshot)) {
                        $preservedScopes[] = $scope;
                    }
                }

                MarkdownDocument::write(
                    $this->resultPath($project, $run, $attemptId),
                    [
                        'schema_version' => 1,
                        'id' => $attemptId.'--feedback-application-result',
                        'type' => 'feedback_application_result',
                        'attempt_id' => $attemptId,
                        'phase' => $phase,
                        'status' => $preservedScopes === [] ? 'applied' : 'applied_snapshot_current_preserved',
                        'artifact_ids' => $artifactIds,
                        'history_ids' => $historyIds,
                        'generation_feedback_sha256' => $generationFeedbackHash,
                        'implementation_references' => $references,
                        'preserved_current_scopes' => $preservedScopes,
                        'finished_at' => $appliedAt,
                    ],
                    "# Feedback application result\n\nThe reserved feedback snapshot is linked to one committed generation attempt and its immutable output artifacts.",
                    false,
                );
            });
        });
    }

    public function markNotApplied(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $error,
    ): void {
        $requestPath = $this->requestPath($project, $run, $attemptId);
        $this->locks->exclusive("feedback-application:{$requestPath}", function () use ($project, $run, $phase, $attemptId, $error, $requestPath): void {
            if (! File::isFile($requestPath)
                || File::isFile($this->resultPath($project, $run, $attemptId))) {
                return;
            }

            MarkdownDocument::write(
                $this->resultPath($project, $run, $attemptId),
                [
                    'schema_version' => 1,
                    'id' => $attemptId.'--feedback-application-result',
                    'type' => 'feedback_application_result',
                    'attempt_id' => $attemptId,
                    'phase' => $phase,
                    'status' => 'not_applied',
                    'implementation_references' => [],
                    'error' => Str::limit($error, 500),
                    'finished_at' => $this->timestamp(),
                ],
                "# Feedback not applied\n\nThe generation attempt did not commit a usable batch. The current feedback inbox was preserved for a later attempt.",
                false,
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function applications(string $project, string $run): array
    {
        $directory = $this->paths->feedbackApplicationsPath($project, $run);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::directories($directory))->map(function (string $path): array {
            try {
                return [
                    'attempt_id' => basename($path),
                    'request' => File::isFile($path.'/request.md')
                        ? MarkdownDocument::read($path.'/request.md')['metadata']
                        : null,
                    'result' => File::isFile($path.'/result.md')
                        ? MarkdownDocument::read($path.'/result.md')['metadata']
                        : null,
                    'path' => $path,
                ];
            } catch (Throwable $exception) {
                return [
                    'attempt_id' => basename($path),
                    'request' => null,
                    'result' => null,
                    'path' => $path,
                    'parse_error' => $exception->getMessage(),
                ];
            }
        })->sortBy('attempt_id')->values()->all());
    }

    /** @return list<array{scope: string, path: string, pending_sha256: string, pending_body: string}> */
    private function sourceSnapshots(string $project, string $run, string $phase): array
    {
        $sources = [];
        foreach ([
            'phase' => $this->paths->currentFeedbackPath($project, $run, $phase),
            'cycle' => $this->paths->cycleFeedbackPath($project, $run),
        ] as $scope => $path) {
            $document = MarkdownDocument::read($path);
            $pending = $this->pendingBody($document['body']);
            if ($pending === '') {
                continue;
            }
            $sources[] = [
                'scope' => $scope,
                'path' => Str::after($path, $this->paths->root().'/'),
                'pending_sha256' => hash('sha256', $pending),
                'pending_body' => $pending,
            ];
        }

        return $sources;
    }

    private function removeAppliedPrefix(
        string $project,
        string $run,
        string $phase,
        string $scope,
        string $snapshot,
    ): bool {
        $path = $scope === 'cycle'
            ? $this->paths->cycleFeedbackPath($project, $run)
            : $this->paths->currentFeedbackPath($project, $run, $phase);
        $document = MarkdownDocument::read($path);
        $current = $this->pendingBody($document['body']);
        if ($current === '') {
            return true;
        }

        if ($current !== $snapshot && ! str_starts_with($current, $snapshot."\n")) {
            return false;
        }

        $remaining = trim(substr($current, strlen($snapshot)));
        $header = $scope === 'cycle'
            ? '# Current feedback · whole cycle'
            : "# Current feedback · {$phase}";
        $metadata = $document['metadata'];
        $metadata['updated_at'] = now('UTC')->toIso8601String();
        MarkdownDocument::write($path, $metadata, $header.($remaining !== '' ? "\n\n{$remaining}" : ''));

        return true;
    }

    private function pendingBody(string $body): string
    {
        $pending = preg_replace('/\A#\s+Current feedback[^\r\n]*\R*/u', '', trim($body));

        return trim(is_string($pending) ? $pending : '');
    }

    /** @param array<array-key, mixed> $sources */
    private function generationFeedback(array $sources, string $generationContext): string
    {
        $feedback = collect($sources)->map(function (mixed $source): string {
            if (! is_array($source)
                || ! is_string($source['scope'] ?? null)
                || ! in_array($source['scope'], ['phase', 'cycle'], true)
                || ! is_string($source['pending_body'] ?? null)) {
                throw new RuntimeException('The feedback application source snapshot is malformed.');
            }

            $label = $source['scope'] === 'phase' ? 'Phase feedback' : 'Whole-run feedback';

            return "# {$label}\n\n".$source['pending_body'];
        })->implode("\n\n");

        if ($feedback === '') {
            return $generationContext;
        }

        return $generationContext === '' ? $feedback : $feedback."\n\n".$generationContext;
    }

    /** @param list<array<string, mixed>> $sources */
    private function requestBody(array $sources): string
    {
        return "# Reserved feedback application\n\n".collect($sources)->map(
            fn (array $source): string => '## '.Str::headline((string) $source['scope'])." feedback\n\n".(string) $source['pending_body'],
        )->implode("\n\n")."\n\n## Lifecycle\n\nThis immutable request reserves the exact pending snapshot. It is marked applied only after a complete artifact batch commits.";
    }

    /** @param list<array<string, string>> $references */
    private function referenceBody(array $references): string
    {
        return collect($references)->map(
            fn (array $reference): string => '- '.Str::headline($reference['type'])." `{$reference['id']}` at `{$reference['path']}`",
        )->implode("\n");
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function withSourceLocks(string $project, string $run, string $phase, callable $callback): mixed
    {
        $phasePath = $this->paths->currentFeedbackPath($project, $run, $phase);
        $cyclePath = $this->paths->cycleFeedbackPath($project, $run);

        return $this->locks->exclusive(
            "feedback:{$phasePath}",
            fn (): mixed => $this->locks->exclusive("feedback:{$cyclePath}", $callback),
        );
    }

    private function requestPath(string $project, string $run, string $attemptId): string
    {
        return $this->paths->feedbackApplicationsPath($project, $run).'/'.$attemptId.'/request.md';
    }

    private function resultPath(string $project, string $run, string $attemptId): string
    {
        return $this->paths->feedbackApplicationsPath($project, $run).'/'.$attemptId.'/result.md';
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path, string $label): array
    {
        try {
            return MarkdownDocument::read($path)['metadata'];
        } catch (Throwable $exception) {
            throw new RuntimeException("{$label} is malformed.", previous: $exception);
        }
    }

    private function attemptRelativePath(string $phase, string $attemptId): string
    {
        return $this->phases->directory($phase)."/attempts/{$attemptId}";
    }

    private function artifactRelativePath(string $phase, string $artifactId): string
    {
        return $this->phases->directory($phase)."/outputs/{$artifactId}.md";
    }

    private function assertAttemptId(string $attemptId): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*(?:--[a-z0-9-]+)+$/', $attemptId) !== 1) {
            throw new RuntimeException('The feedback application attempt ID is invalid.');
        }
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
