<?php

namespace App\Creative;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FilesystemCreativeStore
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativePhaseCatalog $phases,
        private readonly CreativeLockManager $locks,
    ) {}

    /** @return list<string> */
    public function projectIds(): array
    {
        File::ensureDirectoryExists($this->paths->root());

        return array_values(collect(File::directories($this->paths->root()))
            ->map(fn (string $directory): string => basename($directory))
            ->all());
    }

    /** @return array<string, mixed> */
    public function readProject(string $project): array
    {
        return $this->readDocument($this->paths->projectDocumentPath($project));
    }

    /** @param array<string, mixed> $metadata */
    public function writeProject(string $project, array $metadata, string $body): void
    {
        MarkdownDocument::write(
            $this->paths->projectDocumentPath($project),
            $metadata,
            $body,
            false,
        );
    }

    /** @return array<string, mixed> */
    public function readRun(string $project, string $run): array
    {
        return $this->readDocument($this->paths->runDocumentPath($project, $run));
    }

    /** @param array<string, mixed> $metadata */
    public function initializeRun(string $project, string $run, array $metadata, string $body): void
    {
        MarkdownDocument::write(
            $this->paths->runDocumentPath($project, $run),
            $metadata,
            $body,
            false,
        );

        foreach (array_keys($this->phases->all()) as $phase) {
            File::ensureDirectoryExists($this->paths->outputsPath($project, $run, $phase));
            $this->writeCurrentFeedback($project, $run, $phase, '');
        }

        $this->writeCycleFeedback($project, $run, '');
    }

    /** @return list<array<string, mixed>> */
    public function artifacts(string $project, string $run, string $phase): array
    {
        $directory = $this->paths->outputsPath($project, $run, $phase);
        File::ensureDirectoryExists($directory);

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (\SplFileInfo $file) use ($directory): array {
                try {
                    $document = $this->readDocument($file->getPathname());
                } catch (Throwable $exception) {
                    $document = [
                        'metadata' => [],
                        'body' => '',
                        'sections' => [],
                        'parse_error' => $exception->getMessage(),
                    ];
                }

                $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
                $artifactType = $metadata['artifact_type'] ?? 'markdown';
                $assetStatus = $metadata['asset_status'] ?? null;
                if ($assetStatus === null && in_array($artifactType, ['image_sidecar', 'video_sidecar'], true)) {
                    $assetPath = is_string($metadata['asset_path'] ?? null) ? $metadata['asset_path'] : '';
                    $assetStatus = $assetPath !== '' && (File::isFile($file->getPath().'/'.$assetPath)
                        || File::isFile(dirname($file->getPath()).'/'.$assetPath))
                        ? 'ready'
                        : 'missing';
                }

                return $document + [
                    'id' => $metadata['id'] ?? $file->getBasename('.md'),
                    'title' => $metadata['title'] ?? Str::headline($file->getBasename('.md')),
                    'summary' => $metadata['summary'] ?? '',
                    'type' => $artifactType,
                    'slot' => $metadata['slot'] ?? 'primary',
                    'variant' => $metadata['variant'] ?? null,
                    'created_at' => $metadata['created_at'] ?? date(DATE_ATOM, $file->getMTime()),
                    'path' => Str::after($file->getPathname(), $this->paths->root().'/'),
                    'filename' => Str::after($file->getPathname(), $directory.'/'),
                    'preview_color' => $metadata['preview_color'] ?? '#d9d5c8',
                    'asset_status' => $assetStatus,
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all());
    }

    /** @param array<string, mixed> $metadata */
    public function writeArtifact(
        string $project,
        string $run,
        string $phase,
        string $artifactId,
        array $metadata,
        string $body,
    ): void {
        MarkdownDocument::write(
            $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md',
            $metadata,
            $body,
            false,
        );
    }

    /** @return list<array<string, mixed>> */
    public function selectionRecords(string $project, string $run, string $phase): array
    {
        $directory = $this->paths->selectionsPath($project, $run, $phase);

        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (\SplFileInfo $file): array {
                try {
                    $metadata = MarkdownDocument::read($file->getPathname())['metadata'];
                } catch (Throwable $exception) {
                    $metadata = ['parse_error' => $exception->getMessage()];
                }

                return $metadata + [
                    '_filename' => $file->getFilename(),
                    '_path' => $file->getPathname(),
                ];
            })
            ->values()
            ->all());
    }

    /** @param array<string, mixed> $metadata */
    public function appendSelection(
        string $project,
        string $run,
        string $phase,
        string $slot,
        string $selectionId,
        array $metadata,
        string $body,
    ): void {
        $lock = $phase === 'ideas'
            ? $this->ideasLineageLock($project, $run)
            : "selection:{$project}:{$run}:{$phase}:{$slot}";

        $this->locks->exclusive($lock, function () use ($project, $run, $phase, $slot, $selectionId, $metadata, $body): void {
            $metadata = $phase === 'ideas'
                ? array_merge($metadata, [
                    'lineage_sequence' => $this->nextIdeasLineageSequence($project, $run),
                ])
                : $metadata;
            $this->writeSelection($project, $run, $phase, $slot, $selectionId, $metadata, $body);
        });
    }

    /**
     * Append an Ideas selection and its exact source-promotion evidence without
     * allowing a skip or another Ideas selection to interleave between them.
     *
     * A process interruption can still leave the first immutable file behind,
     * so readers must continue to fail closed unless both records agree.
     *
     * @param  array<string, mixed>  $selectionMetadata
     * @param  array<string, mixed>  $decisionMetadata
     */
    public function appendIdeasSelectionWithSourceDecision(
        string $project,
        string $run,
        string $slot,
        string $selectionId,
        array $selectionMetadata,
        string $selectionBody,
        string $decisionId,
        array $decisionMetadata,
        string $decisionBody,
    ): void {
        $this->locks->exclusive($this->ideasLineageLock($project, $run), function () use (
            $project,
            $run,
            $slot,
            $selectionId,
            $selectionMetadata,
            $selectionBody,
            $decisionId,
            $decisionMetadata,
            $decisionBody,
        ): void {
            $lineageSequence = $this->nextIdeasLineageSequence($project, $run);
            $this->writeSelection(
                $project,
                $run,
                'ideas',
                $slot,
                $selectionId,
                array_merge($selectionMetadata, ['lineage_sequence' => $lineageSequence]),
                $selectionBody,
            );
            $this->writeIdeaSourceDecision(
                $project,
                $run,
                $decisionId,
                array_merge($decisionMetadata, ['lineage_sequence' => $lineageSequence]),
                $decisionBody,
            );
        });
    }

    /**
     * @return array{
     *     current: list<array{title: string, content: string}>,
     *     cycle: list<array{title: string, content: string}>,
     *     history: list<array<string, mixed>>,
     *     history_count: int
     * }
     */
    public function feedback(string $project, string $run, string $phase): array
    {
        $historyPath = $this->paths->feedbackHistoryPath($project, $run);
        $history = [];
        if (File::isDirectory($historyPath)) {
            $history = array_values(collect(File::files($historyPath))
                ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
                ->map(function (\SplFileInfo $file): array {
                    try {
                        $document = MarkdownDocument::read($file->getPathname());
                        $metadata = $document['metadata'];

                        return $document + [
                            'id' => $metadata['id'] ?? $file->getBasename('.md'),
                            'title' => 'Feedback · '.Str::headline((string) ($metadata['scope'] ?? 'snapshot')),
                            'path' => Str::after($file->getPathname(), $this->paths->root().'/'),
                        ];
                    } catch (Throwable $exception) {
                        return [
                            'id' => $file->getBasename('.md'),
                            'title' => 'Malformed feedback snapshot',
                            'path' => Str::after($file->getPathname(), $this->paths->root().'/'),
                            'metadata' => [],
                            'body' => '',
                            'sections' => [],
                            'parse_error' => $exception->getMessage(),
                        ];
                    }
                })
                ->sortByDesc(fn (array $item): string => (string) ($item['metadata']['archived_at'] ?? $item['id']))
                ->values()
                ->all());
        }

        return [
            'current' => MarkdownDocument::read($this->paths->currentFeedbackPath($project, $run, $phase))['sections'],
            'cycle' => MarkdownDocument::read($this->paths->cycleFeedbackPath($project, $run))['sections'],
            'history' => $history,
            'history_count' => count($history),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function ideaSourceDecisions(string $project, string $run): array
    {
        $directory = $this->paths->ideaSourceDecisionsPath($project, $run);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (\SplFileInfo $file): array {
                try {
                    $document = MarkdownDocument::read($file->getPathname());
                    $metadata = $document['metadata'];

                    return $metadata + [
                        '_body' => $document['body'],
                        '_filename' => $file->getFilename(),
                        '_path' => Str::after($file->getPathname(), $this->paths->root().'/'),
                    ];
                } catch (Throwable $exception) {
                    return [
                        'parse_error' => $exception->getMessage(),
                        '_filename' => $file->getFilename(),
                        '_path' => Str::after($file->getPathname(), $this->paths->root().'/'),
                    ];
                }
            })
            ->sortBy(fn (array $record): int => (int) ($record['sequence'] ?? 0))
            ->values()
            ->all());
    }

    /** @param array<string, mixed> $metadata */
    public function appendIdeaSourceDecision(
        string $project,
        string $run,
        string $decisionId,
        array $metadata,
        string $body,
    ): void {
        $this->locks->exclusive($this->ideasLineageLock($project, $run), function () use ($project, $run, $decisionId, $metadata, $body): void {
            $this->writeIdeaSourceDecision(
                $project,
                $run,
                $decisionId,
                array_merge($metadata, [
                    'lineage_sequence' => $this->nextIdeasLineageSequence($project, $run),
                ]),
                $body,
            );
        });
    }

    /**
     * Safely backfill source-promotion evidence for the exact current Ideas
     * selection. Returns false when another process already wrote that exact
     * evidence while this caller was preparing the recovery.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function ensureIdeaSourceDecisionForCurrentSelection(
        string $project,
        string $run,
        string $selectionId,
        string $artifactId,
        string $decisionId,
        array $metadata,
        string $body,
    ): bool {
        return $this->locks->exclusive($this->ideasLineageLock($project, $run), function () use (
            $project,
            $run,
            $selectionId,
            $artifactId,
            $decisionId,
            $metadata,
            $body,
        ): bool {
            $selection = $this->newestRecord(array_values(array_filter(
                $this->selectionRecords($project, $run, 'ideas'),
                static fn (array $record): bool => ($record['slot'] ?? null) === 'primary',
            )));

            if (! is_array($selection)
                || ($selection['id'] ?? null) !== $selectionId
                || ($selection['artifact_id'] ?? null) !== $artifactId) {
                throw new RuntimeException('The effective Ideas selection changed before its source decision could be recovered.');
            }

            $latestDecision = $this->newestRecord($this->ideaSourceDecisions($project, $run));
            if (is_array($latestDecision)
                && ($latestDecision['mode'] ?? null) === 'selected_idea'
                && ($latestDecision['artifact_id'] ?? null) === $artifactId
                && ($latestDecision['selection_id'] ?? null) === $selectionId) {
                return false;
            }

            if (is_array($latestDecision)
                && ($latestDecision['mode'] ?? null) === 'project_idea'
                && $this->ideaDecisionIsAtOrAfterSelection($latestDecision, $selection)) {
                throw new RuntimeException('Ideas are currently explicitly skipped; recovery cannot supersede that decision.');
            }

            $selectionLineageSequence = $selection['lineage_sequence'] ?? null;
            $this->writeIdeaSourceDecision(
                $project,
                $run,
                $decisionId,
                is_int($selectionLineageSequence) && $selectionLineageSequence > 0
                    ? array_merge($metadata, ['lineage_sequence' => $selectionLineageSequence])
                    : $metadata,
                $body,
            );

            return true;
        });
    }

    public function appendFeedbackEntry(
        string $project,
        string $run,
        string $phase,
        string $scope,
        string $entry,
    ): void {
        $target = $scope === 'cycle'
            ? $this->paths->cycleFeedbackPath($project, $run)
            : $this->paths->currentFeedbackPath($project, $run, $phase);

        $this->locks->exclusive("feedback:{$target}", function () use ($target, $entry): void {
            if (file_put_contents($target, $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to append feedback safely.');
            }
        });
    }

    public function archiveFeedback(
        string $project,
        string $run,
        string $phase,
        string $scope,
        string $historyName,
        string $archivedAt,
    ): void {
        $source = $scope === 'cycle'
            ? $this->paths->cycleFeedbackPath($project, $run)
            : $this->paths->currentFeedbackPath($project, $run, $phase);

        $this->locks->exclusive("feedback:{$source}", function () use ($source, $scope, $phase, $project, $run, $historyName, $archivedAt): void {
            $document = MarkdownDocument::read($source);

            if (trim($document['body']) === '' || ! str_contains($document['body'], '## ')) {
                throw new RuntimeException('There is no pending feedback to archive.');
            }

            MarkdownDocument::write(
                $this->paths->feedbackHistoryPath($project, $run).'/'.$historyName.'.md',
                [
                    'id' => $historyName,
                    'type' => 'feedback_history',
                    'scope' => $scope,
                    'phase' => $phase,
                    'status' => 'archived_unapplied',
                    'archived_at' => $archivedAt,
                ],
                "# Archived feedback snapshot\n\n{$document['body']}\n\n## Processing status\n\nMoved to history without claiming implementation. No generation, code change, or verification reference was supplied.",
                false,
            );

            if ($scope === 'cycle') {
                $this->writeCycleFeedback($project, $run, '');
            } else {
                $this->writeCurrentFeedback($project, $run, $phase, '');
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function runs(string $project): array
    {
        return array_values(collect(File::directories($this->paths->runsPath($project)))
            ->map(function (string $path): array {
                $document = $this->readDocument($path.'/run.md');

                return [
                    'id' => basename($path),
                    'created_at' => $document['metadata']['created_at'] ?? '',
                    'label' => 'Run '.substr(basename($path), 0, 8),
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all());
    }

    /** @return list<array{name: string, path: string, type: string, children?: list<array<string, mixed>>}> */
    public function projectTree(string $project): array
    {
        $base = $this->paths->projectPath($project);

        return $this->tree($base, $base);
    }

    /** @return array<string, mixed> */
    public function readDocument(string $path): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException('Creative document not found: '.basename($path));
        }

        try {
            return MarkdownDocument::read($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('Creative document is malformed: '.basename($path), previous: $exception);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function writeSelection(
        string $project,
        string $run,
        string $phase,
        string $slot,
        string $selectionId,
        array $metadata,
        string $body,
    ): void {
        $sequence = collect($this->selectionRecords($project, $run, $phase))
            ->where('slot', $slot)
            ->max(fn (array $record): int => (int) ($record['sequence'] ?? 0));

        MarkdownDocument::write(
            $this->paths->selectionsPath($project, $run, $phase).'/'.$selectionId.'.md',
            $metadata + ['sequence' => ((int) $sequence) + 1],
            $body,
            false,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function writeIdeaSourceDecision(
        string $project,
        string $run,
        string $decisionId,
        array $metadata,
        string $body,
    ): void {
        $sequence = collect($this->ideaSourceDecisions($project, $run))
            ->max(fn (array $record): int => (int) ($record['sequence'] ?? 0));

        MarkdownDocument::write(
            $this->paths->ideaSourceDecisionsPath($project, $run).'/'.$decisionId.'.md',
            $metadata + ['sequence' => ((int) $sequence) + 1],
            $body,
            false,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>|null
     */
    private function newestRecord(array $records): ?array
    {
        usort($records, static function (array $left, array $right): int {
            $sequence = ((int) ($right['sequence'] ?? 0)) <=> ((int) ($left['sequence'] ?? 0));
            if ($sequence !== 0) {
                return $sequence;
            }

            $timestamp = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            if ($timestamp !== 0) {
                return $timestamp;
            }

            return strcmp((string) ($right['_filename'] ?? ''), (string) ($left['_filename'] ?? ''));
        });

        return $records[0] ?? null;
    }

    private function nextIdeasLineageSequence(string $project, string $run): int
    {
        $selectionMaximum = collect($this->selectionRecords($project, $run, 'ideas'))
            ->max(fn (array $record): int => (int) ($record['lineage_sequence'] ?? 0));
        $decisionMaximum = collect($this->ideaSourceDecisions($project, $run))
            ->max(fn (array $record): int => (int) ($record['lineage_sequence'] ?? 0));

        return max((int) $selectionMaximum, (int) $decisionMaximum) + 1;
    }

    /**
     * New records carry a shared monotonic sequence. Timestamps remain the
     * conservative compatibility fallback for legacy records.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $selection
     */
    private function ideaDecisionIsAtOrAfterSelection(array $decision, array $selection): bool
    {
        $decisionSequence = $decision['lineage_sequence'] ?? null;
        $selectionSequence = $selection['lineage_sequence'] ?? null;
        if (is_int($decisionSequence)
            && $decisionSequence > 0
            && is_int($selectionSequence)
            && $selectionSequence > 0) {
            return $decisionSequence >= $selectionSequence;
        }

        $decisionCreatedAt = is_string($decision['created_at'] ?? null)
            ? $decision['created_at']
            : '';
        $selectionCreatedAt = is_string($selection['created_at'] ?? null)
            ? $selection['created_at']
            : '';

        return $decisionCreatedAt === ''
            || $selectionCreatedAt === ''
            || $decisionCreatedAt >= $selectionCreatedAt;
    }

    private function ideasLineageLock(string $project, string $run): string
    {
        return "ideas-lineage:{$project}:{$run}";
    }

    /** @return list<array{name: string, path: string, type: string, children?: list<array<string, mixed>>}> */
    private function tree(string $directory, string $base, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        return array_values(collect(File::directories($directory))
            ->map(fn (string $path): array => [
                'name' => basename($path),
                'path' => Str::after($path, $base.'/'),
                'type' => 'directory',
                'children' => $this->tree($path, $base, $depth + 1),
            ])
            ->concat(collect(File::files($directory))->map(fn (\SplFileInfo $file): array => [
                'name' => $file->getFilename(),
                'path' => Str::after($file->getPathname(), $base.'/'),
                'type' => $file->getExtension() === 'md' ? 'markdown' : 'asset',
            ]))
            ->sortBy(fn (array $item): string => ($item['type'] === 'directory' ? '0' : '1').$item['name'])
            ->values()
            ->all());
    }

    private function writeCurrentFeedback(string $project, string $run, string $phase, string $body): void
    {
        MarkdownDocument::write($this->paths->currentFeedbackPath($project, $run, $phase), [
            'type' => 'feedback_inbox',
            'scope' => 'phase',
            'phase' => $phase,
            'updated_at' => now('UTC')->toIso8601String(),
        ], "# Current feedback · {$phase}\n\n{$body}");
    }

    private function writeCycleFeedback(string $project, string $run, string $body): void
    {
        MarkdownDocument::write($this->paths->cycleFeedbackPath($project, $run), [
            'type' => 'feedback_inbox',
            'scope' => 'cycle',
            'updated_at' => now('UTC')->toIso8601String(),
        ], "# Current feedback · whole cycle\n\n{$body}");
    }
}
