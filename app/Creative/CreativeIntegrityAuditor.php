<?php

namespace App\Creative;

use App\Creative\Automation\AutomationRunProjector;
use App\Creative\Automation\AutomationStateStore;
use App\Creative\Feedback\FeedbackApplicationIntegrityAuditor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class CreativeIntegrityAuditor
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativePhaseCatalog $phases,
        private readonly AutomationStateStore $automationStates,
        private readonly AutomationRunProjector $automationProjector,
        private readonly CreativeArtifactValidator $validator,
        private readonly CreativeArtifactEligibilityVerifier $eligibility,
        private readonly EffectiveSelectionResolver $selectionResolver,
        private readonly ShotPlanResolver $shotPlans,
        private readonly CreativeOperationalIntegrityAuditor $operationalIntegrity,
        private readonly FeedbackApplicationIntegrityAuditor $feedbackIntegrity,
    ) {}

    /**
     * @return array{
     *     scope: array{project: string|null, run: string|null},
     *     summary: array{total: int, errors: int, warnings: int, info: int},
     *     issues: list<array{severity: 'error'|'warning'|'info', code: string, project: string|null, run: string|null, phase: string|null, path: string|null, entity: string|null, message: string, remediation: string}>
     * }
     */
    public function audit(?string $project = null, ?string $run = null): array
    {
        if ($run !== null && $project === null) {
            throw new InvalidArgumentException('A run can be audited only when its project is specified.');
        }

        $issues = [];
        $root = $this->paths->root();
        if (! File::isDirectory($root)) {
            $this->addIssue(
                $issues,
                'error',
                'creative_root_missing',
                $project,
                $run,
                null,
                null,
                null,
                'The configured creative-projects root does not exist.',
                'Create the configured root or correct CREATIVE_PROJECTS_ROOT.',
            );

            return $this->report($project, $run, $issues);
        }

        $projects = $this->projectsForScope($project, $issues);
        foreach ($projects as $projectId) {
            $this->auditProject($projectId, $run, $issues);
        }

        return $this->report($project, $run, $issues);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function projectsForScope(?string $requestedProject, array &$issues): array
    {
        if ($requestedProject !== null) {
            try {
                $path = $this->paths->projectPath($requestedProject);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
            }

            if (! File::isDirectory($path)) {
                throw new InvalidArgumentException("Creative project [{$requestedProject}] does not exist.");
            }

            return [$requestedProject];
        }

        $projects = [];
        foreach (File::directories($this->paths->root()) as $directory) {
            $project = basename($directory);
            try {
                $this->paths->assertIdentifier($project);
                $projects[] = $project;
            } catch (Throwable) {
                $this->addIssue(
                    $issues,
                    'error',
                    'invalid_project_directory',
                    null,
                    null,
                    null,
                    $this->relativePath($directory),
                    $project,
                    'A project directory has an invalid filesystem identifier.',
                    'Rename or quarantine the directory after confirming its contents.',
                );
            }
        }

        sort($projects);

        return $projects;
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditProject(string $project, ?string $requestedRun, array &$issues): void
    {
        $projectDocument = $this->auditIdentityDocument(
            $this->paths->projectDocumentPath($project),
            'creative_project',
            $project,
            $project,
            null,
            $issues,
        );
        $startPhase = is_array($projectDocument)
            && is_string($projectDocument['metadata']['start_phase'] ?? null)
            ? $projectDocument['metadata']['start_phase']
            : 'concepts';

        $runsPath = $this->paths->runsPath($project);
        if (! File::isDirectory($runsPath)) {
            $this->addIssue(
                $issues,
                'error',
                'runs_directory_missing',
                $project,
                $requestedRun,
                null,
                $this->relativePath($runsPath),
                $project,
                'The project has no runs directory.',
                'Create a new run through VideoAuto or restore the missing directory.',
            );

            return;
        }

        $runs = [];
        if ($requestedRun !== null) {
            try {
                $runPath = $this->paths->runPath($project, $requestedRun);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
            }

            if (! File::isDirectory($runPath)) {
                throw new InvalidArgumentException("Creative run [{$requestedRun}] does not exist in project [{$project}].");
            }
            $runs[] = $requestedRun;
        } else {
            foreach (File::directories($runsPath) as $directory) {
                $run = basename($directory);
                try {
                    $this->paths->assertIdentifier($run);
                    $runs[] = $run;
                } catch (Throwable) {
                    $this->addIssue(
                        $issues,
                        'error',
                        'invalid_run_directory',
                        $project,
                        null,
                        null,
                        $this->relativePath($directory),
                        $run,
                        'A run directory has an invalid filesystem identifier.',
                        'Rename or quarantine the directory after confirming its contents.',
                    );
                }
            }
        }

        sort($runs);
        foreach ($runs as $run) {
            $this->auditRun($project, $run, $startPhase, $issues);
        }
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditRun(string $project, string $run, string $startPhase, array &$issues): void
    {
        $this->auditIdentityDocument(
            $this->paths->runDocumentPath($project, $run),
            'creative_run',
            $run,
            $project,
            $run,
            $issues,
        );

        /** @var array<string, list<array<string, mixed>>> $artifactsByPhase */
        $artifactsByPhase = [];
        /** @var array<string, list<array<string, mixed>>> $recordsByPhase */
        $recordsByPhase = [];
        foreach (array_keys($this->phases->all()) as $phase) {
            $artifactsByPhase[$phase] = $this->readArtifacts($project, $run, $phase);
            $recordsByPhase[$phase] = $this->readSelections($project, $run, $phase, $issues);
        }
        $ideaSourceDecisions = $this->readIdeaSourceDecisions($project, $run);
        $this->auditIdeaSourceDecisions($project, $run, $ideaSourceDecisions, $issues);

        $this->auditDuplicateArtifactIds($project, $run, $artifactsByPhase, $issues);

        /** @var array<string, array<string, mixed>> $states */
        $states = [];
        /** @var array<string, list<string>> $requiredSlotsByPhase */
        $requiredSlotsByPhase = [];
        /** @var array<string, array{ready: bool, by_slot: array<string, list<string>>}> $expectedByPhase */
        $expectedByPhase = [];
        /** @var array<string, array<string, array<string, mixed>>> $expectedShotsByPhase */
        $expectedShotsByPhase = [];
        /** @var array<string, array<string, mixed>|null> $expectedSequencePlansByPhase */
        $expectedSequencePlansByPhase = [];
        $shotSlots = [];
        $shotsBySlot = [];

        foreach ($this->phases->all() as $phase => $definition) {
            $selectionMode = is_string($definition['selection_mode'] ?? null)
                ? $definition['selection_mode']
                : 'single';
            $configuredSlots = is_array($definition['slots'] ?? null)
                ? array_values(array_filter($definition['slots'], 'is_string'))
                : ['primary'];
            $requiredSlots = $selectionMode === 'script_shots' ? $shotSlots : $configuredSlots;
            $requiredSlotsByPhase[$phase] = $requiredSlots;
            $expectedShots = in_array($phase, ['images', 'scenes'], true) ? $shotsBySlot : [];
            $expectedShotsByPhase[$phase] = $expectedShots;
            $expectedSequencePlan = $phase === 'sequence'
                ? $this->sequencePlanFromState(is_array($states['scenes'] ?? null) ? $states['scenes'] : [])
                : null;
            $expectedSequencePlansByPhase[$phase] = $expectedSequencePlan;

            $expected = $this->expectedSources(
                $project,
                $phase,
                $requiredSlots,
                $ideaSourceDecisions,
                $states,
            );
            $expectedByPhase[$phase] = $expected;

            $state = $this->selectionResolver->resolve(
                $phase,
                $recordsByPhase[$phase],
                $artifactsByPhase[$phase],
                $requiredSlots,
                $expected['by_slot'],
                $expected['ready'] && ($selectionMode !== 'script_shots' || $requiredSlots !== []),
                $expectedShots,
                $project,
                $run,
                $expectedSequencePlan,
            );
            $states[$phase] = $state;

            if ($phase === 'brief-script' && $state['complete']) {
                $selectedScript = $state['resolved'][0]['artifact'] ?? null;
                if (is_array($selectedScript)) {
                    $shotPlan = $this->shotPlans->resolve($selectedScript);
                    if ($shotPlan['valid'] && is_array($shotPlan['shot_plan'])) {
                        $shots = $shotPlan['shot_plan']['shots'] ?? [];
                        foreach (is_array($shots) ? $shots : [] as $shot) {
                            if (! is_array($shot) || ! is_string($shot['id'] ?? null)) {
                                continue;
                            }

                            $shotSlots[] = $shot['id'];
                            $shotsBySlot[$shot['id']] = $shot;
                        }
                    }
                }
            }
        }

        $this->auditEffectiveIdeaSourceDecision(
            $project,
            $run,
            is_array($states['ideas'] ?? null) ? $states['ideas'] : [],
            $ideaSourceDecisions,
            $issues,
        );

        foreach (array_keys($this->phases->all()) as $phase) {
            $this->auditArtifacts(
                $project,
                $run,
                $phase,
                $artifactsByPhase[$phase],
                $requiredSlotsByPhase[$phase],
                $expectedByPhase[$phase],
                $expectedShotsByPhase[$phase],
                $expectedSequencePlansByPhase[$phase],
                $issues,
            );
            $this->auditSelections(
                $project,
                $run,
                $phase,
                $recordsByPhase[$phase],
                $artifactsByPhase[$phase],
                $states[$phase],
                $issues,
            );
            $this->auditAttempts($project, $run, $phase, $issues);
            $this->auditEvaluations($project, $run, $phase, $artifactsByPhase[$phase], $issues);
        }

        foreach ($this->operationalIntegrity->audit($project, $run) as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->feedbackIntegrity->audit($project, $run) as $issue) {
            $issues[] = $issue;
        }

        $this->auditAutomation($project, $run, $issues);
        $this->reportIncompletePhases($project, $run, $startPhase, $states, $requiredSlotsByPhase, $issues);
    }

    /**
     * @param  array<string, mixed>  $sceneState
     * @return array<string, mixed>|null
     */
    private function sequencePlanFromState(array $sceneState): ?array
    {
        if (! ($sceneState['complete'] ?? false) || ! is_array($sceneState['resolved'] ?? null)) {
            return null;
        }

        $scenes = [];
        foreach ($sceneState['resolved'] as $item) {
            if (is_array($item) && is_array($item['artifact'] ?? null)) {
                $scenes[] = $item['artifact'];
            }
        }

        $contract = $this->shotPlans->sequenceContract($scenes);

        return $contract['valid'] && is_array($contract['shot_plan'])
            ? $contract['shot_plan']
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $ideaSourceDecisions
     * @param  array<string, array<string, mixed>>  $states
     * @param  list<string>  $requiredSlots
     * @return array{ready: bool, by_slot: array<string, list<string>>}
     */
    private function expectedSources(
        string $project,
        string $phase,
        array $requiredSlots,
        array $ideaSourceDecisions,
        array $states,
    ): array {
        if ($phase === 'ideas') {
            return ['ready' => true, 'by_slot' => ['primary' => [$project]]];
        }

        if ($phase === 'concepts') {
            $sourceIds = $this->conceptSourceIds(
                $project,
                is_array($states['ideas'] ?? null) ? $states['ideas'] : [],
                $ideaSourceDecisions,
            );

            return [
                'ready' => $sourceIds !== [],
                'by_slot' => ['primary' => $sourceIds],
            ];
        }

        $previous = $this->phases->previous($phase);
        if ($previous === null || ! ($states[$previous]['complete'] ?? false)) {
            return ['ready' => false, 'by_slot' => array_fill_keys($requiredSlots, [])];
        }

        $resolved = is_array($states[$previous]['resolved'] ?? null) ? $states[$previous]['resolved'] : [];
        if ($phase === 'scenes') {
            $bySlot = [];
            foreach ($resolved as $item) {
                if (! is_array($item) || ! is_array($item['artifact'] ?? null)) {
                    continue;
                }
                $slot = $item['artifact']['slot'] ?? null;
                $id = $item['artifact']['id'] ?? null;
                if (is_string($slot) && is_string($id)) {
                    $bySlot[$slot] = [$id];
                }
            }

            return ['ready' => true, 'by_slot' => $bySlot];
        }

        $sourceIds = [];
        foreach ($resolved as $item) {
            $id = is_array($item) && is_array($item['artifact'] ?? null)
                ? ($item['artifact']['id'] ?? null)
                : null;
            if (is_string($id)) {
                $sourceIds[] = $id;
            }
        }

        return ['ready' => true, 'by_slot' => array_fill_keys($requiredSlots, $sourceIds)];
    }

    /**
     * @param  array<string, mixed>  $ideaState
     * @param  list<array<string, mixed>>  $decisions
     * @return list<string>
     */
    private function conceptSourceIds(string $project, array $ideaState, array $decisions): array
    {
        $resolved = is_array($ideaState['resolved'] ?? null) ? $ideaState['resolved'] : [];
        $selection = is_array($resolved[0]['selection'] ?? null) ? $resolved[0]['selection'] : null;
        $artifact = is_array($resolved[0]['artifact'] ?? null) ? $resolved[0]['artifact'] : null;
        $latest = $this->latestValidIdeaSourceDecision($project, $decisions);

        if (is_array($latest) && ($latest['mode'] ?? null) === 'project_idea') {
            if (! ($ideaState['complete'] ?? false)
                || ! is_array($selection)
                || $this->ideaDecisionIsAtOrAfterSelection($latest, $selection)) {
                return [$project];
            }
        }

        $artifactId = is_array($artifact) && is_string($artifact['id'] ?? null)
            ? $artifact['id']
            : null;
        $selectionId = is_array($selection) && is_string($selection['id'] ?? null)
            ? $selection['id']
            : null;

        return ($ideaState['complete'] ?? false)
            && $artifactId !== null
            && $selectionId !== null
            && is_array($latest)
            && ($latest['mode'] ?? null) === 'selected_idea'
            && ($latest['artifact_id'] ?? null) === $artifactId
            && ($latest['selection_id'] ?? null) === $selectionId
            ? [$artifactId]
            : [];
    }

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @return array<string, mixed>|null
     */
    private function latestValidIdeaSourceDecision(string $project, array $decisions): ?array
    {
        $latest = collect($decisions)
            ->filter(fn (array $record): bool => $this->ideaSourceDecisionErrors($record, $project) === [])
            ->sortByDesc(fn (array $record): int => (int) ($record['sequence'] ?? 0))
            ->first();

        return is_array($latest) ? $latest : null;
    }

    /**
     * @param  array<string, mixed>  $ideaState
     * @param  list<array<string, mixed>>  $decisions
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditEffectiveIdeaSourceDecision(
        string $project,
        string $run,
        array $ideaState,
        array $decisions,
        array &$issues,
    ): void {
        if (! ($ideaState['complete'] ?? false)) {
            return;
        }

        $resolved = is_array($ideaState['resolved'] ?? null) ? $ideaState['resolved'] : [];
        $selection = is_array($resolved[0]['selection'] ?? null) ? $resolved[0]['selection'] : null;
        $artifact = is_array($resolved[0]['artifact'] ?? null) ? $resolved[0]['artifact'] : null;
        if (! is_array($selection) || ! is_array($artifact)) {
            return;
        }

        $latest = $this->latestValidIdeaSourceDecision($project, $decisions);
        if (is_array($latest) && ($latest['mode'] ?? null) === 'project_idea') {
            if ($this->ideaDecisionIsAtOrAfterSelection($latest, $selection)) {
                return;
            }
        }

        $artifactId = is_string($artifact['id'] ?? null) ? $artifact['id'] : '';
        $selectionId = is_string($selection['id'] ?? null) ? $selection['id'] : '';
        $matches = is_array($latest)
            && ($latest['mode'] ?? null) === 'selected_idea'
            && ($latest['artifact_id'] ?? null) === $artifactId
            && ($latest['selection_id'] ?? null) === $selectionId;
        if ($matches) {
            return;
        }

        $path = is_string($selection['_path'] ?? null)
            ? $this->relativePath($selection['_path'])
            : null;
        $selectedAutomatically = ($selection['selected_by'] ?? null) === 'automation';
        if ($latest === null) {
            $this->addIssue(
                $issues,
                'error',
                'ideas_source_decision_missing',
                $project,
                $run,
                'ideas',
                $path,
                $selectionId !== '' ? $selectionId : null,
                "Effective Ideas selection [{$selectionId}] for artifact [{$artifactId}] has no valid selected_idea source decision."
                    .($selectedAutomatically ? ' The automatic selection therefore has no trustworthy source-promotion evidence.' : ''),
                'Create a newer Ideas selection so its exact artifact and selection IDs are promoted by a new immutable source decision.',
            );

            return;
        }

        $decisionId = is_string($latest['id'] ?? null) ? $latest['id'] : 'unknown';
        $decisionMode = is_string($latest['mode'] ?? null) ? $latest['mode'] : 'unknown';
        $decisionArtifactId = is_string($latest['artifact_id'] ?? null) ? $latest['artifact_id'] : 'null';
        $decisionSelectionId = is_string($latest['selection_id'] ?? null) ? $latest['selection_id'] : 'null';
        $this->addIssue(
            $issues,
            'error',
            'ideas_source_decision_mismatch',
            $project,
            $run,
            'ideas',
            is_string($latest['_path'] ?? null) ? $this->relativePath($latest['_path']) : $path,
            $decisionId,
            "Latest valid Ideas source decision [{$decisionId}] records mode [{$decisionMode}], artifact [{$decisionArtifactId}], and selection [{$decisionSelectionId}], but the effective Ideas selection is [{$selectionId}] for artifact [{$artifactId}]."
                .($selectedAutomatically ? ' The contradictory record cannot prove the automatic source promotion.' : ''),
            'Preserve the contradictory evidence and create a newer Ideas selection with an exact matching immutable source decision.',
        );
    }

    /**
     * New records share a monotonic Ideas lineage sequence. Timestamps are the
     * conservative compatibility fallback for historical records.
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

    /** @return list<array<string, mixed>> */
    private function readIdeaSourceDecisions(string $project, string $run): array
    {
        $directory = $this->paths->ideaSourceDecisionsPath($project, $run);
        if (! File::isDirectory($directory)) {
            return [];
        }

        $decisions = [];
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            try {
                $metadata = MarkdownDocument::read($file->getPathname())['metadata'];
            } catch (Throwable $exception) {
                $metadata = ['parse_error' => $exception->getMessage()];
            }

            $decisions[] = $metadata + [
                '_filename' => $file->getFilename(),
                '_path' => $file->getPathname(),
            ];
        }

        return $decisions;
    }

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditIdeaSourceDecisions(
        string $project,
        string $run,
        array $decisions,
        array &$issues,
    ): void {
        $sequences = [];
        foreach ($decisions as $decision) {
            $path = is_string($decision['_path'] ?? null) ? $this->relativePath($decision['_path']) : null;
            $entity = is_string($decision['id'] ?? null)
                ? $decision['id']
                : (is_string($decision['_filename'] ?? null) ? Str::beforeLast($decision['_filename'], '.md') : null);

            if (is_string($decision['parse_error'] ?? null)) {
                $this->addIssue(
                    $issues,
                    'error',
                    'malformed_ideas_source_decision',
                    $project,
                    $run,
                    'ideas',
                    $path,
                    $entity,
                    'The Ideas source decision Markdown or YAML cannot be parsed.',
                    'Preserve the record, then create a newer valid Ideas source decision.',
                );

                continue;
            }

            $errors = $this->ideaSourceDecisionErrors($decision, $project);
            if ($errors !== []) {
                $this->addIssue(
                    $issues,
                    'error',
                    'invalid_ideas_source_decision',
                    $project,
                    $run,
                    'ideas',
                    $path,
                    $entity,
                    implode(' ', $errors),
                    'Preserve the record, then create a newer decision satisfying the Ideas source contract.',
                );

                continue;
            }

            $sequence = (int) $decision['sequence'];
            $sequences[$sequence][] = ['path' => $path, 'entity' => $entity];
        }

        foreach ($sequences as $sequence => $records) {
            if (count($records) < 2) {
                continue;
            }

            foreach ($records as $record) {
                $this->addIssue(
                    $issues,
                    'error',
                    'duplicate_ideas_source_sequence',
                    $project,
                    $run,
                    'ideas',
                    $record['path'],
                    $record['entity'],
                    "More than one Ideas source decision uses sequence {$sequence}.",
                    'Create a newer valid decision; keep duplicate historical records for investigation.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function ideaSourceDecisionErrors(array $record, string $project): array
    {
        $errors = [];
        if (($record['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }
        if (! is_string($record['id'] ?? null) || trim($record['id']) === '') {
            $errors[] = 'id must be a non-empty string.';
        }
        if (($record['type'] ?? null) !== 'ideas_source_decision') {
            $errors[] = 'type must be ideas_source_decision.';
        }
        if (($record['phase'] ?? null) !== 'ideas') {
            $errors[] = 'phase must be ideas.';
        }

        $mode = $record['mode'] ?? null;
        if (! in_array($mode, ['selected_idea', 'project_idea'], true)) {
            $errors[] = 'mode must be selected_idea or project_idea.';
        }

        $sourceId = $record['source_id'] ?? null;
        if (! is_string($sourceId) || trim($sourceId) === '') {
            $errors[] = 'source_id must be a non-empty string.';
        }
        if (! in_array($record['selected_by'] ?? null, ['human', 'automation'], true)) {
            $errors[] = 'selected_by must be human or automation.';
        }
        if (! is_string($record['reason'] ?? null) || trim($record['reason']) === '') {
            $errors[] = 'reason must be a non-empty string.';
        }
        if (! $this->isTimestamp($record['created_at'] ?? null)) {
            $errors[] = 'created_at must be an ISO-8601 timestamp.';
        }
        if (! is_int($record['sequence'] ?? null) || $record['sequence'] < 1) {
            $errors[] = 'sequence must be a positive integer.';
        }
        $lineageSequence = $record['lineage_sequence'] ?? null;
        if ($lineageSequence !== null
            && (! is_int($lineageSequence) || $lineageSequence < 1)) {
            $errors[] = 'lineage_sequence must be a positive integer when present.';
        }

        $artifactId = $record['artifact_id'] ?? null;
        $selectionId = $record['selection_id'] ?? null;
        if ($mode === 'project_idea'
            && ($sourceId !== $project || $artifactId !== null || $selectionId !== null)) {
            $errors[] = 'project_idea must point only to the project ID with null artifact and selection IDs.';
        }
        if ($mode === 'selected_idea'
            && (! is_string($artifactId)
                || trim($artifactId) === ''
                || $sourceId !== $artifactId
                || ! is_string($selectionId)
                || trim($selectionId) === '')) {
            $errors[] = 'selected_idea must point to matching non-empty source, artifact, and selection IDs.';
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> */
    private function readArtifacts(string $project, string $run, string $phase): array
    {
        $directory = $this->paths->outputsPath($project, $run, $phase);
        if (! File::isDirectory($directory)) {
            return [];
        }

        $artifacts = [];
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            try {
                $document = MarkdownDocument::read($file->getPathname());
            } catch (Throwable $exception) {
                $document = [
                    'metadata' => [],
                    'body' => '',
                    'sections' => [],
                    'parse_error' => $exception->getMessage(),
                ];
            }
            $metadata = $document['metadata'];

            $artifacts[] = $document + [
                'id' => $metadata['id'] ?? $file->getBasename('.md'),
                'title' => $metadata['title'] ?? Str::headline($file->getBasename('.md')),
                'summary' => $metadata['summary'] ?? '',
                'type' => $metadata['artifact_type'] ?? 'markdown',
                'slot' => $metadata['slot'] ?? 'primary',
                'created_at' => $metadata['created_at'] ?? date(DATE_ATOM, $file->getMTime()),
                'path' => $this->relativePath($file->getPathname()),
                'filename' => $file->getFilename(),
                'asset_status' => $metadata['asset_status'] ?? null,
                '_absolute_path' => $file->getPathname(),
            ];
        }

        return $artifacts;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function readSelections(string $project, string $run, string $phase, array &$issues): array
    {
        $directory = $this->paths->selectionsPath($project, $run, $phase);
        if (! File::isDirectory($directory)) {
            return [];
        }

        $records = [];
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            try {
                $metadata = MarkdownDocument::read($file->getPathname())['metadata'];
            } catch (Throwable $exception) {
                $metadata = ['parse_error' => $exception->getMessage()];
                $this->addIssue(
                    $issues,
                    'error',
                    'malformed_selection',
                    $project,
                    $run,
                    $phase,
                    $this->relativePath($file->getPathname()),
                    $file->getBasename('.md'),
                    'The selection Markdown or YAML cannot be parsed.',
                    'Restore or replace the append-only selection record.',
                );
            }

            $records[] = $metadata + [
                '_filename' => $file->getFilename(),
                '_path' => $file->getPathname(),
            ];
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<string>  $requiredSlots
     * @param  array{ready: bool, by_slot: array<string, list<string>>}  $expected
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditArtifacts(
        string $project,
        string $run,
        string $phase,
        array $artifacts,
        array $requiredSlots,
        array $expected,
        array $expectedShotsBySlot,
        ?array $expectedSequencePlan,
        array &$issues,
    ): void {
        foreach ($artifacts as $artifact) {
            $path = is_string($artifact['path'] ?? null) ? $artifact['path'] : null;
            $entity = is_string($artifact['id'] ?? null) ? $artifact['id'] : null;
            $slot = is_string($artifact['slot'] ?? null) ? $artifact['slot'] : '';
            $validation = $this->validator->validate(
                $artifact,
                $phase,
                $expected['by_slot'][$slot] ?? null,
                $requiredSlots,
                $expectedShotsBySlot[$slot] ?? null,
                $expectedSequencePlan,
            );
            $eligibility = $this->eligibility->verify(
                $project,
                $run,
                $phase,
                $artifact,
                $requiredSlots,
                $expected['by_slot'],
                $expectedShotsBySlot,
                $expectedSequencePlan,
            );

            $hardErrors = [];
            foreach ($validation['errors'] as $error) {
                if (str_contains($error, 'sources do not match')
                    || str_contains($error, 'slot is not required')
                    || str_contains($error, 'does not match the selected script shot plan')) {
                    $this->addIssue(
                        $issues,
                        'warning',
                        'stale_artifact',
                        $project,
                        $run,
                        $phase,
                        $path,
                        $entity,
                        $error,
                        'Regenerate from the current selections before promoting this artifact.',
                    );
                } else {
                    $hardErrors[] = $error;
                }
            }

            if ($hardErrors !== []) {
                $this->addIssue(
                    $issues,
                    'error',
                    is_string($artifact['parse_error'] ?? null) ? 'malformed_artifact' : 'invalid_artifact',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    implode(' ', array_values(array_unique($hardErrors))),
                    'Restore the file or generate a new artifact that satisfies the phase contract.',
                );
            }

            foreach ($validation['warnings'] as $warning) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'artifact_warning',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    $warning,
                    'Regenerate the artifact under the current schema when practical.',
                );
            }

            foreach ($eligibility['warnings'] as $warning) {
                if (in_array($warning, $validation['warnings'], true)) {
                    continue;
                }

                $this->addIssue(
                    $issues,
                    'warning',
                    'artifact_warning',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    $warning,
                    'Regenerate the artifact under the current schema when practical.',
                );
            }

            foreach ($eligibility['errors'] as $error) {
                $issue = $this->eligibilityIssue($error);
                if ($issue === null) {
                    continue;
                }

                $this->addIssue(
                    $issues,
                    $issue['severity'] ?? 'error',
                    $issue['code'],
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    $error,
                    $issue['remediation'],
                );
            }

            $this->auditAsset($project, $run, $phase, $artifact, $issues);
        }
    }

    /** @return array{code: string, remediation: string, severity?: 'error'|'warning'}|null */
    private function eligibilityIssue(string $error): ?array
    {
        if (in_array($error, [
            CreativeArtifactEligibilityVerifier::ERROR_SCHEMA_V1_ATTEMPT_REQUEST_MISSING,
            CreativeArtifactEligibilityVerifier::ERROR_SCHEMA_V1_ATTEMPT_RESULT_MISSING,
        ], true)) {
            return [
                'code' => 'artifact_attempt_manifest_missing',
                'remediation' => 'Restore the immutable request/result manifests or regenerate a complete artifact batch.',
            ];
        }

        if ($this->isStaleEligibilityError($error)) {
            return [
                'severity' => 'warning',
                'code' => 'stale_artifact',
                'remediation' => 'Preserve this immutable historical artifact, but regenerate from the current selections before promoting an output.',
            ];
        }

        if (str_contains(strtolower($error), 'generation attempt')
            || str_starts_with($error, 'Committed generation batch')
            || str_starts_with($error, 'Artifact identity')
            || str_starts_with($error, 'Artifact is not a member')
            || str_starts_with($error, 'Artifact generator lineage')
            || str_starts_with($error, 'Artifact source lineage')
            || str_starts_with($error, 'Artifact slot was not reserved')
            || str_starts_with($error, 'Artifact simulation flag')
            || str_starts_with($error, 'Artifact cost_usd')
            || str_starts_with($error, 'Committed artifact')) {
            return [
                'code' => 'artifact_attempt_manifest_invalid',
                'remediation' => 'Keep the artifact ineligible; restore the exact immutable request/result pair or generate a new complete attempt.',
            ];
        }

        return match ($error) {
            'Media binary bytes do not match asset_sha256.' => [
                'code' => 'asset_hash_mismatch',
                'remediation' => 'Restore the exact recorded media bytes or create a new generation attempt.',
            ],
            'Media binary byte size does not match asset_bytes.' => [
                'code' => 'asset_size_mismatch',
                'remediation' => 'Restore the exact recorded media bytes or create a new generation attempt.',
            ],
            'An absent_simulation sidecar unexpectedly resolves to a binary asset.' => [
                'code' => 'unexpected_simulation_asset',
                'remediation' => 'Remove the untracked binary or commit it through a ready media artifact with hash and probe metadata.',
            ],
            'Media asset_path resolves to more than one binary asset.' => [
                'code' => 'ambiguous_asset_path',
                'remediation' => 'Keep one canonical binary and create a new sidecar pointing to its unambiguous relative path.',
            ],
            default => null,
        };
    }

    private function isStaleEligibilityError(string $error): bool
    {
        if ($error === 'Artifact generation attempt required_slots do not match the current effective phase slots.') {
            return true;
        }

        return str_contains($error, ' is invalid: Artifact sources do not match the current effective selection.')
            || str_contains($error, ' is invalid: Artifact slot is not required by the effective phase plan.')
            || str_contains($error, ' is invalid: Media shot snapshot does not match the selected script shot plan:')
            || str_contains($error, ' is invalid: Sequence shot_plan does not match the current selected scene snapshots.')
            || str_contains($error, ' is invalid: Current selected scenes do not resolve to a valid expected sequence shot plan.');
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAsset(string $project, string $run, string $phase, array $artifact, array &$issues): void
    {
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        if (! in_array($metadata['artifact_type'] ?? null, ['image_sidecar', 'video_sidecar'], true)) {
            return;
        }

        $assetPath = $metadata['asset_path'] ?? null;
        if (! is_string($assetPath) || trim($assetPath) === '') {
            return;
        }

        $normalized = str_replace('\\', '/', trim($assetPath));
        $unsafe = str_starts_with($normalized, '/')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) === 1
            || in_array('..', explode('/', $normalized), true);
        if ($unsafe) {
            $this->addIssue(
                $issues,
                'error',
                'asset_path_traversal',
                $project,
                $run,
                $phase,
                is_string($artifact['path'] ?? null) ? $artifact['path'] : null,
                is_string($artifact['id'] ?? null) ? $artifact['id'] : null,
                'The media sidecar has an absolute, external, or parent-traversing asset_path.',
                'Use a relative asset path contained within the phase directory.',
            );

            return;
        }

        $sidecarPath = is_string($artifact['_absolute_path'] ?? null) ? $artifact['_absolute_path'] : '';
        $outputDirectory = dirname($sidecarPath);
        $candidates = [
            $outputDirectory.'/'.$normalized,
            dirname($outputDirectory).'/'.$normalized,
        ];
        $existing = array_values(array_filter($candidates, fn (string $candidate): bool => File::isFile($candidate)));

        foreach ($existing as $candidate) {
            $realAsset = realpath($candidate);
            $realRun = realpath($this->paths->runPath($project, $run));
            if ($realAsset !== false && $realRun !== false && ! str_starts_with($realAsset, $realRun.DIRECTORY_SEPARATOR)) {
                $this->addIssue(
                    $issues,
                    'error',
                    'asset_path_traversal',
                    $project,
                    $run,
                    $phase,
                    is_string($artifact['path'] ?? null) ? $artifact['path'] : null,
                    is_string($artifact['id'] ?? null) ? $artifact['id'] : null,
                    'The media asset resolves outside its creative run.',
                    'Move the asset into the phase directory and update the sidecar path.',
                );
            }
        }

        if (($metadata['asset_status'] ?? null) === 'ready' && $existing === []) {
            $this->addIssue(
                $issues,
                'error',
                'missing_ready_asset',
                $project,
                $run,
                $phase,
                is_string($artifact['path'] ?? null) ? $artifact['path'] : null,
                is_string($artifact['id'] ?? null) ? $artifact['id'] : null,
                'The sidecar declares a ready media asset, but the binary file is missing.',
                'Restore the binary at asset_path or create a new generation attempt.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $artifacts
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditSelections(
        string $project,
        string $run,
        string $phase,
        array $records,
        array $artifacts,
        array $state,
        array &$issues,
    ): void {
        foreach ($records as $record) {
            $path = is_string($record['_path'] ?? null) ? $this->relativePath($record['_path']) : null;
            $entity = is_string($record['id'] ?? null) ? $record['id'] : null;
            if (is_string($record['parse_error'] ?? null)) {
                continue;
            }

            $recordErrors = [];
            foreach (['id', 'type', 'phase', 'slot', 'artifact_id', 'artifact_path', 'created_at'] as $field) {
                if (! is_string($record[$field] ?? null) || trim((string) $record[$field]) === '') {
                    $recordErrors[] = "Selection is missing {$field}.";
                }
            }
            if (($record['type'] ?? null) !== 'selection') {
                $recordErrors[] = 'Selection type must be selection.';
            }
            if (($record['phase'] ?? null) !== $phase) {
                $recordErrors[] = 'Selection phase does not match its directory.';
            }
            if (array_key_exists('selected_by', $record)
                && ! in_array($record['selected_by'], ['human', 'automation'], true)) {
                $recordErrors[] = 'Selection selected_by must be human or automation.';
            }
            if ($recordErrors !== []) {
                $this->addIssue(
                    $issues,
                    'error',
                    'invalid_selection',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    implode(' ', array_values(array_unique($recordErrors))),
                    'Preserve the record for evidence and create a corrected newer selection.',
                );
            }

            $artifactId = is_string($record['artifact_id'] ?? null) ? $record['artifact_id'] : '';
            $matches = array_values(array_filter(
                $artifacts,
                fn (array $artifact): bool => ($artifact['id'] ?? null) === $artifactId,
            ));
            if ($artifactId !== '' && $matches === []) {
                $this->addIssue(
                    $issues,
                    'error',
                    'orphan_selection',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    "Selection points to missing artifact [{$artifactId}].",
                    'Restore the immutable artifact or create a newer selection for an existing valid artifact.',
                );
            } elseif (count($matches) === 1
                && ($record['artifact_path'] ?? null) !== ($matches[0]['path'] ?? null)) {
                $this->addIssue(
                    $issues,
                    'error',
                    'selection_path_mismatch',
                    $project,
                    $run,
                    $phase,
                    $path,
                    $entity,
                    'Selection artifact_path does not match the uniquely resolved artifact.',
                    'Create a newer selection using the artifact path reported by the workspace.',
                );
            }

            if (($record['selected_by'] ?? null) === 'automation') {
                $this->auditAutomaticSelection(
                    $project,
                    $run,
                    $phase,
                    $record,
                    $path,
                    $entity,
                    $issues,
                );
            }
        }

        $invalid = is_array($state['invalid'] ?? null) ? $state['invalid'] : [];
        foreach ($invalid as $item) {
            if (! is_array($item) || ! is_array($item['selection'] ?? null)) {
                continue;
            }
            $selection = $item['selection'];
            $errors = is_array($item['errors'] ?? null)
                ? array_values(array_filter($item['errors'], 'is_string'))
                : ['Selection is invalid.'];
            $message = implode(' ', $errors);
            $code = match (true) {
                str_contains($message, 'missing') && str_contains($message, 'artifact') => 'orphan_selection',
                str_contains($message, 'artifact_path') => 'selection_path_mismatch',
                str_contains($message, 'source'),
                str_contains($message, 'previous phase'),
                str_contains($message, 'slot'),
                str_contains($message, 'selected script shot plan') => 'stale_selection',
                default => 'invalid_selection',
            };

            $this->addIssue(
                $issues,
                'error',
                $code,
                $project,
                $run,
                $phase,
                is_string($selection['_path'] ?? null) ? $this->relativePath($selection['_path']) : null,
                is_string($selection['id'] ?? null) ? $selection['id'] : null,
                $message,
                'Create a newer selection for an existing artifact with current lineage; do not delete history.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAutomaticSelection(
        string $project,
        string $run,
        string $phase,
        array $selection,
        ?string $selectionPath,
        ?string $selectionId,
        array &$issues,
    ): void {
        $evaluationId = is_string($selection['evaluation_id'] ?? null)
            ? trim($selection['evaluation_id'])
            : '';
        if ($evaluationId === '') {
            $this->addIssue(
                $issues,
                'error',
                'automatic_selection_evaluation_missing',
                $project,
                $run,
                $phase,
                $selectionPath,
                $selectionId,
                'Automatic selection has no evaluation_id.',
                'Create a newer selection only after a passed immutable evaluation recommends that artifact for this slot.',
            );

            return;
        }

        try {
            $this->paths->assertIdentifier($evaluationId);
        } catch (Throwable) {
            $this->addIssue(
                $issues,
                'error',
                'automatic_selection_evaluation_invalid',
                $project,
                $run,
                $phase,
                $selectionPath,
                $selectionId,
                "Automatic selection evaluation ID [{$evaluationId}] is not a safe workspace identifier.",
                'Create a newer selection referencing an immutable evaluation in this phase.',
            );

            return;
        }

        $phaseRoot = dirname($this->paths->outputsPath($project, $run, $phase));
        $candidatePaths = [
            $phaseRoot.'/evaluations/'.$evaluationId.'.md',
            $this->paths->runPath($project, $run).'/automation/evaluations/'.$phase.'/'.$evaluationId.'.md',
        ];
        $evaluationPath = collect(array_unique($candidatePaths))
            ->first(fn (string $candidate): bool => File::isFile($candidate));
        if (! is_string($evaluationPath)) {
            $this->addIssue(
                $issues,
                'error',
                'automatic_selection_evaluation_missing',
                $project,
                $run,
                $phase,
                $selectionPath,
                $selectionId,
                "Automatic selection references missing evaluation [{$evaluationId}].",
                'Restore the immutable evaluation or create a newer selection backed by a passed evaluation.',
            );

            return;
        }

        $document = $this->auditSpecialMarkdown(
            $evaluationPath,
            'automatic_selection_evaluation_invalid',
            $project,
            $run,
            $phase,
            $evaluationId,
            $issues,
        );
        if (! is_array($document)) {
            return;
        }

        $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
        $candidateIds = $metadata['candidate_ids'] ?? null;
        $recommendations = $metadata['recommended_ids_by_slot'] ?? $metadata['recommendations'] ?? null;
        $contractIsValid = ($metadata['id'] ?? null) === $evaluationId
            && ($metadata['type'] ?? null) === 'artifact_evaluation'
            && ($metadata['phase'] ?? null) === $phase
            && is_bool($metadata['passed'] ?? null)
            && $this->isStringList($candidateIds)
            && is_array($recommendations);
        if (! $contractIsValid) {
            $this->addIssue(
                $issues,
                'error',
                'automatic_selection_evaluation_invalid',
                $project,
                $run,
                $phase,
                $this->relativePath($evaluationPath),
                $evaluationId,
                'Automatic selection does not resolve to a valid evaluation with the same identity and phase.',
                'Create a new evaluation and selection pair; preserve the invalid records as evidence.',
            );

            return;
        }

        $slot = is_string($selection['slot'] ?? null) ? $selection['slot'] : '';
        $artifactId = is_string($selection['artifact_id'] ?? null) ? $selection['artifact_id'] : '';
        $recommendedId = $slot !== '' && array_key_exists($slot, $recommendations)
            ? $recommendations[$slot]
            : null;
        if (($metadata['passed'] ?? null) !== true
            || ! in_array($artifactId, $candidateIds, true)
            || $recommendedId !== $artifactId) {
            $this->addIssue(
                $issues,
                'error',
                'automatic_selection_not_recommended',
                $project,
                $run,
                $phase,
                $selectionPath,
                $selectionId,
                "Automatic selection of [{$artifactId}] for slot [{$slot}] is not authorized by passed evaluation [{$evaluationId}].",
                'Create a newer selection for the exact artifact recommended for this slot by a passed evaluation.',
            );
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifactsByPhase
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditDuplicateArtifactIds(string $project, string $run, array $artifactsByPhase, array &$issues): void
    {
        $byId = [];
        foreach ($artifactsByPhase as $phase => $artifacts) {
            foreach ($artifacts as $artifact) {
                $id = is_string($artifact['metadata']['id'] ?? null) ? $artifact['metadata']['id'] : null;
                if ($id !== null && $id !== '') {
                    $byId[$id][] = ['phase' => $phase, 'artifact' => $artifact];
                }
            }
        }

        foreach ($byId as $id => $occurrences) {
            if (count($occurrences) < 2) {
                continue;
            }
            foreach ($occurrences as $occurrence) {
                $artifact = $occurrence['artifact'];
                $this->addIssue(
                    $issues,
                    'error',
                    'duplicate_artifact_id',
                    $project,
                    $run,
                    $occurrence['phase'],
                    is_string($artifact['path'] ?? null) ? $artifact['path'] : null,
                    $id,
                    "Artifact ID [{$id}] appears more than once in this run.",
                    'Preserve both files, but regenerate one artifact with a unique immutable ID before selecting it.',
                );
            }
        }
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditAttempts(string $project, string $run, string $phase, array &$issues): void
    {
        $phaseRoot = dirname($this->paths->outputsPath($project, $run, $phase));
        $roots = [
            $phaseRoot.'/attempts',
            $this->paths->runPath($project, $run).'/attempts/'.$phase,
        ];

        foreach (array_unique($roots) as $attemptsRoot) {
            if (! File::isDirectory($attemptsRoot)) {
                continue;
            }

            foreach (File::directories($attemptsRoot) as $attemptDirectory) {
                $attemptId = basename($attemptDirectory);
                $requestPath = $attemptDirectory.'/request.md';
                $resultPath = $attemptDirectory.'/result.md';
                $requestMetadata = null;
                $resultMetadata = null;

                if (! File::isFile($requestPath)) {
                    $this->addIssue(
                        $issues,
                        'error',
                        'malformed_attempt',
                        $project,
                        $run,
                        $phase,
                        $this->relativePath($attemptDirectory),
                        $attemptId,
                        'Generation attempt directory has no request.md.',
                        'Restore the attempt request or quarantine the incomplete attempt directory.',
                    );
                } else {
                    $request = $this->auditSpecialMarkdown(
                        $requestPath,
                        'malformed_attempt',
                        $project,
                        $run,
                        $phase,
                        $attemptId,
                        $issues,
                    );
                    if (is_array($request)) {
                        $requestMetadata = $request['metadata'];
                        $this->auditAttemptRequestMetadata(
                            $project,
                            $run,
                            $phase,
                            $attemptId,
                            $requestPath,
                            $request['metadata'],
                            $issues,
                        );
                    }
                }

                if (! File::isFile($resultPath)) {
                    $this->addIssue(
                        $issues,
                        'error',
                        'incomplete_attempt',
                        $project,
                        $run,
                        $phase,
                        $this->relativePath($attemptDirectory),
                        $attemptId,
                        'Generation attempt has a request but no terminal result manifest.',
                        'Resume the orchestrator or record an interrupted/failed terminal result without deleting partial evidence.',
                    );
                } else {
                    $result = $this->auditSpecialMarkdown($resultPath, 'malformed_attempt', $project, $run, $phase, $attemptId, $issues);
                    $status = is_array($result)
                        ? $this->auditAttemptResultMetadata(
                            $project,
                            $run,
                            $phase,
                            $attemptId,
                            $resultPath,
                            $result['metadata'],
                            $issues,
                        )
                        : null;
                    if (is_array($result)) {
                        $resultMetadata = $result['metadata'];
                    }
                    if ($status === 'committed') {
                        $this->auditCommittedAttempt(
                            $project,
                            $run,
                            $phase,
                            $attemptId,
                            $result['metadata'],
                            $issues,
                        );
                    }
                }

                if (is_array($requestMetadata) && is_array($resultMetadata)) {
                    $this->auditAttemptManifestConsistency(
                        $project,
                        $run,
                        $phase,
                        $attemptId,
                        $requestMetadata,
                        $resultMetadata,
                        $issues,
                    );
                }
            }

            foreach (File::files($attemptsRoot) as $manifest) {
                if ($manifest->getExtension() !== 'md') {
                    continue;
                }
                $document = $this->auditSpecialMarkdown(
                    $manifest->getPathname(),
                    'malformed_attempt',
                    $project,
                    $run,
                    $phase,
                    $manifest->getBasename('.md'),
                    $issues,
                );
                $status = is_array($document) ? ($document['metadata']['status'] ?? null) : null;
                if (! is_string($status) || in_array($status, ['pending', 'running', 'started'], true)) {
                    $this->addIssue(
                        $issues,
                        'error',
                        'incomplete_attempt',
                        $project,
                        $run,
                        $phase,
                        $this->relativePath($manifest->getPathname()),
                        $manifest->getBasename('.md'),
                        'Generation attempt manifest has no terminal status.',
                        'Record a committed, failed, cancelled, or interrupted result.',
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAttemptRequestMetadata(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $path,
        array $metadata,
        array &$issues,
    ): void {
        $errors = [];
        if (($metadata['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1';
        }
        if (($metadata['id'] ?? null) !== $attemptId || ($metadata['attempt_id'] ?? null) !== $attemptId) {
            $errors[] = 'id and attempt_id must match the attempt directory';
        }
        if (($metadata['type'] ?? null) !== 'generation_attempt_request') {
            $errors[] = 'type must be generation_attempt_request';
        }
        if (($metadata['phase'] ?? null) !== $phase) {
            $errors[] = 'phase must match the phase directory';
        }
        if (! $this->isTimestamp($metadata['started_at'] ?? null)) {
            $errors[] = 'started_at must be an ISO-8601 timestamp';
        }
        if (! is_string($metadata['driver'] ?? null) || trim($metadata['driver']) === '') {
            $errors[] = 'driver must be a non-empty string';
        }
        if (! $this->isStringList($metadata['source_artifact_ids'] ?? null)) {
            $errors[] = 'source_artifact_ids must be a list of non-empty strings';
        }
        if (! $this->isNonEmptyStringList($metadata['required_slots'] ?? null)) {
            $errors[] = 'required_slots must be a non-empty list of non-empty strings';
        }
        if (! is_int($metadata['expected_output_count'] ?? null) || $metadata['expected_output_count'] < 1) {
            $errors[] = 'expected_output_count must be a positive integer';
        }
        if (! is_int($metadata['reserved_simulation_units'] ?? null) || $metadata['reserved_simulation_units'] < 0) {
            $errors[] = 'reserved_simulation_units must be a non-negative integer';
        }
        if (! array_key_exists('cost_limit_usd', $metadata)
            || ($metadata['cost_limit_usd'] !== null
                && (! is_numeric($metadata['cost_limit_usd']) || (float) $metadata['cost_limit_usd'] < 0))) {
            $errors[] = 'cost_limit_usd must be a non-negative number or null';
        }

        if ($errors !== []) {
            $this->addIssue(
                $issues,
                'error',
                'attempt_request_invalid',
                $project,
                $run,
                $phase,
                $this->relativePath($path),
                $attemptId,
                'Generation attempt request metadata is invalid: '.implode('; ', $errors).'.',
                'Restore the immutable request manifest or keep the attempt ineligible and create a new attempt.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAttemptResultMetadata(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $path,
        array $metadata,
        array &$issues,
    ): ?string {
        $errors = [];
        if (($metadata['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1';
        }
        if (($metadata['id'] ?? null) !== $attemptId.'--result'
            || ($metadata['attempt_id'] ?? null) !== $attemptId) {
            $errors[] = 'id and attempt_id must match the attempt directory';
        }
        if (($metadata['type'] ?? null) !== 'generation_attempt_result') {
            $errors[] = 'type must be generation_attempt_result';
        }
        if (($metadata['phase'] ?? null) !== $phase) {
            $errors[] = 'phase must match the phase directory';
        }
        if (! $this->isTimestamp($metadata['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be an ISO-8601 timestamp';
        }
        if (! is_string($metadata['driver'] ?? null) || trim($metadata['driver']) === '') {
            $errors[] = 'driver must be a non-empty string';
        }

        $artifactIds = $metadata['artifact_ids'] ?? null;
        if (! $this->isStringList($artifactIds)) {
            $errors[] = 'artifact_ids must be a list of non-empty strings';
        }
        if (! is_int($metadata['output_count'] ?? null) || $metadata['output_count'] < 0) {
            $errors[] = 'output_count must be a non-negative integer';
        } elseif (is_array($artifactIds) && $metadata['output_count'] !== count($artifactIds)) {
            $errors[] = 'output_count must equal the number of artifact_ids';
        }
        if (! is_int($metadata['expected_output_count'] ?? null) || $metadata['expected_output_count'] < 1) {
            $errors[] = 'expected_output_count must be a positive integer';
        }
        if (! is_int($metadata['simulation_units'] ?? null) || $metadata['simulation_units'] < 0) {
            $errors[] = 'simulation_units must be a non-negative integer';
        }
        if (! array_key_exists('cost_usd', $metadata)
            || ($metadata['cost_usd'] !== null
                && (! is_numeric($metadata['cost_usd']) || (float) $metadata['cost_usd'] < 0))) {
            $errors[] = 'cost_usd must be a non-negative number or null';
        }

        if ($errors !== []) {
            $this->addIssue(
                $issues,
                'error',
                'attempt_result_invalid',
                $project,
                $run,
                $phase,
                $this->relativePath($path),
                $attemptId,
                'Generation attempt result metadata is invalid: '.implode('; ', $errors).'.',
                'Keep the attempt ineligible and restore its terminal result from trustworthy evidence.',
            );
        }

        $status = $metadata['status'] ?? null;
        $terminalStatuses = ['committed', 'failed', 'cancelled', 'interrupted'];
        if (is_string($status) && in_array($status, ['pending', 'running', 'started'], true)) {
            $this->addIssue(
                $issues,
                'error',
                'incomplete_attempt',
                $project,
                $run,
                $phase,
                $this->relativePath($path),
                $attemptId,
                "Generation attempt result remains in non-terminal status [{$status}].",
                'Resume or terminate the attempt through the orchestrator.',
            );

            return null;
        }
        if (! is_string($status) || ! in_array($status, $terminalStatuses, true)) {
            $renderedStatus = is_scalar($status) ? (string) $status : 'missing';
            $this->addIssue(
                $issues,
                'error',
                'attempt_status_invalid',
                $project,
                $run,
                $phase,
                $this->relativePath($path),
                $attemptId,
                "Generation attempt result has unsupported terminal status [{$renderedStatus}].",
                'Record a committed, failed, cancelled, or interrupted result.',
            );

            return null;
        }

        return $status;
    }

    /**
     * Cross-check fields whose truth exists only in the immutable request/result pair.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditAttemptManifestConsistency(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        array $request,
        array $result,
        array &$issues,
    ): void {
        $attemptPath = $this->relativePath(
            dirname($this->paths->outputsPath($project, $run, $phase)).'/attempts/'.$attemptId,
        );
        $contractErrors = [];
        if (is_string($request['driver'] ?? null)
            && is_string($result['driver'] ?? null)
            && $request['driver'] !== $result['driver']) {
            $contractErrors[] = 'request and result drivers do not match';
        }
        if (is_int($request['expected_output_count'] ?? null)
            && is_int($result['expected_output_count'] ?? null)
            && $request['expected_output_count'] !== $result['expected_output_count']) {
            $contractErrors[] = 'request and result expected_output_count values do not match';
        }

        $requiredSlots = $request['required_slots'] ?? null;
        if (is_array($requiredSlots)
            && array_is_list($requiredSlots)
            && collect($requiredSlots)->every(fn (mixed $slot): bool => is_string($slot))) {
            $phaseCount = match ($phase) {
                'ideas', 'concepts', 'brief-script', 'sequence' => 3,
                'images' => count($requiredSlots) * 2,
                'scenes' => count($requiredSlots),
                default => null,
            };
            if ($phaseCount !== null
                && is_int($request['expected_output_count'] ?? null)
                && $request['expected_output_count'] !== $phaseCount) {
                $contractErrors[] = 'request expected_output_count does not match the phase/slot contract';
            }
        }

        if ($contractErrors !== []) {
            $this->addIssue(
                $issues,
                'error',
                'attempt_manifest_mismatch',
                $project,
                $run,
                $phase,
                $attemptPath,
                $attemptId,
                'Generation attempt request/result contract is inconsistent: '.implode('; ', $contractErrors).'.',
                'Keep the attempt ineligible and create a new immutable attempt with one consistent request/result pair.',
            );
        }

        $budgetErrors = [];
        if (is_int($request['reserved_simulation_units'] ?? null)
            && is_int($result['simulation_units'] ?? null)
            && $result['simulation_units'] > $request['reserved_simulation_units']) {
            $budgetErrors[] = 'simulation_units exceed the request reservation';
        }
        if (array_key_exists('cost_limit_usd', $request)
            && $request['cost_limit_usd'] !== null
            && is_numeric($request['cost_limit_usd'])
            && (! is_numeric($result['cost_usd'] ?? null)
                || (float) $result['cost_usd'] > (float) $request['cost_limit_usd'])) {
            $budgetErrors[] = 'cost_usd exceeds or cannot prove compliance with cost_limit_usd';
        }
        if ($budgetErrors !== []) {
            $this->addIssue(
                $issues,
                'error',
                'attempt_budget_exceeded',
                $project,
                $run,
                $phase,
                $attemptPath,
                $attemptId,
                'Generation attempt exceeded its immutable reservation: '.implode('; ', $budgetErrors).'.',
                'Do not select its artifacts; create a new attempt under an explicit sufficient budget.',
            );
        }
    }

    private function isStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && collect($value)->every(fn (mixed $item): bool => is_string($item) && trim($item) !== '');
    }

    private function isNonEmptyStringList(mixed $value): bool
    {
        return $this->isStringList($value) && $value !== [];
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

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditCommittedAttempt(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        array $metadata,
        array &$issues,
    ): void {
        $artifactIds = $metadata['artifact_ids'] ?? null;
        if (! is_array($artifactIds) || ! array_is_list($artifactIds)) {
            $this->addIssue(
                $issues,
                'error',
                'attempt_artifact_manifest_invalid',
                $project,
                $run,
                $phase,
                null,
                $attemptId,
                'Committed attempt artifact_ids must be a list.',
                'Restore the committed result manifest from the generated artifacts and hashes.',
            );

            return;
        }

        $ids = [];
        foreach ($artifactIds as $artifactId) {
            if (! is_string($artifactId) || $artifactId === '') {
                $this->addIssue(
                    $issues,
                    'error',
                    'attempt_artifact_manifest_invalid',
                    $project,
                    $run,
                    $phase,
                    null,
                    $attemptId,
                    'Committed attempt artifact_ids contains an invalid identifier.',
                    'Restore the exact immutable artifact IDs in the result manifest.',
                );

                return;
            }
            $ids[] = $artifactId;
        }

        if (count($ids) !== count(array_unique($ids))
            || (int) ($metadata['output_count'] ?? -1) !== count($ids)
            || (int) ($metadata['expected_output_count'] ?? -1) !== count($ids)) {
            $this->addIssue(
                $issues,
                'error',
                'attempt_output_count_mismatch',
                $project,
                $run,
                $phase,
                null,
                $attemptId,
                'Committed attempt IDs and output counts do not describe one complete batch.',
                'Do not select the batch; restore the result manifest or generate a new attempt.',
            );
        }

        $hashes = is_array($metadata['artifact_sha256'] ?? null)
            ? $metadata['artifact_sha256']
            : [];
        foreach ($ids as $artifactId) {
            $artifactPath = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
            if (! File::isFile($artifactPath)) {
                $this->addIssue(
                    $issues,
                    'error',
                    'committed_artifact_missing',
                    $project,
                    $run,
                    $phase,
                    $this->relativePath($artifactPath),
                    $artifactId,
                    'Committed attempt references an artifact file that is missing.',
                    'Restore the immutable artifact or generate a new complete attempt.',
                );

                continue;
            }

            $expectedHash = $hashes[$artifactId] ?? null;
            $actualHash = hash_file('sha256', $artifactPath);
            if (! is_string($expectedHash) || ! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'attempt_artifact_hash_missing',
                    $project,
                    $run,
                    $phase,
                    $this->relativePath($artifactPath),
                    $artifactId,
                    'Committed attempt has no valid SHA-256 for this artifact.',
                    'Regenerate under the current attempt schema when practical.',
                );
            } elseif (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                $this->addIssue(
                    $issues,
                    'error',
                    'attempt_artifact_hash_mismatch',
                    $project,
                    $run,
                    $phase,
                    $this->relativePath($artifactPath),
                    $artifactId,
                    'Artifact bytes no longer match the committed attempt hash.',
                    'Treat the artifact as corrupted or modified and generate a new immutable attempt.',
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<array<string, mixed>>  $issues
     */
    private function auditEvaluations(
        string $project,
        string $run,
        string $phase,
        array $artifacts,
        array &$issues,
    ): void {
        $phaseRoot = dirname($this->paths->outputsPath($project, $run, $phase));
        $roots = [
            $phaseRoot.'/evaluations',
            $this->paths->runPath($project, $run).'/automation/evaluations/'.$phase,
        ];
        $artifactIds = array_values(array_filter(array_map(
            fn (array $artifact): ?string => is_string($artifact['id'] ?? null) ? $artifact['id'] : null,
            $artifacts,
        )));

        foreach (array_unique($roots) as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach (File::files($root) as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }
                $document = $this->auditSpecialMarkdown(
                    $file->getPathname(),
                    'malformed_evaluation',
                    $project,
                    $run,
                    $phase,
                    $file->getBasename('.md'),
                    $issues,
                );
                if (! is_array($document)) {
                    continue;
                }

                $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
                $candidateIds = $metadata['candidate_ids'] ?? null;
                $recommendations = $metadata['recommended_ids_by_slot'] ?? $metadata['recommendations'] ?? null;
                $valid = ($metadata['type'] ?? null) === 'artifact_evaluation'
                    && is_string($metadata['id'] ?? null)
                    && ($metadata['phase'] ?? null) === $phase
                    && is_array($candidateIds)
                    && array_is_list($candidateIds)
                    && is_array($recommendations)
                    && is_bool($metadata['passed'] ?? null);
                if (! $valid) {
                    $this->addIssue(
                        $issues,
                        'error',
                        'malformed_evaluation',
                        $project,
                        $run,
                        $phase,
                        $this->relativePath($file->getPathname()),
                        is_string($metadata['id'] ?? null) ? $metadata['id'] : $file->getBasename('.md'),
                        'Evaluation metadata does not satisfy the inspectable evaluation contract.',
                        'Regenerate the evaluation with candidate IDs, slot recommendations, pass state, and exact phase.',
                    );

                    continue;
                }

                foreach ($candidateIds as $candidateId) {
                    if (! is_string($candidateId) || ! in_array($candidateId, $artifactIds, true)) {
                        $this->addIssue(
                            $issues,
                            'error',
                            'evaluation_candidate_missing',
                            $project,
                            $run,
                            $phase,
                            $this->relativePath($file->getPathname()),
                            (string) $metadata['id'],
                            'Evaluation references a candidate that is absent from this phase.',
                            'Create a new evaluation from the current immutable candidate set.',
                        );
                    }
                }
                foreach ($recommendations as $recommendedId) {
                    if (! is_string($recommendedId) || ! in_array($recommendedId, $candidateIds, true)) {
                        $this->addIssue(
                            $issues,
                            'error',
                            'evaluation_recommendation_invalid',
                            $project,
                            $run,
                            $phase,
                            $this->relativePath($file->getPathname()),
                            (string) $metadata['id'],
                            'Evaluation recommends an artifact outside its candidate set.',
                            'Create a corrected immutable evaluation; do not edit selection history.',
                        );
                    }
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $issues */
    private function auditAutomation(string $project, string $run, array &$issues): void
    {
        $root = $this->paths->runPath($project, $run).'/automation';
        if (! File::isDirectory($root)) {
            return;
        }

        $policyExists = File::isFile($root.'/policy.md');
        $stateExists = File::isFile($root.'/state.md');
        if ($policyExists !== $stateExists) {
            $missing = $policyExists ? 'state.md' : 'policy.md';
            $present = $policyExists ? 'policy.md' : 'state.md';
            $this->addIssue(
                $issues,
                'error',
                'automation_bootstrap_incomplete',
                $project,
                $run,
                null,
                $this->relativePath($root),
                'automation',
                "Automation bootstrap has {$present} but is missing {$missing}.",
                $policyExists
                    ? 'Recover the derived state from the immutable policy before resuming automation.'
                    : 'Restore the immutable policy before trusting or resuming the derived state.',
            );
        }

        $policyDocument = $policyExists
            ? $this->auditSpecialMarkdown(
                $root.'/policy.md',
                'malformed_automation_state',
                $project,
                $run,
                null,
                'policy',
                $issues,
            )
            : null;
        $stateDocument = $stateExists
            ? $this->auditSpecialMarkdown(
                $root.'/state.md',
                'malformed_automation_state',
                $project,
                $run,
                null,
                'state',
                $issues,
            )
            : null;

        $policyValid = false;
        if (is_array($policyDocument)) {
            try {
                $this->automationStates->policy($project, $run);
                $policyValid = true;
            } catch (Throwable $exception) {
                $this->addIssue(
                    $issues,
                    'error',
                    'automation_policy_invalid',
                    $project,
                    $run,
                    null,
                    $this->relativePath($root.'/policy.md'),
                    'policy',
                    'Automation policy failed canonical validation: '.$exception->getMessage(),
                    'Restore the immutable policy before trusting or resuming this automatic run.',
                );
            }
        }

        $checkpoint = null;
        if (is_array($stateDocument) && $policyValid) {
            try {
                $checkpoint = $this->automationStates->state($project, $run);
            } catch (Throwable $exception) {
                $this->addIssue(
                    $issues,
                    'error',
                    'malformed_automation_state',
                    $project,
                    $run,
                    null,
                    $this->relativePath($root.'/state.md'),
                    'state',
                    'Automation checkpoint failed canonical validation: '.$exception->getMessage(),
                    'Rebuild the mutable checkpoint from immutable automation evidence.',
                );
            }
        }

        $jsonState = $root.'/state.json';
        if (File::isFile($jsonState)) {
            try {
                $decoded = json_decode(File::get($jsonState), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! is_string($decoded['status'] ?? null)) {
                    throw new InvalidArgumentException('Automation state JSON has no status.');
                }
            } catch (Throwable) {
                $this->addIssue(
                    $issues,
                    'error',
                    'malformed_automation_state',
                    $project,
                    $run,
                    null,
                    $this->relativePath($jsonState),
                    'state',
                    'Automation state JSON cannot be parsed or has no status.',
                    'Delete only the derived cache after confirming immutable events can rebuild it.',
                );
            }
        }

        if (! $policyValid) {
            return;
        }

        try {
            $projection = $this->automationProjector->project($project, $run);
        } catch (Throwable $exception) {
            $this->addIssue(
                $issues,
                'error',
                'automation_replay_invalid',
                $project,
                $run,
                null,
                $this->relativePath($root.'/events'),
                'automation',
                'Canonical automation replay rejected the immutable evidence: '.$exception->getMessage(),
                'Preserve the evidence, repair or document the contradictory record, and replay before resuming automation.',
            );

            return;
        }

        if (is_array($checkpoint)
            && ! $this->automationProjector->consistent($checkpoint, $projection['state'])) {
            $this->addIssue(
                $issues,
                'error',
                'automation_checkpoint_diverged',
                $project,
                $run,
                null,
                $this->relativePath($root.'/state.md'),
                'state',
                'The mutable automation checkpoint does not match canonical replay of immutable events, attempts, evaluations, and selections.',
                'Reconcile the checkpoint from canonical replay before resuming automation.',
            );
        }

        foreach ($projection['issues'] as $projectionIssue) {
            $this->addIssue(
                $issues,
                'error',
                'automation_filesystem_truth_mismatch',
                $project,
                $run,
                is_string($projection['state']['current_phase'] ?? null)
                    ? $projection['state']['current_phase']
                    : null,
                $this->relativePath($root),
                'automation',
                $projectionIssue,
                'Reconcile automation with current artifact and selection truth before treating the run as complete.',
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $states
     * @param  array<string, list<string>>  $requiredSlotsByPhase
     * @param  list<array<string, mixed>>  $issues
     */
    private function reportIncompletePhases(
        string $project,
        string $run,
        string $startPhase,
        array $states,
        array $requiredSlotsByPhase,
        array &$issues,
    ): void {
        $phaseKeys = array_keys($this->phases->all());
        $startIndex = array_search($startPhase, $phaseKeys, true);
        $startIndex = is_int($startIndex) ? $startIndex : 0;

        foreach ($phaseKeys as $index => $phase) {
            if ($index < $startIndex || ($phase === 'ideas' && ($states[$phase]['has_records'] ?? false) === false && $startPhase !== 'ideas')) {
                continue;
            }
            if (($states[$phase]['complete'] ?? false) === true) {
                continue;
            }

            $resolved = is_array($states[$phase]['resolved'] ?? null) ? count($states[$phase]['resolved']) : 0;
            $required = count($requiredSlotsByPhase[$phase] ?? []);
            $message = $required === 0
                ? 'Manual phase is incomplete because its upstream plan has not produced required slots.'
                : "Manual phase is incomplete ({$resolved} of {$required} required selections are current).";
            $this->addIssue(
                $issues,
                'info',
                'phase_incomplete',
                $project,
                $run,
                $phase,
                $this->relativePath(dirname($this->paths->outputsPath($project, $run, $phase))),
                $phase,
                $message,
                'Continue the manual workflow when ready; incompleteness alone is not corruption.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>|null
     */
    private function auditIdentityDocument(
        string $path,
        string $expectedType,
        string $expectedId,
        string $project,
        ?string $run,
        array &$issues,
    ): ?array {
        if (! File::isFile($path)) {
            $this->addIssue(
                $issues,
                'error',
                'document_missing',
                $project,
                $run,
                null,
                $this->relativePath($path),
                $expectedId,
                'Required creative identity document is missing.',
                'Restore the document from version control or backup.',
            );

            return null;
        }

        try {
            $document = MarkdownDocument::read($path);
        } catch (Throwable) {
            $this->addIssue(
                $issues,
                'error',
                'document_malformed',
                $project,
                $run,
                null,
                $this->relativePath($path),
                $expectedId,
                'Required creative identity document cannot be parsed.',
                'Restore valid YAML front matter and Markdown without changing its identity.',
            );

            return null;
        }

        $metadata = $document['metadata'];
        if (($metadata['id'] ?? null) !== $expectedId || ($metadata['type'] ?? null) !== $expectedType) {
            $this->addIssue(
                $issues,
                'error',
                'document_identity_invalid',
                $project,
                $run,
                null,
                $this->relativePath($path),
                $expectedId,
                'Creative identity document type or ID does not match its directory.',
                'Restore the original identity metadata; do not assign an existing directory a new identity.',
            );
        }

        return $document;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>|null
     */
    private function auditSpecialMarkdown(
        string $path,
        string $code,
        string $project,
        string $run,
        ?string $phase,
        string $entity,
        array &$issues,
    ): ?array {
        try {
            return MarkdownDocument::read($path);
        } catch (Throwable) {
            $this->addIssue(
                $issues,
                'error',
                $code,
                $project,
                $run,
                $phase,
                $this->relativePath($path),
                $entity,
                'Markdown or YAML cannot be parsed.',
                'Restore the immutable record from backup or create a documented replacement.',
            );

            return null;
        }
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->paths->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function addIssue(
        array &$issues,
        string $severity,
        string $code,
        ?string $project,
        ?string $run,
        ?string $phase,
        ?string $path,
        ?string $entity,
        string $message,
        string $remediation,
    ): void {
        $issues[] = compact(
            'severity',
            'code',
            'project',
            'run',
            'phase',
            'path',
            'entity',
            'message',
            'remediation',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array{
     *     scope: array{project: string|null, run: string|null},
     *     summary: array{total: int, errors: int, warnings: int, info: int},
     *     issues: list<array{severity: 'error'|'warning'|'info', code: string, project: string|null, run: string|null, phase: string|null, path: string|null, entity: string|null, message: string, remediation: string}>
     * }
     */
    private function report(?string $project, ?string $run, array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            $key = implode('|', array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $issue,
            ));
            $unique[$key] = $issue;
        }
        $issues = array_values($unique);

        $severityOrder = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, function (array $left, array $right) use ($severityOrder): int {
            return [
                $severityOrder[$left['severity']] ?? 9,
                (string) ($left['project'] ?? ''),
                (string) ($left['run'] ?? ''),
                (string) ($left['phase'] ?? ''),
                (string) ($left['path'] ?? ''),
                (string) ($left['code'] ?? ''),
            ] <=> [
                $severityOrder[$right['severity']] ?? 9,
                (string) ($right['project'] ?? ''),
                (string) ($right['run'] ?? ''),
                (string) ($right['phase'] ?? ''),
                (string) ($right['path'] ?? ''),
                (string) ($right['code'] ?? ''),
            ];
        });

        $errors = count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'error'));
        $warnings = count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'warning'));
        $info = count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'info'));

        /** @var list<array{severity: 'error'|'warning'|'info', code: string, project: string|null, run: string|null, phase: string|null, path: string|null, entity: string|null, message: string, remediation: string}> $issues */
        return [
            'scope' => ['project' => $project, 'run' => $run],
            'summary' => [
                'total' => count($issues),
                'errors' => $errors,
                'warnings' => $warnings,
                'info' => $info,
            ],
            'issues' => $issues,
        ];
    }
}
