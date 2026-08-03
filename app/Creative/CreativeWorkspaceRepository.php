<?php

namespace App\Creative;

use App\Creative\Generation\CreativeGenerationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreativeWorkspaceRepository
{
    public function __construct(
        private readonly CreativeGenerationService $generation,
        private readonly CreativeArtifactValidator $artifactValidator,
        private readonly CreativeArtifactEligibilityVerifier $artifactEligibility,
        private readonly EffectiveSelectionResolver $selectionResolver,
        private readonly FilesystemCreativeStore $store,
        private readonly CreativePhaseCatalog $phaseCatalog,
        private readonly ShotPlanResolver $shotPlanResolver,
        private readonly CreativeMediaDelivery $mediaDelivery,
    ) {}

    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        return array_values(collect($this->store->projectIds())
            ->map(fn (string $project): ?array => $this->projectSummary($project))
            ->filter()
            ->sortByDesc('updated_at')
            ->values()
            ->all());
    }

    /** @return array{project: string, run: string, phase: string} */
    public function createProject(string $name, string $idea, string $startPhase): array
    {
        $project = (Str::slug($name) ?: 'creative-project').'--'.$this->shortId();
        $createdAt = $this->timestamp();

        $this->store->writeProject($project, [
            'id' => $project,
            'type' => 'creative_project',
            'name' => $name,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'start_phase' => $startPhase,
        ], "# {$name}\n\n## Broad idea\n\n{$idea}\n\n## Goal\n\nProduce a clear 15-second advertisement through a human-reviewed creative chain.");

        return $this->createRun($project);
    }

    /** @return array{project: string, run: string, phase: string} */
    public function createRun(string $project): array
    {
        $projectDocument = $this->store->readProject($project);
        $run = now('UTC')->format('Ymd-His').'--'.$this->shortId();
        $createdAt = $this->timestamp();
        $startPhase = (string) ($projectDocument['metadata']['start_phase'] ?? 'concepts');

        $this->store->initializeRun($project, $run, [
            'id' => $run,
            'type' => 'creative_run',
            'project' => $project,
            'created_at' => $createdAt,
        ], "# Creative run\n\nThis run begins from the broad idea in `project.md`. Every generated option and selection remains immutable.");

        if ($startPhase === 'concepts') {
            $this->recordIdeaSourceDecision(
                $project,
                $run,
                'project_idea',
                'human',
                'Ideas were explicitly skipped because this run is configured to begin at Concepts.',
            );
        }

        return compact('project', 'run') + ['phase' => $startPhase];
    }

    /** @return array<string, mixed> */
    public function workspace(string $project, string $run, string $phase, ?string $artifactId = null): array
    {
        $this->assertPhase($phase);
        $projectDocument = $this->store->readProject($project);
        $runDocument = $this->store->readRun($project, $run);
        $artifacts = $this->artifacts($project, $run, $phase);
        $selectionState = $this->selectionState($project, $run, $phase);
        $selectedIds = collect($selectionState['resolved'])->pluck('artifact.id');
        $invalidSelectionIds = collect($selectionState['invalid'])->pluck('selection.artifact_id');
        $selectionsByArtifact = collect($selectionState['resolved'])
            ->mapWithKeys(fn (array $item): array => [
                (string) $item['artifact']['id'] => $item['selection'],
            ]);
        $invalidSelectionsByArtifact = collect($selectionState['invalid'])
            ->mapWithKeys(fn (array $item): array => [
                (string) $item['selection']['artifact_id'] => $item['selection'],
            ]);
        $expectedSources = $this->expectedSources($project, $run, $phase);
        $requiredSlots = $this->requiredSlots($project, $run, $phase);
        $expectedShots = $this->expectedShotsBySlot($project, $run, $phase);
        $artifacts = array_map(function (array $artifact) use (
            $project,
            $run,
            $phase,
            $selectedIds,
            $invalidSelectionIds,
            $selectionsByArtifact,
            $invalidSelectionsByArtifact,
            $expectedSources,
            $requiredSlots,
            $expectedShots,
        ): array {
            $validation = $this->artifactValidation(
                $project,
                $run,
                $artifact,
                $phase,
                $expectedSources['by_slot'][$artifact['slot']] ?? null,
                $requiredSlots,
                $expectedShots[$artifact['slot']] ?? null,
                $expectedSources['by_slot'],
                $expectedShots,
            );

            return $artifact + [
                'selected' => $selectedIds->contains($artifact['id']),
                'selection_invalid' => $invalidSelectionIds->contains($artifact['id']),
                'selection' => $selectionsByArtifact->get((string) $artifact['id']),
                'invalid_selection' => $invalidSelectionsByArtifact->get((string) $artifact['id']),
                'validation' => $validation,
                'asset_url' => $validation['valid']
                    && ($artifact['asset_status'] ?? null) === MediaAssetContract::STATUS_READY
                    ? $this->mediaDelivery->artifactUrl(
                        $project,
                        $run,
                        $phase,
                        (string) $artifact['id'],
                    )
                    : null,
            ];
        }, $artifacts);
        $activeArtifact = collect($artifacts)->firstWhere('id', $artifactId) ?? Arr::first($artifacts);
        $phaseItems = $this->phaseItems($project, $run, $phase);

        return [
            'projects' => $this->projects(),
            'project' => $projectDocument + ['id' => $project],
            'run' => $runDocument + ['id' => $run],
            'runs' => $this->runs($project),
            'phases' => $phaseItems,
            'phase' => collect($phaseItems)->firstWhere('key', $phase),
            'inputs' => $this->inputs($project, $run, $phase),
            'artifacts' => $artifacts,
            'activeArtifact' => $activeArtifact,
            'feedback' => $this->feedback($project, $run, $phase),
            'generation' => $this->generationState($phase),
            'shots' => $expectedShots,
            'concept_source' => $phase === 'concepts'
                ? $this->effectiveIdeaSource($project, $run)
                : null,
            'tree' => $this->store->projectTree($project),
        ];
    }

    /**
     * Lightweight validated read model for automation projection and transitions.
     *
     * @return array{
     *     complete: bool,
     *     required_slots: list<string>,
     *     artifacts: list<array<string, mixed>>
     * }
     */
    public function automationPhaseState(string $project, string $run, string $phase): array
    {
        $this->assertPhase($phase);
        $selectionState = $this->selectionState($project, $run, $phase);
        $selectedIds = collect($selectionState['resolved'])->pluck('artifact.id');
        $expectedSources = $this->expectedSources($project, $run, $phase);
        $requiredSlots = $this->requiredSlots($project, $run, $phase);
        $expectedShots = $this->expectedShotsBySlot($project, $run, $phase);
        $artifacts = array_map(fn (array $artifact): array => $artifact + [
            'selected' => $selectedIds->contains($artifact['id']),
            'validation' => $this->artifactValidation(
                $project,
                $run,
                $artifact,
                $phase,
                $expectedSources['by_slot'][$artifact['slot']] ?? null,
                $requiredSlots,
                $expectedShots[$artifact['slot']] ?? null,
                $expectedSources['by_slot'],
                $expectedShots,
            ),
        ], $this->artifacts($project, $run, $phase));
        $ideaSource = $phase === 'ideas'
            ? $this->effectiveIdeaSource($project, $run)
            : null;

        return [
            'complete' => $phase === 'ideas'
                ? $this->ideasPhaseComplete($selectionState, $ideaSource)
                : $selectionState['complete'],
            'required_slots' => $requiredSlots,
            'artifacts' => $artifacts,
        ];
    }

    /** @return array{project: string, run: string, phase: string} */
    public function firstWorkspace(): array
    {
        if ($this->projects() === []) {
            $this->createDemoProject();
        }

        $project = $this->projects()[0];

        return [
            'project' => $project['id'],
            'run' => $project['latest_run'],
            'phase' => $project['current_phase'],
        ];
    }

    public function simulate(string $project, string $run, string $phase, bool $forceSimulation = false): string
    {
        $context = $this->phaseContext($project, $run, $phase);
        if (! $context['ready']) {
            throw new RuntimeException('Complete every required selection in the previous phase before generating this phase.');
        }

        $result = $this->generation->generate(
            $project,
            $run,
            $phase,
            $context['inputs'],
            $context['feedback'],
            $context['required_slots'],
            $context['expected_sources_by_slot'],
            simulationOnly: $forceSimulation,
        );

        return $result['driver'] === 'simulation' ? 'simulation' : 'provider';
    }

    /**
     * @return array{
     *     ready: bool,
     *     inputs: list<array<string, mixed>>,
     *     feedback: string,
     *     required_slots: list<string>,
     *     expected_sources_by_slot: array<string, list<string>>
     * }
     */
    public function phaseContext(string $project, string $run, string $phase): array
    {
        $this->assertPhase($phase);
        $expectedSources = $this->expectedSources($project, $run, $phase);

        return [
            'ready' => $expectedSources['ready'],
            'inputs' => $this->inputs($project, $run, $phase),
            'feedback' => $this->generationFeedback($project, $run, $phase),
            'required_slots' => $this->requiredSlots($project, $run, $phase),
            'expected_sources_by_slot' => $expectedSources['by_slot'],
        ];
    }

    /** @return array{next_phase: string|null, complete: bool} */
    public function select(
        string $project,
        string $run,
        string $phase,
        string $artifactId,
        string $selectedBy = 'human',
        ?string $evaluationId = null,
        ?string $reason = null,
    ): array {
        $this->assertPhase($phase);
        if (! in_array($selectedBy, ['human', 'automation'], true)) {
            throw new RuntimeException('Selection actor must be human or automation.');
        }

        $evaluationId = $evaluationId !== null && trim($evaluationId) !== ''
            ? trim($evaluationId)
            : null;
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $artifact = collect($this->artifacts($project, $run, $phase))->firstWhere('id', $artifactId);

        if (! $artifact) {
            throw new RuntimeException('The selected artifact does not exist.');
        }

        $slot = is_string($artifact['slot'] ?? null) ? $artifact['slot'] : '';
        $expectedSources = $this->expectedSources($project, $run, $phase);
        if (! $expectedSources['ready']) {
            throw new RuntimeException('Complete every required selection in the previous phase first.');
        }
        $requiredSlots = $this->requiredSlots($project, $run, $phase);
        $expectedShots = $this->expectedShotsBySlot($project, $run, $phase);

        $validation = $this->artifactValidation(
            $project,
            $run,
            $artifact,
            $phase,
            $expectedSources['by_slot'][$slot] ?? null,
            $requiredSlots,
            $expectedShots[$slot] ?? null,
            $expectedSources['by_slot'],
            $expectedShots,
        );
        if (! $validation['valid']) {
            throw new RuntimeException('Artifact cannot be selected: '.implode(' ', $validation['errors']));
        }

        $selectionId = $this->filename('selection', (string) $artifact['title']);
        $createdAt = $this->timestamp();
        $selectionMetadata = [
            'id' => $selectionId,
            'type' => 'selection',
            'phase' => $phase,
            'slot' => $slot,
            'artifact_id' => $artifactId,
            'artifact_path' => $artifact['path'],
            'selected_by' => $selectedBy,
            'evaluation_id' => $evaluationId,
            'reason' => $reason,
            'created_at' => $createdAt,
        ];
        $selectionBody = "# Selected: {$artifact['title']}\n\nThis immutable record promotes the referenced artifact as the input for the next phase.\n\n## Selection explanation\n\n- Selected by: `{$selectedBy}`\n- Evaluation: ".($evaluationId !== null ? "`{$evaluationId}`" : 'none')."\n\n## Reason\n\n".($reason ?? 'No additional selection reason was recorded.');

        if ($phase === 'ideas') {
            $decision = $this->ideaSourceDecisionDocument(
                $project,
                'selected_idea',
                $selectedBy,
                $reason ?? 'The selected idea should be the source for Concepts.',
                $artifactId,
                $selectionId,
                $createdAt,
            );
            $this->store->appendIdeasSelectionWithSourceDecision(
                $project,
                $run,
                $slot,
                $selectionId,
                $selectionMetadata,
                $selectionBody,
                $decision['id'],
                $decision['metadata'],
                $decision['body'],
            );
        } else {
            $this->store->appendSelection(
                $project,
                $run,
                $phase,
                $slot,
                $selectionId,
                $selectionMetadata,
                $selectionBody,
            );
        }

        $selectionState = $this->selectionState($project, $run, $phase);
        $complete = $phase === 'ideas'
            ? $this->ideasPhaseComplete($selectionState, $this->effectiveIdeaSource($project, $run))
            : (bool) $selectionState['complete'];

        return [
            'next_phase' => $complete ? $this->nextPhase($phase) : null,
            'complete' => $complete,
        ];
    }

    /** @return array{next_phase: string, complete: true} */
    public function skipIdeas(
        string $project,
        string $run,
        string $selectedBy = 'human',
        ?string $reason = null,
    ): array {
        $this->store->readProject($project);
        $this->store->readRun($project, $run);
        if (! in_array($selectedBy, ['human', 'automation'], true)) {
            throw new RuntimeException('Ideas skip actor must be human or automation.');
        }

        $this->recordIdeaSourceDecision(
            $project,
            $run,
            'project_idea',
            $selectedBy,
            $reason !== null && trim($reason) !== ''
                ? trim($reason)
                : 'Ideas were explicitly skipped; Concepts should use the broad project idea.',
        );

        return ['next_phase' => 'concepts', 'complete' => true];
    }

    /**
     * Recover a missing source decision for the exact current eligible Ideas
     * selection. Existing exact evidence is returned without another write.
     *
     * @return array{
     *     mode: 'selected_idea'|'project_idea',
     *     source_id: string,
     *     source_title: string,
     *     explicit: bool,
     *     skipped: bool,
     *     reason: string|null,
     *     decision: array<string, mixed>|null
     * }
     */
    public function ensureCurrentIdeaSourceDecision(
        string $project,
        string $run,
        string $selectedBy = 'automation',
        ?string $reason = null,
    ): array {
        $this->store->readProject($project);
        $this->store->readRun($project, $run);
        if (! in_array($selectedBy, ['human', 'automation'], true)) {
            throw new RuntimeException('Ideas source-decision actor must be human or automation.');
        }

        $source = $this->effectiveIdeaSource($project, $run);
        if ($source['mode'] === 'selected_idea' && $source['explicit']) {
            return $source;
        }
        if ($source['mode'] === 'project_idea' && $source['explicit']) {
            throw new RuntimeException('Ideas are currently explicitly skipped; recovery cannot supersede that decision.');
        }

        $state = $this->selectionState($project, $run, 'ideas');
        $selection = is_array($state['resolved'][0]['selection'] ?? null)
            ? $state['resolved'][0]['selection']
            : null;
        $artifact = is_array($state['resolved'][0]['artifact'] ?? null)
            ? $state['resolved'][0]['artifact']
            : null;
        $selectionId = is_array($selection) && is_string($selection['id'] ?? null)
            ? trim($selection['id'])
            : '';
        $artifactId = is_array($artifact) && is_string($artifact['id'] ?? null)
            ? trim($artifact['id'])
            : '';

        if (! $state['complete'] || $selectionId === '' || $artifactId === '') {
            throw new RuntimeException('There is no eligible current Ideas selection to recover.');
        }

        $reason = $reason !== null && trim($reason) !== ''
            ? trim($reason)
            : 'Recovered the missing source decision for the current Ideas selection.';
        $decision = $this->ideaSourceDecisionDocument(
            $project,
            'selected_idea',
            $selectedBy,
            $reason,
            $artifactId,
            $selectionId,
        );
        $this->store->ensureIdeaSourceDecisionForCurrentSelection(
            $project,
            $run,
            $selectionId,
            $artifactId,
            $decision['id'],
            $decision['metadata'],
            $decision['body'],
        );

        $recovered = $this->effectiveIdeaSource($project, $run);
        if ($recovered['mode'] !== 'selected_idea'
            || ! $recovered['explicit']
            || $recovered['source_id'] !== $artifactId
            || ($recovered['decision']['selection_id'] ?? null) !== $selectionId) {
            throw new RuntimeException('The Ideas lineage changed before source-decision recovery completed.');
        }

        return $recovered;
    }

    public function appendFeedback(string $project, string $run, string $phase, string $scope, string $feedback): void
    {
        $this->assertPhase($phase);
        $entry = "\n## ".now('UTC')->format('Y-m-d H:i:s').' UTC · '.$this->shortId()."\n\n{$feedback}\n";
        $this->store->appendFeedbackEntry($project, $run, $phase, $scope, $entry);
    }

    public function archiveFeedback(string $project, string $run, string $phase, string $scope): void
    {
        $historyName = $this->filename('feedback', $scope === 'cycle' ? 'whole-cycle' : $phase);
        $this->store->archiveFeedback(
            $project,
            $run,
            $phase,
            $scope,
            $historyName,
            $this->timestamp(),
        );
    }

    public function createDemoProject(): void
    {
        $created = $this->createProject(
            'Voice prompts campaign',
            'Create a warm dating-app advertisement showing how a meaningful voice prompt turns repetitive small talk into a real conversation.',
            'concepts',
        );

        $project = $created['project'];
        $run = $created['run'];
        $this->simulate($project, $run, 'ideas', true);
        $idea = $this->artifacts($project, $run, 'ideas')[0];
        $this->select($project, $run, 'ideas', $idea['id']);
        $this->simulate($project, $run, 'concepts', true);
        $concept = $this->artifacts($project, $run, 'concepts')[0];
        $this->select($project, $run, 'concepts', $concept['id']);
        $this->simulate($project, $run, 'brief-script', true);
        $script = $this->artifacts($project, $run, 'brief-script')[0];
        $this->select($project, $run, 'brief-script', $script['id']);
        $this->simulate($project, $run, 'images', true);
        $this->appendFeedback($project, $run, 'concepts', 'phase', 'The first concept is strongest. Keep the opening understandable without sound.');
    }

    /** @return list<array<string, mixed>> */
    private function artifacts(string $project, string $run, string $phase): array
    {
        return $this->store->artifacts($project, $run, $phase);
    }

    /** @return list<array<string, mixed>> */
    private function inputs(string $project, string $run, string $phase): array
    {
        if ($phase === 'ideas') {
            return [$this->store->readProject($project) + [
                'id' => $project,
                'title' => 'Broad project idea',
                'path' => "{$project}/project.md",
                'slot' => 'primary',
            ]];
        }

        if ($phase === 'concepts') {
            $source = $this->effectiveIdeaSource($project, $run);
            if (! $source['explicit']) {
                return [];
            }

            if ($source['mode'] === 'selected_idea') {
                $ideaState = $this->selectionState($project, $run, 'ideas');

                return array_map(
                    fn (array $item): array => $item['artifact'] + ['selection' => $item['selection']],
                    $ideaState['resolved'],
                );
            }

            return [$this->store->readProject($project) + [
                'id' => $project,
                'title' => 'Broad project idea',
                'path' => "{$project}/project.md",
                'slot' => 'primary',
            ]];
        }

        $previous = $this->previousPhase($phase);

        if ($previous === null) {
            return [];
        }

        $state = $this->selectionState($project, $run, $previous);
        if (! $state['complete']) {
            return [];
        }

        return array_map(
            fn (array $item): array => $item['artifact'] + ['selection' => $item['selection']],
            $state['resolved'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function selectionRecords(string $project, string $run, string $phase): array
    {
        return $this->store->selectionRecords($project, $run, $phase);
    }

    /** @return list<string> */
    private function requiredSlots(string $project, string $run, string $phase): array
    {
        $definition = $this->phases()[$phase];
        if (($definition['selection_mode'] ?? 'single') !== 'script_shots') {
            $configured = is_array($definition['slots'] ?? null) ? $definition['slots'] : [];

            return array_values(array_filter(
                $configured,
                fn (mixed $slot): bool => is_string($slot) && $slot !== '',
            ));
        }

        $shotPlan = $this->effectiveShotPlan($project, $run);
        if (! $shotPlan['valid'] || ! is_array($shotPlan['shot_plan'])) {
            return [];
        }

        $shots = is_array($shotPlan['shot_plan']['shots'] ?? null)
            ? $shotPlan['shot_plan']['shots']
            : [];

        return array_values(array_filter(array_map(
            fn (mixed $shot): string => is_array($shot) && is_string($shot['id'] ?? null)
                ? $shot['id']
                : '',
            $shots,
        )));
    }

    /**
     * @return array{valid: bool, shot_plan: array<string, mixed>|null, errors: list<string>, warnings: list<string>, origin: 'metadata'|'legacy_markdown'|null}
     */
    private function effectiveShotPlan(string $project, string $run): array
    {
        $scriptState = $this->selectionState($project, $run, 'brief-script');
        $script = $scriptState['resolved'][0]['artifact'] ?? null;

        if (! $scriptState['complete'] || ! is_array($script)) {
            return [
                'valid' => false,
                'shot_plan' => null,
                'errors' => ['A valid selected brief/script is required to resolve the shot plan.'],
                'warnings' => [],
                'origin' => null,
            ];
        }

        return $this->shotPlanResolver->resolve($script);
    }

    /** @return array<string, array<string, mixed>> */
    private function expectedShotsBySlot(string $project, string $run, string $phase): array
    {
        if (! in_array($phase, ['images', 'scenes'], true)) {
            return [];
        }

        $resolution = $this->effectiveShotPlan($project, $run);
        if (! $resolution['valid'] || ! is_array($resolution['shot_plan'])) {
            return [];
        }

        $shots = $resolution['shot_plan']['shots'] ?? null;
        if (! is_array($shots)) {
            return [];
        }

        $bySlot = [];
        foreach ($shots as $shot) {
            if (! is_array($shot) || ! is_string($shot['id'] ?? null)) {
                continue;
            }

            $bySlot[$shot['id']] = $shot;
        }

        return $bySlot;
    }

    /** @return array{resolved: list<array{selection: array<string, mixed>, artifact: array<string, mixed>}>, effective: list<array<string, mixed>>, invalid: list<array{selection: array<string, mixed>, errors: list<string>}>, complete: bool, has_records: bool} */
    private function selectionState(string $project, string $run, string $phase): array
    {
        $expected = $this->expectedSources($project, $run, $phase);

        return $this->selectionResolver->resolve(
            $phase,
            $this->selectionRecords($project, $run, $phase),
            $this->artifacts($project, $run, $phase),
            $this->requiredSlots($project, $run, $phase),
            $expected['by_slot'],
            $expected['ready'],
            $this->expectedShotsBySlot($project, $run, $phase),
            $project,
            $run,
            $this->expectedSequencePlan($project, $run, $phase),
        );
    }

    /** @return array<string, mixed>|null */
    private function expectedSequencePlan(string $project, string $run, string $phase): ?array
    {
        if ($phase !== 'sequence') {
            return null;
        }

        $sceneState = $this->selectionState($project, $run, 'scenes');
        if (! $sceneState['complete']) {
            return null;
        }

        $scenes = array_map(
            static fn (array $item): array => $item['artifact'],
            $sceneState['resolved'],
        );
        $contract = $this->shotPlanResolver->sequenceContract($scenes);

        return $contract['valid'] && is_array($contract['shot_plan'])
            ? $contract['shot_plan']
            : null;
    }

    /** @return array{ready: bool, by_slot: array<string, list<string>>} */
    private function expectedSources(string $project, string $run, string $phase): array
    {
        $slots = $this->requiredSlots($project, $run, $phase);
        if ($phase === 'ideas') {
            return ['ready' => true, 'by_slot' => ['primary' => [$project]]];
        }

        if ($phase === 'concepts') {
            $source = $this->effectiveIdeaSource($project, $run);
            if (! $source['explicit']) {
                return ['ready' => false, 'by_slot' => ['primary' => []]];
            }

            if ($source['mode'] === 'project_idea') {
                return ['ready' => true, 'by_slot' => ['primary' => [$project]]];
            }

            return ['ready' => true, 'by_slot' => ['primary' => [(string) $source['source_id']]]];
        }

        if (($this->phases()[$phase]['selection_mode'] ?? 'single') === 'script_shots' && $slots === []) {
            return ['ready' => false, 'by_slot' => []];
        }

        $previous = $this->previousPhase($phase);
        if ($previous === null) {
            return ['ready' => false, 'by_slot' => []];
        }

        $previousState = $this->selectionState($project, $run, $previous);
        if (! $previousState['complete']) {
            return [
                'ready' => false,
                'by_slot' => array_fill_keys($slots, []),
            ];
        }

        if ($phase === 'scenes') {
            $bySlot = [];
            foreach ($previousState['resolved'] as $item) {
                $bySlot[(string) $item['artifact']['slot']] = [(string) $item['artifact']['id']];
            }

            return ['ready' => true, 'by_slot' => $bySlot];
        }

        $sourceIds = array_map(
            fn (array $item): string => (string) $item['artifact']['id'],
            $previousState['resolved'],
        );

        return [
            'ready' => true,
            'by_slot' => array_fill_keys($slots, $sourceIds),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function phaseItems(string $project, string $run, string $activePhase): array
    {
        return array_values(collect($this->phases())->map(function (array $definition, string $key) use ($project, $run, $activePhase): array {
            $state = $this->selectionState($project, $run, $key);
            $ideaSource = $key === 'ideas'
                ? $this->effectiveIdeaSource($project, $run)
                : null;
            $skipped = $key === 'ideas'
                && is_array($ideaSource)
                && $ideaSource['explicit']
                && $ideaSource['skipped'];
            $selectedCount = count($state['resolved']);
            $requiredCount = count($this->requiredSlots($project, $run, $key));
            $available = $this->expectedSources($project, $run, $key)['ready'];
            $complete = $key === 'ideas'
                ? $this->ideasPhaseComplete($state, $ideaSource)
                : $state['complete'];

            return $definition + [
                'key' => $key,
                'directory' => $this->phaseCatalog->directory($key),
                'artifact_count' => count($this->artifacts($project, $run, $key)),
                'selected_count' => $selectedCount,
                'required_count' => $requiredCount,
                'required_slots' => $this->requiredSlots($project, $run, $key),
                'complete' => $complete,
                'active' => $key === $activePhase,
                'available' => $available,
                'selection_issue_count' => count($state['invalid']),
                'status' => $skipped
                    ? 'skipped'
                    : ($complete
                    ? 'complete'
                    : ($state['has_records'] ? 'stale' : ($available ? 'ready' : 'blocked'))),
            ];
        })->values()->all());
    }

    /**
     * @return array{
     *     current: list<array{title: string, content: string}>,
     *     cycle: list<array{title: string, content: string}>,
     *     history: list<array<string, mixed>>,
     *     history_count: int
     * }
     */
    private function feedback(string $project, string $run, string $phase): array
    {
        return $this->store->feedback($project, $run, $phase);
    }

    /**
     * @param  array<string, mixed>  $selectionState
     * @param  array<string, mixed>|null  $source
     */
    private function ideasPhaseComplete(array $selectionState, ?array $source): bool
    {
        if (! is_array($source) || ! ($source['explicit'] ?? false)) {
            return false;
        }

        if (($source['mode'] ?? null) === 'project_idea') {
            return (bool) ($source['skipped'] ?? false);
        }

        return ($source['mode'] ?? null) === 'selected_idea'
            && (bool) ($selectionState['complete'] ?? false);
    }

    /**
     * @return array{
     *     mode: 'selected_idea'|'project_idea',
     *     source_id: string,
     *     source_title: string,
     *     explicit: bool,
     *     skipped: bool,
     *     reason: string|null,
     *     decision: array<string, mixed>|null
     * }
     */
    public function effectiveIdeaSource(string $project, string $run): array
    {
        $ideaState = $this->selectionState($project, $run, 'ideas');
        $selection = is_array($ideaState['resolved'][0]['selection'] ?? null)
            ? $ideaState['resolved'][0]['selection']
            : null;
        $artifact = is_array($ideaState['resolved'][0]['artifact'] ?? null)
            ? $ideaState['resolved'][0]['artifact']
            : null;
        $latest = collect($this->store->ideaSourceDecisions($project, $run))
            ->filter(fn (array $record): bool => $this->isValidIdeaSourceDecision($record, $project))
            ->sortByDesc(fn (array $record): int => (int) ($record['sequence'] ?? 0))
            ->first();

        if (is_array($latest) && ($latest['mode'] ?? null) === 'project_idea') {
            if (! $ideaState['complete']
                || ! is_array($selection)
                || $this->ideaDecisionIsAtOrAfterSelection($latest, $selection)) {
                return [
                    'mode' => 'project_idea',
                    'source_id' => $project,
                    'source_title' => 'Broad project idea',
                    'explicit' => true,
                    'skipped' => true,
                    'reason' => is_string($latest['reason'] ?? null) ? $latest['reason'] : null,
                    'decision' => $latest,
                ];
            }
        }

        if ($ideaState['complete'] && is_array($artifact)) {
            $matchingDecision = is_array($latest)
                && ($latest['mode'] ?? null) === 'selected_idea'
                && ($latest['artifact_id'] ?? null) === ($artifact['id'] ?? null)
                && ($latest['selection_id'] ?? null) === ($selection['id'] ?? null);

            return [
                'mode' => 'selected_idea',
                'source_id' => (string) $artifact['id'],
                'source_title' => (string) ($artifact['title'] ?? 'Selected idea'),
                'explicit' => $matchingDecision,
                'skipped' => false,
                'reason' => is_string(($matchingDecision ? $latest : $selection)['reason'] ?? null)
                    ? ($matchingDecision ? $latest : $selection)['reason']
                    : null,
                'decision' => $matchingDecision ? $latest : null,
            ];
        }

        return [
            'mode' => 'project_idea',
            'source_id' => $project,
            'source_title' => 'Broad project idea',
            'explicit' => false,
            'skipped' => false,
            'reason' => null,
            'decision' => null,
        ];
    }

    private function recordIdeaSourceDecision(
        string $project,
        string $run,
        string $mode,
        string $selectedBy,
        string $reason,
        ?string $artifactId = null,
        ?string $selectionId = null,
    ): void {
        $decision = $this->ideaSourceDecisionDocument(
            $project,
            $mode,
            $selectedBy,
            $reason,
            $artifactId,
            $selectionId,
        );
        $this->store->appendIdeaSourceDecision(
            $project,
            $run,
            $decision['id'],
            $decision['metadata'],
            $decision['body'],
        );
    }

    /**
     * @return array{id: string, metadata: array<string, mixed>, body: string}
     */
    private function ideaSourceDecisionDocument(
        string $project,
        string $mode,
        string $selectedBy,
        string $reason,
        ?string $artifactId = null,
        ?string $selectionId = null,
        ?string $createdAt = null,
    ): array {
        $decisionId = $this->filename('ideas-source', $mode);
        $sourceId = $mode === 'selected_idea' && $artifactId !== null ? $artifactId : $project;

        return [
            'id' => $decisionId,
            'metadata' => [
                'schema_version' => 1,
                'id' => $decisionId,
                'type' => 'ideas_source_decision',
                'phase' => 'ideas',
                'mode' => $mode,
                'source_id' => $sourceId,
                'artifact_id' => $artifactId,
                'selection_id' => $selectionId,
                'selected_by' => $selectedBy,
                'reason' => $reason,
                'created_at' => $createdAt ?? $this->timestamp(),
            ],
            'body' => "# Ideas source decision\n\n## Decision\n\n".($mode === 'selected_idea'
                ? "Use selected idea `{$artifactId}` as the Concepts source."
                : 'Skip optional Ideas and use the broad idea from `project.md` as the Concepts source.')."\n\n## Reason\n\n{$reason}",
        ];
    }

    /** @param array<string, mixed> $record */
    private function isValidIdeaSourceDecision(array $record, string $project): bool
    {
        $mode = $record['mode'] ?? null;
        $sourceId = $record['source_id'] ?? null;
        $artifactId = $record['artifact_id'] ?? null;
        $selectionId = $record['selection_id'] ?? null;
        $lineageSequence = $record['lineage_sequence'] ?? null;

        if (($record['schema_version'] ?? null) !== 1
            || ! is_string($record['id'] ?? null)
            || trim($record['id']) === ''
            || ($record['type'] ?? null) !== 'ideas_source_decision'
            || ($record['phase'] ?? null) !== 'ideas'
            || ! in_array($mode, ['selected_idea', 'project_idea'], true)
            || ! is_string($sourceId)
            || trim($sourceId) === ''
            || ! in_array($record['selected_by'] ?? null, ['human', 'automation'], true)
            || ! is_string($record['reason'] ?? null)
            || trim($record['reason']) === ''
            || ! $this->isIsoTimestamp($record['created_at'] ?? null)
            || ! is_int($record['sequence'] ?? null)
            || $record['sequence'] < 1
            || ($lineageSequence !== null
                && (! is_int($lineageSequence) || $lineageSequence < 1))) {
            return false;
        }

        if ($mode === 'project_idea') {
            return $sourceId === $project && $artifactId === null && $selectionId === null;
        }

        return is_string($artifactId)
            && trim($artifactId) !== ''
            && $sourceId === $artifactId
            && is_string($selectionId)
            && trim($selectionId) !== '';
    }

    /**
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

    private function isIsoTimestamp(mixed $value): bool
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

    /** @return list<array<string, mixed>> */
    private function runs(string $project): array
    {
        return $this->store->runs($project);
    }

    /** @return array<string, mixed>|null */
    private function projectSummary(string $project): ?array
    {
        try {
            $document = $this->store->readProject($project);
            $runs = $this->runs($project);
            $latestRun = $runs[0]['id'] ?? null;

            if (! $latestRun) {
                return null;
            }

            $phaseItems = $this->phaseItems($project, $latestRun, 'concepts');
            $startPhase = $document['metadata']['start_phase'] ?? 'concepts';
            $startIndex = array_search($startPhase, array_column($phaseItems, 'key'), true);
            $eligible = is_int($startIndex) ? array_slice($phaseItems, $startIndex) : $phaseItems;
            $current = collect($eligible)->first(fn (array $phase): bool => ! $phase['complete'] && $phase['available']) ?? end($phaseItems);

            return [
                'id' => $project,
                'name' => $document['metadata']['name'] ?? Str::headline($project),
                'updated_at' => $document['metadata']['updated_at'] ?? $document['metadata']['created_at'] ?? '',
                'latest_run' => $latestRun,
                'current_phase' => $current['key'] ?? 'concepts',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{configured_driver: string, effective_driver: string, label: string, external: bool, cost: string, description: string} */
    private function generationState(string $phase): array
    {
        return $this->generation->state($phase);
    }

    private function generationFeedback(string $project, string $run, string $phase): string
    {
        $feedback = $this->feedback($project, $run, $phase);

        return collect([
            'Phase feedback' => $feedback['current'],
            'Whole-run feedback' => $feedback['cycle'],
        ])->map(function (array $sections, string $label): string {
            if ($sections === []) {
                return '';
            }

            return '# '.$label."\n\n".collect($sections)
                ->map(fn (array $section): string => "## {$section['title']}\n\n{$section['content']}")
                ->implode("\n\n");
        })->filter()->implode("\n\n");
    }

    /** @return array<string, array<string, mixed>> */
    private function phases(): array
    {
        return $this->phaseCatalog->all();
    }

    private function nextPhase(string $phase): ?string
    {
        return $this->phaseCatalog->next($phase);
    }

    private function previousPhase(string $phase): ?string
    {
        return $this->phaseCatalog->previous($phase);
    }

    private function assertPhase(string $phase): void
    {
        $this->phaseCatalog->assertValid($phase);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>|null  $expectedSourceIds
     * @param  list<string>  $requiredSlots
     * @param  array<string, mixed>|null  $expectedShot
     * @param  array<string, list<string>>  $expectedSourcesBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, schema_version: int|null}
     */
    private function artifactValidation(
        string $project,
        string $run,
        array $artifact,
        string $phase,
        ?array $expectedSourceIds,
        array $requiredSlots,
        ?array $expectedShot,
        array $expectedSourcesBySlot,
        array $expectedShotsBySlot,
    ): array {
        $expectedSequencePlan = $this->expectedSequencePlan($project, $run, $phase);
        $validation = $this->artifactValidator->validate(
            $artifact,
            $phase,
            $expectedSourceIds,
            $requiredSlots,
            $expectedShot,
            $expectedSequencePlan,
        );
        $eligibility = $this->artifactEligibility->verify(
            $project,
            $run,
            $phase,
            $artifact,
            $requiredSlots,
            $expectedSourcesBySlot,
            $expectedShotsBySlot,
            $expectedSequencePlan,
        );

        return [
            'valid' => $validation['valid'] && $eligibility['eligible'],
            'errors' => array_values(array_unique(array_merge($validation['errors'], $eligibility['errors']))),
            'warnings' => array_values(array_unique(array_merge($validation['warnings'], $eligibility['warnings']))),
            'schema_version' => $validation['schema_version'],
        ];
    }

    private function shortId(): string
    {
        return Str::lower(Str::random(6));
    }

    private function filename(string $type, string $title): string
    {
        return now('UTC')->format('Ymd-His').'--'.Str::slug($type).'--'.$this->shortId().'--'.Str::limit(Str::slug($title), 42, '');
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
