<?php

namespace App\Creative\Automation;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePhaseCatalog;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Feedback\FeedbackApplicationStore;
use App\Creative\Generation\CreativeGenerationService;
use App\Creative\Generation\GenerationAttemptStore;
use App\Creative\Media\SimulatedMediaJobService;
use RuntimeException;
use Throwable;

final class CreativeRunOrchestrator
{
    public function __construct(
        private readonly CreativeWorkspaceRepository $workspace,
        private readonly CreativePhaseCatalog $phases,
        private readonly CreativeGenerationService $generation,
        private readonly GenerationAttemptStore $attempts,
        private readonly AutomationPolicy $policies,
        private readonly AutomationStateStore $stateStore,
        private readonly AutomationRunProjector $projector,
        private readonly ArtifactEvaluator $evaluator,
        private readonly CreativeLockManager $locks,
        private readonly FeedbackApplicationStore $feedbackApplications,
        private readonly SimulatedMediaJobService $mediaJobs,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function start(string $project, string $run, array $overrides = []): array
    {
        return $this->locks->exclusive($this->lockKey($project, $run), function () use ($project, $run, $overrides): array {
            $projectWorkspace = $this->workspace->workspace($project, $run, 'concepts');
            $projectMetadata = is_array($projectWorkspace['project']['metadata'] ?? null)
                ? $projectWorkspace['project']['metadata']
                : [];
            $startPhase = is_string($projectMetadata['start_phase'] ?? null)
                ? $projectMetadata['start_phase']
                : 'concepts';
            $this->phases->assertValid($startPhase);
            $policy = $this->policies->make($startPhase, $overrides);
            foreach ($policy['human_gates'] as $gate) {
                $this->phases->assertValid($gate);
            }

            if ($this->stateStore->policyExists($project, $run)
                && ! $this->stateStore->stateExists($project, $run)) {
                $this->validateStoredPolicy($project, $run);
                $this->stateStore->recoverPolicyOnly($project, $run);
                $this->reconcileCheckpoint($project, $run);

                return $this->runUntilPauseUnlocked($project, $run);
            }

            $automationId = $this->stateStore->newId('automation');
            $this->stateStore->start($project, $run, $automationId, $policy);
            $this->validateStoredPolicy($project, $run);
            $this->synchronize($project, $run);

            return $this->runUntilPauseUnlocked($project, $run);
        });
    }

    /** @return array<string, mixed>|null */
    public function status(string $project, string $run): ?array
    {
        if (! $this->stateStore->policyExists($project, $run)
            && ! $this->stateStore->stateExists($project, $run)) {
            return null;
        }
        if (! $this->stateStore->policyExists($project, $run)) {
            throw new RuntimeException('Automatic state exists without its immutable policy.');
        }

        $policy = $this->stateStore->policy($project, $run);
        $projection = $this->projector->project($project, $run);
        $checkpoint = null;
        $checkpointError = null;
        if ($this->stateStore->stateExists($project, $run)) {
            try {
                $checkpoint = $this->stateStore->state($project, $run);
            } catch (RuntimeException $exception) {
                $checkpointError = $exception->getMessage();
            }
        }
        $events = $this->stateStore->events($project, $run);

        return [
            'state' => $projection['state'],
            'policy' => $policy,
            'event_count' => count($events),
            'latest_event' => $events === [] ? null : $events[array_key_last($events)],
            'checkpoint_consistent' => is_array($checkpoint)
                && $this->projector->consistent($checkpoint, $projection['state']),
            'recovery_available' => ! is_array($checkpoint),
            'checkpoint_error' => $checkpointError,
            'projection_issues' => $projection['issues'],
            'gate' => $this->gateContext($project, $run, $projection['state']),
            'cancellation_mode' => 'checkpoint',
            'evaluation_kind' => 'structural_eligibility',
        ];
    }

    /** @return array<string, mixed> */
    public function runUntilPause(string $project, string $run, int $maximumSteps = 100): array
    {
        return $this->locks->exclusive(
            $this->lockKey($project, $run),
            fn (): array => $this->runUntilPauseUnlocked($project, $run, $maximumSteps),
        );
    }

    /** @return array<string, mixed> */
    public function step(string $project, string $run): array
    {
        return $this->locks->exclusive(
            $this->lockKey($project, $run),
            fn (): array => $this->stepUnlocked($project, $run),
        );
    }

    /** @return array<string, mixed> */
    public function cancel(string $project, string $run): array
    {
        return $this->locks->exclusive(
            $this->lockKey($project, $run),
            fn (): array => $this->cancelUnlocked($project, $run),
        );
    }

    /** @return array<string, mixed> */
    public function resume(string $project, string $run): array
    {
        return $this->locks->exclusive($this->lockKey($project, $run), function () use ($project, $run): array {
            if (! $this->stateStore->exists($project, $run)) {
                if ($this->stateStore->policyExists($project, $run)
                    && ! $this->stateStore->stateExists($project, $run)) {
                    $this->validateStoredPolicy($project, $run);
                    $this->stateStore->recoverPolicyOnly($project, $run);
                } else {
                    throw new RuntimeException('This run has no recoverable automatic-mode state to resume.');
                }
            }

            $this->validateStoredPolicy($project, $run);
            $state = $this->reconcileCheckpoint($project, $run);
            if (($state['cancel_requested'] ?? false) === true) {
                return $this->cancelUnlocked($project, $run);
            }
            if (($state['status'] ?? null) === 'complete') {
                return $state;
            }

            if (($state['status'] ?? null) === 'awaiting_review') {
                throw new RuntimeException('This run is awaiting an explicit gate decision: approve, manual_override, retry_with_feedback, or cancel. Resume never decides a review gate.');
            }

            if (in_array($state['status'] ?? null, ['cancelled', 'failed'], true)) {
                $cancelledAttemptId = $state['status'] === 'cancelled'
                    ? $this->latestCancelledAttemptId($project, $run)
                    : null;
                $this->stateStore->appendEvent($project, $run, 'automation_resumed', [
                    'phase' => $state['current_phase'] ?? null,
                    'active_attempt_id' => $cancelledAttemptId,
                ], 'Automatic simulation resumed from its projected filesystem checkpoint.');
                $this->synchronize($project, $run);
            }

            return $this->runUntilPauseUnlocked($project, $run);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(
        string $project,
        string $run,
        string $decision,
        ?string $feedback = null,
        ?string $gateToken = null,
    ): array {
        return $this->locks->exclusive(
            $this->lockKey($project, $run),
            fn (): array => $this->decideUnlocked($project, $run, $decision, $feedback, $gateToken),
        );
    }

    /** @return array<string, mixed> */
    private function runUntilPauseUnlocked(string $project, string $run, int $maximumSteps = 100): array
    {
        $state = $this->reconcileCheckpoint($project, $run);
        if (($state['cancel_requested'] ?? false) === true) {
            return $this->cancelUnlocked($project, $run);
        }
        for ($step = 0; $step < $maximumSteps; $step++) {
            if (! in_array($state['status'] ?? null, ['pending', 'running'], true)) {
                return $state;
            }

            $state = $this->stepUnlocked($project, $run, $state);
        }

        $this->stateStore->appendEvent($project, $run, 'automation_failed', [
            'phase' => $state['current_phase'] ?? null,
        ], 'Automatic run exceeded its bounded step limit.');

        return $this->synchronize($project, $run);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    private function stepUnlocked(string $project, string $run, ?array $state = null): array
    {
        $state ??= $this->reconcileCheckpoint($project, $run);
        if (($state['cancel_requested'] ?? false) === true) {
            return $this->cancelUnlocked($project, $run);
        }
        if (! in_array($state['status'] ?? null, ['pending', 'running'], true)) {
            return $state;
        }

        try {
            return match ((string) ($state['step'] ?? 'ready')) {
                'generating' => $this->recoverGeneration($project, $run, $state),
                'evaluating' => $this->evaluateAttempt($project, $run, $state),
                'selecting' => $this->selectRecommendations($project, $run, $state),
                default => $this->beginPhaseWork($project, $run, $state),
            };
        } catch (Throwable $exception) {
            $this->stateStore->appendEvent($project, $run, 'automation_failed', [
                'phase' => $state['current_phase'] ?? null,
            ], $exception->getMessage());

            return $this->synchronize($project, $run);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function beginPhaseWork(string $project, string $run, array $state): array
    {
        $phase = (string) ($state['current_phase'] ?? '');
        $this->phases->assertValid($phase);
        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        $retryRequested = ($state['retry_requested'] ?? false) === true
            || ($state['step'] ?? null) === 'retry_requested';

        if ($phaseWorkspace['complete'] && ! $retryRequested) {
            $policy = $this->stateStore->policy($project, $run);
            if (in_array($phase, $policy['human_gates'], true)) {
                $this->stateStore->appendEvent($project, $run, 'gate_requested', $this->withIdeasSourceEvidence($project, $run, $phase, [
                    'phase' => $phase,
                    'gate_kind' => 'human_action',
                    'selection_ids' => $this->currentSelectionIds($project, $run, $phase),
                    'reason' => 'This pre-existing complete phase is a configured human gate; explicitly continue with its current selections.',
                ]), 'A configured gate still applies when automation reuses pre-existing manual selections.');
            } else {
                $this->stateStore->appendEvent($project, $run, 'phase_reused', $this->withIdeasSourceEvidence($project, $run, $phase, [
                    'phase' => $phase,
                    'selection_ids' => $this->currentSelectionIds($project, $run, $phase),
                ]), 'The phase already has valid effective selections, so automation reused them.');
                if ($this->phases->next($phase) === null) {
                    $this->stateStore->appendEvent($project, $run, 'automation_completed', [
                        'phase' => $phase,
                    ], 'The complete automatic run is reconstructible from project files.');
                }
            }

            return $this->synchronize($project, $run);
        }

        $context = $this->workspace->phaseContext($project, $run, $phase);
        if (! $context['ready']) {
            return $this->pauseForHuman(
                $project,
                $run,
                $phase,
                'The selected inputs or dynamic shot plan are incomplete or invalid.',
            );
        }

        $requiredSlots = $context['required_slots'];
        $policy = $this->stateStore->policy($project, $run);
        $attemptsByPhase = is_array($state['attempts_by_phase'] ?? null)
            ? $state['attempts_by_phase']
            : [];
        $phaseAttempts = (int) ($attemptsByPhase[$phase] ?? 0);
        $totalAttempts = (int) ($state['total_attempts'] ?? 0);
        $units = $this->generation->estimatedSimulationUnits($phase, $requiredSlots);
        $spentUnits = (int) ($state['simulation_units_spent'] ?? 0);

        if ($phaseAttempts >= (int) $policy['max_attempts_per_phase']) {
            return $this->pauseForHuman($project, $run, $phase, 'The per-phase retry limit was reached.');
        }
        if ($totalAttempts >= (int) $policy['max_total_attempts']) {
            return $this->pauseForHuman($project, $run, $phase, 'The total automatic-attempt limit was reached.');
        }
        if ($spentUnits + $units > (int) $policy['max_simulation_units']) {
            return $this->pauseForHuman($project, $run, $phase, 'The simulation-unit budget would be exceeded.');
        }

        $attemptId = $this->generation->newAttemptId($phase);
        $attemptNumber = $phaseAttempts + 1;
        $retryContext = $this->retryContext($project, $run, $phase, $state);
        $retryDecision = $this->retryDecisionContext($project, $run, $phase);
        $feedback = $this->withRetryContext($context['feedback'], $retryContext, $retryDecision !== []);
        $retryContextHash = $retryContext === []
            ? null
            : hash('sha256', json_encode($retryContext, JSON_THROW_ON_ERROR));
        $generationFeedbackHash = hash('sha256', $feedback);
        $sourceArtifactIds = collect($context['inputs'])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        $lineageHash = $this->lineageHash($context['inputs']);
        $this->stateStore->appendEvent($project, $run, 'attempt_started', [
            'phase' => $phase,
            'attempt_id' => $attemptId,
            'attempt_number' => $attemptNumber,
            'driver' => 'simulation',
            'source_artifact_ids' => $sourceArtifactIds,
            'required_slots' => $requiredSlots,
            'expected_output_count' => $this->generation->expectedOutputCount($phase, $requiredSlots),
            'reserved_simulation_units' => $units,
            'prior_evaluation_id' => $state['latest_evaluation_id'] ?? null,
            'prior_evaluation_diagnosis' => $retryContext['diagnosis'] ?? null,
            'retry_context_hash' => $retryContextHash,
            'retry_decision_event_id' => $retryDecision['event_id'] ?? null,
            'decision_feedback_sha256' => $retryDecision['feedback_sha256'] ?? null,
            'generation_feedback_sha256' => $generationFeedbackHash,
            'lineage_hash' => $lineageHash,
            'cost_limit_usd' => 0.0,
        ], 'A bounded local structural-simulation attempt was reserved.');
        $this->synchronize($project, $run);

        try {
            $result = $this->generation->generate(
                $project,
                $run,
                $phase,
                $context['inputs'],
                $feedback,
                $requiredSlots,
                $context['expected_sources_by_slot'],
                true,
                $attemptId,
                [
                    'automation_id' => $state['automation_id'] ?? null,
                    'attempt_number' => $attemptNumber,
                    'prior_evaluation_id' => $state['latest_evaluation_id'] ?? null,
                    'prior_evaluation_diagnosis' => $retryContext['diagnosis'] ?? null,
                    'retry_context_hash' => $retryContextHash,
                    'retry_decision_event_id' => $retryDecision['event_id'] ?? null,
                    'decision_feedback_sha256' => $retryDecision['feedback_sha256'] ?? null,
                    'generation_feedback_sha256' => $generationFeedbackHash,
                    'lineage_hash' => $lineageHash,
                ],
            );
        } catch (Throwable $exception) {
            $this->terminalizeAttempt($project, $run, $phase, $attemptId, 'failed', $exception->getMessage());
            $this->stateStore->appendEvent($project, $run, 'attempt_failed', [
                'phase' => $phase,
                'attempt_id' => $attemptId,
            ], $exception->getMessage());

            return $this->synchronize($project, $run);
        }

        $this->stateStore->appendEvent($project, $run, 'attempt_committed', [
            'phase' => $phase,
            'attempt_id' => $attemptId,
            'artifact_ids' => $result['artifact_ids'],
        ], 'The complete validated simulation batch was committed.');

        return $this->synchronize($project, $run);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function recoverGeneration(string $project, string $run, array $state): array
    {
        $phase = (string) ($state['current_phase'] ?? '');
        $attemptId = (string) ($state['active_attempt_id'] ?? '');
        if ($attemptId === '') {
            throw new RuntimeException('Generating checkpoint has no active attempt ID.');
        }

        if (! $this->attempts->requestExists($project, $run, $phase, $attemptId)) {
            $this->recoverMissingAttemptManifest($project, $run, $phase, $attemptId);
            $this->stateStore->appendEvent($project, $run, 'attempt_interrupted', [
                'phase' => $phase,
                'attempt_id' => $attemptId,
            ], 'The event-only reservation was reconstructed as a valid interrupted attempt and released for a bounded retry.');

            return $this->synchronize($project, $run);
        }

        $attempt = $this->attempts->attempt($project, $run, $phase, $attemptId);
        $result = $attempt['result'];
        if (is_array($result) && ($result['status'] ?? null) === 'committed') {
            $artifactIds = is_array($result['artifact_ids'] ?? null)
                ? array_values(array_filter($result['artifact_ids'], 'is_string'))
                : [];
            $this->feedbackApplications->markApplied(
                $project,
                $run,
                $phase,
                $attemptId,
                $artifactIds,
            );
            $this->stateStore->appendEvent($project, $run, 'attempt_committed', [
                'phase' => $phase,
                'attempt_id' => $attemptId,
                'artifact_ids' => $artifactIds,
            ], 'Resume found the complete committed result and advanced to structural evaluation.');

            return $this->synchronize($project, $run);
        }

        if ($result === null) {
            $this->terminalizeAttempt(
                $project,
                $run,
                $phase,
                $attemptId,
                'interrupted',
                'Resume found no terminal result manifest.',
            );
        } elseif (in_array($result['status'] ?? null, ['failed', 'cancelled', 'interrupted'], true)) {
            $this->feedbackApplications->markNotApplied(
                $project,
                $run,
                $phase,
                $attemptId,
                is_string($result['error'] ?? null)
                    ? $result['error']
                    : 'Recovery found a terminal generation attempt without a committed artifact batch.',
            );
        }

        $this->stateStore->appendEvent($project, $run, 'attempt_interrupted', [
            'phase' => $phase,
            'attempt_id' => $attemptId,
        ], 'The incomplete or unsuccessful attempt was retained and a bounded retry may proceed.');

        return $this->synchronize($project, $run);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function evaluateAttempt(string $project, string $run, array $state): array
    {
        $phase = (string) ($state['current_phase'] ?? '');
        $attemptId = (string) ($state['active_attempt_id'] ?? '');
        $attempt = $this->attempts->attempt($project, $run, $phase, $attemptId);
        $result = $attempt['result'];
        if (! is_array($result) || ($result['status'] ?? null) !== 'committed') {
            throw new RuntimeException('Only a committed generation attempt can be evaluated.');
        }

        $artifactIds = is_array($result['artifact_ids'] ?? null) ? $result['artifact_ids'] : [];
        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        $artifacts = array_values(array_filter(
            $phaseWorkspace['artifacts'],
            fn (array $artifact): bool => in_array($artifact['id'] ?? null, $artifactIds, true)
                && ($artifact['metadata']['generation_attempt_id'] ?? null) === $attemptId,
        ));
        if (count($artifacts) !== count($artifactIds)) {
            throw new RuntimeException('The committed attempt no longer resolves to its exact candidate set.');
        }

        $context = $this->workspace->phaseContext($project, $run, $phase);
        $requiredSlots = $context['required_slots'];
        $policy = $this->stateStore->policy($project, $run);
        $evaluation = $this->evaluator->evaluate($phase, $artifacts, $requiredSlots, (int) $policy['score_threshold']);
        $evaluationId = $this->stateStore->newId('evaluation-'.$phase);
        $evaluationRecord = array_merge($evaluation, [
            'automation_id' => $state['automation_id'] ?? null,
            'attempt_id' => $attemptId,
            'candidate_ids' => array_values(array_filter($artifactIds, 'is_string')),
            'required_slots' => $requiredSlots,
            'source_artifact_ids' => collect($context['inputs'])
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_string($id))
                ->values()
                ->all(),
            'lineage_hash' => $this->lineageHash($context['inputs']),
            'prior_evaluation_id' => $state['latest_evaluation_id'] ?? null,
        ]);
        $this->stateStore->writeEvaluation($project, $run, $phase, $evaluationId, $evaluationRecord);
        $this->stateStore->appendEvent($project, $run, 'evaluation_recorded', [
            'phase' => $phase,
            'attempt_id' => $attemptId,
            'evaluation_id' => $evaluationId,
            'passed' => $evaluation['passed'],
        ], $evaluation['diagnosis']);

        if (! $evaluation['passed']) {
            $this->stateStore->appendEvent($project, $run, 'retry_scheduled', [
                'phase' => $phase,
                'evaluation_id' => $evaluationId,
            ], $evaluation['diagnosis']);
        }

        return $this->synchronize($project, $run);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function selectRecommendations(string $project, string $run, array $state): array
    {
        $phase = (string) ($state['current_phase'] ?? '');
        $evaluationId = (string) ($state['latest_evaluation_id'] ?? '');
        $evaluation = $this->stateStore->evaluation($project, $run, $phase, $evaluationId);
        if (($evaluation['passed'] ?? null) !== true) {
            throw new RuntimeException('Only a passed structural evaluation can authorize automatic selection.');
        }
        $context = $this->workspace->phaseContext($project, $run, $phase);
        $requiredSlots = $context['required_slots'];
        $recommendations = is_array($evaluation['recommendations'] ?? null)
            ? $evaluation['recommendations']
            : [];
        if (array_keys($recommendations) !== $requiredSlots
            || ($evaluation['lineage_hash'] ?? null) !== $this->lineageHash($context['inputs'])) {
            throw new RuntimeException('Structural recommendations no longer match current slots or input lineage.');
        }

        if ($phase === 'ideas') {
            $source = $this->ideasSourceEvidence($project, $run, false);
            $recommendedIdea = is_string($recommendations['primary'] ?? null)
                ? $recommendations['primary']
                : '';
            $exactRecoveredAutomaticSelection = $source['explicit']
                && $source['mode'] === 'selected_idea'
                && $source['source_id'] === $recommendedIdea
                && $this->currentSelectionProvesAutomation(
                    $project,
                    $run,
                    'ideas',
                    'primary',
                    $recommendedIdea,
                    $evaluationId,
                );
            if ($source['explicit'] && ! $exactRecoveredAutomaticSelection) {
                return $this->reuseChangedIdeasSource(
                    $project,
                    $run,
                    $evaluationId,
                    $source,
                );
            }
        }
        $selected = $this->currentSelectionIds($project, $run, $phase);

        foreach ($recommendations as $slot => $artifactId) {
            if (! is_string($slot) || ! is_string($artifactId) || $artifactId === '') {
                throw new RuntimeException('The structural evaluation recommendation is malformed.');
            }
            if (($selected[$slot] ?? null) === $artifactId) {
                if ($this->currentSelectionProvesAutomation(
                    $project,
                    $run,
                    $phase,
                    $slot,
                    $artifactId,
                    $evaluationId,
                )) {
                    $this->recordSelectionEventIfMissing(
                        $project,
                        $run,
                        $phase,
                        $slot,
                        $artifactId,
                        $evaluationId,
                        'Resume recovered the missing event from the exact effective immutable automatic selection record.',
                    );

                    continue;
                }
            }

            $this->workspace->select(
                $project,
                $run,
                $phase,
                $artifactId,
                'automation',
                $evaluationId,
                'Highest eligible deterministic structural score for '.$slot.'.',
            );
            $this->recordSelectionEventIfMissing(
                $project,
                $run,
                $phase,
                $slot,
                $artifactId,
                $evaluationId,
                'Automation created the same immutable selection record used by manual mode.',
            );
        }

        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        if (! $phaseWorkspace['complete']) {
            throw new RuntimeException('Structural recommendations did not complete every required phase slot.');
        }
        $selectionIds = $this->currentSelectionIds($project, $run, $phase);
        if ($selectionIds !== $recommendations) {
            throw new RuntimeException('Effective selections do not equal the exact structural recommendations.');
        }

        $this->stateStore->appendEvent($project, $run, 'phase_completed', $this->withIdeasSourceEvidence($project, $run, $phase, [
            'phase' => $phase,
            'evaluation_id' => $evaluationId,
            'selection_ids' => $selectionIds,
        ]), 'Every required slot now has one current valid structurally recommended selection.');
        $policy = $this->stateStore->policy($project, $run);
        if (in_array($phase, $policy['human_gates'], true)) {
            $this->stateStore->appendEvent($project, $run, 'gate_requested', $this->withIdeasSourceEvidence($project, $run, $phase, [
                'phase' => $phase,
                'gate_kind' => 'review',
                'evaluation_id' => $evaluationId,
                'selection_ids' => $selectionIds,
                'reason' => 'Review the structural recommendation and choose approve, manual override, retry with feedback, or cancel.',
            ]), 'A configured human gate requires an explicit decision before the next phase.');
        } elseif ($this->phases->next($phase) === null) {
            $this->stateStore->appendEvent($project, $run, 'automation_completed', [
                'phase' => $phase,
            ], 'The complete automatic run is reconstructible from project files.');
        }

        return $this->synchronize($project, $run);
    }

    /**
     * A human source decision that lands after evaluation is authoritative. The
     * stale recommendation remains immutable history but cannot overwrite it.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function reuseChangedIdeasSource(
        string $project,
        string $run,
        string $evaluationId,
        array $source,
    ): array {
        $selectionIds = ['primary' => (string) $source['source_id']];
        $reason = 'The effective Ideas source changed after structural evaluation; the stale recommendation was invalidated and the explicit source was preserved.';
        $this->stateStore->appendEvent($project, $run, 'phase_reused', [
            'phase' => 'ideas',
            'selection_ids' => $selectionIds,
            'ideas_source' => $source,
            'superseded_evaluation_id' => $evaluationId,
        ], $reason);

        $policy = $this->stateStore->policy($project, $run);
        if (in_array('ideas', $policy['human_gates'], true)) {
            $this->stateStore->appendEvent($project, $run, 'gate_requested', [
                'phase' => 'ideas',
                'gate_kind' => 'human_action',
                'selection_ids' => $selectionIds,
                'ideas_source' => $source,
                'reason' => $reason,
            ], 'A fresh human-action gate is bound to the newer explicit Ideas source.');
        }

        return $this->synchronize($project, $run);
    }

    /** @return array<string, mixed> */
    private function pauseForHuman(string $project, string $run, string $phase, string $reason): array
    {
        $this->stateStore->appendEvent($project, $run, 'gate_requested', $this->withIdeasSourceEvidence($project, $run, $phase, [
            'phase' => $phase,
            'gate_kind' => 'human_action',
            'reason' => $reason,
            'selection_ids' => $this->currentSelectionIds($project, $run, $phase),
        ], requireExplicit: false), $reason);

        return $this->synchronize($project, $run);
    }

    /** @return array<string, mixed> */
    private function cancelUnlocked(string $project, string $run): array
    {
        if (! $this->stateStore->policyExists($project, $run)) {
            throw new RuntimeException('This run has no automatic-mode state to cancel.');
        }
        if (! $this->stateStore->stateExists($project, $run)) {
            $this->stateStore->recoverPolicyOnly($project, $run);
        }
        $state = $this->reconcileCheckpoint($project, $run);
        if (($state['status'] ?? null) === 'complete') {
            throw new RuntimeException('A completed automatic run cannot be cancelled.');
        }
        if (($state['status'] ?? null) === 'cancelled') {
            return $state;
        }

        $phase = (string) ($state['current_phase'] ?? '');
        $attemptId = is_string($state['active_attempt_id'] ?? null) ? $state['active_attempt_id'] : '';
        if ($attemptId === '' && ($state['cancel_requested'] ?? false) === true) {
            $attemptId = $this->latestPendingCancellationAttemptId($project, $run) ?? '';
        }
        if (($state['cancel_requested'] ?? false) !== true) {
            $this->stateStore->appendEvent($project, $run, 'cancel_requested', [
                'phase' => $phase,
                'active_attempt_id' => $attemptId !== '' ? $attemptId : null,
            ], 'A person requested checkpoint cancellation. Synchronous in-flight work is allowed to finish before this lock is acquired.');
        }
        if ($attemptId !== '') {
            $this->prepareAttemptForCancellation($project, $run, $phase, $attemptId);
        }
        $this->stateStore->appendEvent($project, $run, 'automation_cancelled', [
            'phase' => $phase,
            'active_attempt_id' => $attemptId !== '' ? $attemptId : null,
            'selection_ids' => $this->currentSelectionIds($project, $run, $phase),
        ], 'Automatic work stopped at a durable checkpoint. Existing files and history were preserved.');

        return $this->synchronize($project, $run);
    }

    /** @return array<string, mixed> */
    private function decideUnlocked(
        string $project,
        string $run,
        string $decision,
        ?string $feedback,
        ?string $gateToken,
    ): array {
        if (! in_array($decision, ['approve', 'manual_override', 'retry_with_feedback', 'cancel'], true)) {
            throw new RuntimeException('Gate decision must be approve, manual_override, retry_with_feedback, or cancel.');
        }
        $gateToken = is_string($gateToken) ? trim($gateToken) : '';
        if ($gateToken === '') {
            throw new RuntimeException('Gate decision requires the exact durable gate token.');
        }
        $state = $this->reconcileCheckpoint($project, $run);
        if (($state['status'] ?? null) === 'awaiting_review') {
            $currentGateToken = $this->currentGateToken($project, $run, $state);
            if (! hash_equals($currentGateToken, $gateToken)) {
                throw new RuntimeException('This gate decision is stale. Reload the current gate before deciding it.');
            }
        } elseif ($this->decisionAlreadyRecorded($project, $run, $gateToken, $decision, $feedback)) {
            return $state;
        } else {
            throw new RuntimeException('This automatic run is not waiting for a gate decision.');
        }
        $phase = (string) $state['current_phase'];
        $selectionIds = $this->currentSelectionIds($project, $run, $phase);
        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        $complete = $phaseWorkspace['complete'];
        $evaluationId = is_string($state['latest_evaluation_id'] ?? null)
            ? $state['latest_evaluation_id']
            : null;

        if ($decision === 'approve') {
            if (($state['step'] ?? null) !== 'gate' || $evaluationId === null) {
                throw new RuntimeException('Approve is available only for a completed structural recommendation gate.');
            }
            $recommendations = $this->recommendations($project, $run, $phase, $evaluationId);
            if (! $complete || $selectionIds !== $recommendations) {
                throw new RuntimeException('Current selections differ from the recommendation; choose manual_override instead.');
            }
        } elseif ($decision === 'manual_override') {
            if (! $complete) {
                throw new RuntimeException('Complete every required phase selection before accepting a manual override.');
            }
        } elseif ($decision === 'retry_with_feedback') {
            $feedback = is_string($feedback) ? trim($feedback) : '';
            if ($feedback === '') {
                throw new RuntimeException('Retry with feedback requires a non-empty feedback note.');
            }
            $this->ensureRetryFeedback($project, $run, $phase, $gateToken, $feedback);
        }

        $this->stateStore->appendEvent($project, $run, 'gate_decided', $this->withIdeasSourceEvidence($project, $run, $phase, [
            'phase' => $phase,
            'gate_token' => $gateToken,
            'decision' => $decision,
            'evaluation_id' => $evaluationId,
            'selection_ids' => $selectionIds,
            'feedback_sha256' => $decision === 'retry_with_feedback'
                ? hash('sha256', (string) $feedback)
                : null,
        ], requireExplicit: ! in_array($decision, ['retry_with_feedback', 'cancel'], true)), match ($decision) {
            'approve' => 'A person explicitly approved the exact structural recommendations recorded here.',
            'manual_override' => 'A person explicitly continued with the exact effective manual selections recorded here.',
            'retry_with_feedback' => 'A person explicitly requested another bounded structural attempt using linked pending feedback.',
            'cancel' => 'A person explicitly cancelled at the review gate.',
        });

        if ($decision === 'cancel') {
            $this->stateStore->appendEvent($project, $run, 'automation_cancelled', [
                'phase' => $phase,
                'selection_ids' => $selectionIds,
            ], 'Automatic work stopped at the explicit human gate.');

            return $this->synchronize($project, $run);
        }
        if (in_array($decision, ['approve', 'manual_override'], true)
            && $this->phases->next($phase) === null) {
            $this->stateStore->appendEvent($project, $run, 'automation_completed', [
                'phase' => $phase,
            ], 'The final human gate was explicitly decided and the run is complete.');
        }
        $this->synchronize($project, $run);

        return $this->runUntilPauseUnlocked($project, $run);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function gateContext(string $project, string $run, array $state): array
    {
        if (($state['status'] ?? null) !== 'awaiting_review') {
            return [];
        }
        $phase = (string) $state['current_phase'];
        $selectionIds = $this->currentSelectionIds($project, $run, $phase);
        $evaluationId = is_string($state['latest_evaluation_id'] ?? null)
            ? $state['latest_evaluation_id']
            : null;

        return [
            'gate_token' => $this->currentGateToken($project, $run, $state),
            'phase' => $phase,
            'kind' => ($state['step'] ?? null) === 'gate' ? 'review' : 'human_action',
            'evaluation_id' => $evaluationId,
            'selection_ids' => $selectionIds,
            'recommendations' => $evaluationId !== null
                ? $this->recommendations($project, $run, $phase, $evaluationId)
                : [],
            'ideas_source' => $phase === 'ideas'
                ? $this->ideasSourceEvidence($project, $run, false)
                : null,
            'decisions' => ['approve', 'manual_override', 'retry_with_feedback', 'cancel'],
        ];
    }

    /** @return array<string, string> */
    private function recommendations(string $project, string $run, string $phase, string $evaluationId): array
    {
        $evaluation = $this->stateStore->evaluation($project, $run, $phase, $evaluationId);
        $recommendations = is_array($evaluation['recommendations'] ?? null)
            ? array_filter($evaluation['recommendations'], 'is_string')
            : [];
        ksort($recommendations);

        return $recommendations;
    }

    private function decisionAlreadyRecorded(
        string $project,
        string $run,
        string $gateToken,
        string $decision,
        ?string $feedback,
    ): bool {
        $events = $this->stateStore->events($project, $run);
        $latest = collect($events)->first(
            fn (array $event): bool => ($event['event'] ?? null) === 'gate_decided'
                && ($event['gate_token'] ?? null) === $gateToken,
        );
        if (! is_array($latest) || ($latest['decision'] ?? null) !== $decision) {
            return false;
        }
        if ($decision !== 'retry_with_feedback') {
            return true;
        }

        return is_string($feedback)
            && ($latest['feedback_sha256'] ?? null) === hash('sha256', trim($feedback));
    }

    /** @param array<string, mixed> $state */
    private function currentGateToken(string $project, string $run, array $state): string
    {
        $phase = (string) ($state['current_phase'] ?? '');
        $evaluationId = is_string($state['latest_evaluation_id'] ?? null)
            ? $state['latest_evaluation_id']
            : null;

        foreach (array_reverse($this->stateStore->events($project, $run)) as $event) {
            if (($event['phase'] ?? null) !== $phase) {
                continue;
            }
            if (($event['event'] ?? null) === 'gate_decided') {
                break;
            }
            if (($event['event'] ?? null) === 'gate_requested'
                || (($event['event'] ?? null) === 'phase_reused'
                    && ($state['step'] ?? null) === 'human_action')
                || (($event['event'] ?? null) === 'phase_completed'
                    && ($state['step'] ?? null) === 'gate'
                    && ($event['evaluation_id'] ?? null) === $evaluationId)) {
                $token = is_string($event['id'] ?? null) ? $event['id'] : '';
                if ($token !== '') {
                    return $token;
                }
            }
        }

        throw new RuntimeException('The pending review gate has no durable token.');
    }

    /** @return array<string, string> */
    private function currentSelectionIds(string $project, string $run, string $phase): array
    {
        if ($phase === 'ideas') {
            $source = $this->ideasSourceEvidence($project, $run, false);

            return $source['explicit']
                ? ['primary' => $source['source_id']]
                : [];
        }

        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        $selected = [];
        foreach ($phaseWorkspace['artifacts'] as $artifact) {
            if (($artifact['selected'] ?? false) === true
                && is_string($artifact['slot'] ?? null)
                && is_string($artifact['id'] ?? null)) {
                $selected[$artifact['slot']] = $artifact['id'];
            }
        }
        ksort($selected);

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withIdeasSourceEvidence(
        string $project,
        string $run,
        string $phase,
        array $metadata,
        bool $requireExplicit = true,
    ): array {
        if ($phase !== 'ideas') {
            return $metadata;
        }

        return $metadata + [
            'ideas_source' => $this->ideasSourceEvidence($project, $run, $requireExplicit),
        ];
    }

    /**
     * @return array{
     *     mode: 'selected_idea'|'project_idea',
     *     source_id: string,
     *     explicit: bool,
     *     skipped: bool,
     *     decision_id: string|null,
     *     decision_sequence: int|null,
     *     lineage_sequence: int|null,
     *     artifact_id: string|null,
     *     selection_id: string|null,
     *     selected_by: string|null
     * }
     */
    private function ideasSourceEvidence(
        string $project,
        string $run,
        bool $requireExplicit = true,
    ): array {
        $source = $this->workspace->effectiveIdeaSource($project, $run);
        $mode = $source['mode'];
        $sourceId = trim($source['source_id']);
        $explicit = $source['explicit'];
        $skipped = $source['skipped'];
        $decision = $source['decision'];

        if ($sourceId === '') {
            throw new RuntimeException('The effective Ideas source is malformed.');
        }
        if ($requireExplicit && ! $explicit) {
            throw new RuntimeException('The effective Ideas source has no explicit immutable source decision.');
        }
        if ($explicit && ! is_array($decision)) {
            throw new RuntimeException('The explicit Ideas source is missing its immutable decision record.');
        }

        $evidence = [
            'mode' => $mode,
            'source_id' => $sourceId,
            'explicit' => $explicit,
            'skipped' => $skipped,
            'decision_id' => is_string($decision['id'] ?? null) ? $decision['id'] : null,
            'decision_sequence' => is_int($decision['sequence'] ?? null) ? $decision['sequence'] : null,
            'lineage_sequence' => is_int($decision['lineage_sequence'] ?? null) ? $decision['lineage_sequence'] : null,
            'artifact_id' => is_string($decision['artifact_id'] ?? null) ? $decision['artifact_id'] : null,
            'selection_id' => is_string($decision['selection_id'] ?? null) ? $decision['selection_id'] : null,
            'selected_by' => is_string($decision['selected_by'] ?? null) ? $decision['selected_by'] : null,
        ];

        if ($explicit
            && (! is_string($evidence['decision_id'])
                || ! is_int($evidence['decision_sequence'])
                || ! in_array($evidence['selected_by'], ['human', 'automation'], true)
                || ($mode === 'selected_idea'
                    && ($evidence['artifact_id'] !== $sourceId
                        || ! is_string($evidence['selection_id'])))
                || ($mode === 'project_idea'
                    && ($evidence['artifact_id'] !== null
                        || $evidence['selection_id'] !== null
                        || ! $skipped)))) {
            throw new RuntimeException('The explicit Ideas source decision evidence is incomplete or contradictory.');
        }

        return $evidence;
    }

    /**
     * Repair only the narrow crash window where an exact automatic Ideas
     * selection exists but its paired source-decision file was interrupted.
     */
    private function recoverAutomaticIdeaSourceDecision(string $project, string $run): void
    {
        $policy = $this->stateStore->policy($project, $run);
        if (($policy['start_phase'] ?? null) !== 'ideas') {
            return;
        }

        $source = $this->workspace->effectiveIdeaSource($project, $run);
        if ($source['mode'] !== 'selected_idea' || $source['explicit']) {
            return;
        }

        $workspace = $this->workspace->workspace($project, $run, 'ideas', $source['source_id']);
        $artifact = collect(is_array($workspace['artifacts'] ?? null) ? $workspace['artifacts'] : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['selected'] ?? false) === true
                && ($candidate['id'] ?? null) === $source['source_id']);
        $selection = is_array($artifact) && is_array($artifact['selection'] ?? null)
            ? $artifact['selection']
            : null;
        $evaluationId = is_array($selection) && is_string($selection['evaluation_id'] ?? null)
            ? $selection['evaluation_id']
            : null;
        if (! is_array($selection)
            || ($selection['selected_by'] ?? null) !== 'automation'
            || $evaluationId === null) {
            return;
        }

        $evaluation = $this->stateStore->evaluation($project, $run, 'ideas', $evaluationId);
        if (($evaluation['passed'] ?? null) !== true
            || ($evaluation['recommendations']['primary'] ?? null) !== $source['source_id']) {
            throw new RuntimeException('The interrupted automatic Ideas selection is not authorized by its exact evaluation.');
        }

        $this->workspace->ensureCurrentIdeaSourceDecision(
            $project,
            $run,
            'automation',
            'Recovered the source decision for the exact evaluated automatic Ideas selection.',
        );
    }

    private function currentSelectionProvesAutomation(
        string $project,
        string $run,
        string $phase,
        string $slot,
        string $artifactId,
        string $evaluationId,
    ): bool {
        $workspace = $this->workspace->workspace($project, $run, $phase, $artifactId);
        $artifacts = is_array($workspace['artifacts'] ?? null) ? $workspace['artifacts'] : [];
        $artifact = collect($artifacts)->first(
            fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['selected'] ?? false) === true
                && ($candidate['slot'] ?? null) === $slot
                && ($candidate['id'] ?? null) === $artifactId,
        );
        $selection = is_array($artifact) && is_array($artifact['selection'] ?? null)
            ? $artifact['selection']
            : null;

        return is_array($selection)
            && ($selection['phase'] ?? null) === $phase
            && ($selection['slot'] ?? null) === $slot
            && ($selection['artifact_id'] ?? null) === $artifactId
            && ($selection['selected_by'] ?? null) === 'automation'
            && ($selection['evaluation_id'] ?? null) === $evaluationId;
    }

    private function recordSelectionEventIfMissing(
        string $project,
        string $run,
        string $phase,
        string $slot,
        string $artifactId,
        string $evaluationId,
        string $message,
    ): void {
        $recorded = collect($this->stateStore->events($project, $run))->contains(
            fn (array $event): bool => ($event['event'] ?? null) === 'selection_recorded'
                && ($event['phase'] ?? null) === $phase
                && ($event['slot'] ?? null) === $slot
                && ($event['artifact_id'] ?? null) === $artifactId
                && ($event['evaluation_id'] ?? null) === $evaluationId,
        );
        if ($recorded) {
            return;
        }

        if (! $this->currentSelectionProvesAutomation(
            $project,
            $run,
            $phase,
            $slot,
            $artifactId,
            $evaluationId,
        )) {
            throw new RuntimeException('Automatic selection evidence does not match the current effective immutable selection record.');
        }

        $this->stateStore->appendEvent($project, $run, 'selection_recorded', [
            'phase' => $phase,
            'slot' => $slot,
            'artifact_id' => $artifactId,
            'evaluation_id' => $evaluationId,
        ], $message);
    }

    private function ensureRetryFeedback(
        string $project,
        string $run,
        string $phase,
        string $gateToken,
        string $feedback,
    ): void {
        $feedbackHash = hash('sha256', $feedback);
        $markerPrefix = "<!-- videoauto:automation-retry gate_token={$gateToken} ";
        $marker = $markerPrefix."feedback_sha256={$feedbackHash} -->";
        $pendingFeedback = $this->workspace->phaseContext($project, $run, $phase)['feedback'];

        if (str_contains($pendingFeedback, $marker)) {
            return;
        }
        if (str_contains($pendingFeedback, $markerPrefix)) {
            throw new RuntimeException('This gate already has a different durable retry-feedback note. Reload before deciding it.');
        }

        $this->workspace->appendFeedback(
            $project,
            $run,
            $phase,
            'phase',
            $feedback."\n\n{$marker}",
        );
    }

    /** @return array<string, mixed> */
    private function reconcileCheckpoint(string $project, string $run): array
    {
        $this->reconcileTerminalAttemptSideEffects($project, $run);
        $this->recoverAutomaticIdeaSourceDecision($project, $run);
        $projection = $this->projector->project($project, $run);
        $checkpointExists = $this->stateStore->stateExists($project, $run);
        $checkpoint = null;
        $checkpointError = null;
        if ($checkpointExists) {
            try {
                $checkpoint = $this->stateStore->state($project, $run);
            } catch (RuntimeException $exception) {
                $checkpointError = $exception->getMessage();
            }
        }
        $wasComplete = is_array($checkpoint) && ($checkpoint['status'] ?? null) === 'complete';
        $needsReconcile = ! is_array($checkpoint)
            || ! $this->projector->consistent($checkpoint, $projection['state']);

        if ($wasComplete && $projection['issues'] !== []) {
            $this->stateStore->appendEvent($project, $run, 'completed_checkpoint_reopened', [
                'phase' => $projection['state']['current_phase'],
                'reason' => $projection['issues'][0],
            ], $projection['issues'][0]);
            $projection = $this->projector->project($project, $run);
            $needsReconcile = true;
        } elseif ($needsReconcile && $checkpointExists) {
            $this->stateStore->appendEvent($project, $run, 'checkpoint_reconciled', [
                'phase' => $projection['state']['current_phase'],
                'checkpoint_status' => $checkpoint['status'] ?? null,
                'projected_status' => $projection['state']['status'] ?? null,
                'checkpoint_error' => $checkpointError,
            ], $checkpointError !== null
                ? 'The unreadable mutable checkpoint was replaced by the projection of immutable automation evidence.'
                : 'The mutable checkpoint was replaced by the projection of immutable automation evidence.');
            $projection = $this->projector->project($project, $run);
        }

        return $needsReconcile
            ? $this->stateStore->replaceState($project, $run, $projection['state'])
            : $checkpoint;
    }

    private function reconcileTerminalAttemptSideEffects(string $project, string $run): void
    {
        foreach (array_keys($this->phases->all()) as $phase) {
            foreach ($this->attempts->attempts($project, $run, $phase) as $attempt) {
                $attemptId = $attempt['attempt_id'];
                $result = $attempt['result'];
                if (! is_array($result)) {
                    continue;
                }

                $status = $result['status'] ?? null;
                if ($status === 'committed') {
                    $artifactIds = is_array($result['artifact_ids'] ?? null)
                        ? array_values(array_filter($result['artifact_ids'], 'is_string'))
                        : [];
                    $this->feedbackApplications->markApplied(
                        $project,
                        $run,
                        $phase,
                        $attemptId,
                        $artifactIds,
                    );

                    continue;
                }

                if (! in_array($status, ['failed', 'cancelled', 'interrupted'], true)) {
                    continue;
                }
                if (in_array($phase, ['images', 'scenes'], true)) {
                    $this->mediaJobs->recoverAttempt($project, $run, $phase, $attemptId);
                }
                $this->feedbackApplications->markNotApplied(
                    $project,
                    $run,
                    $phase,
                    $attemptId,
                    is_string($result['error'] ?? null)
                        ? $result['error']
                        : 'Recovery found a terminal generation attempt without a committed artifact batch.',
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function synchronize(string $project, string $run): array
    {
        return $this->stateStore->replaceState(
            $project,
            $run,
            $this->projector->project($project, $run)['state'],
        );
    }

    private function recoverMissingAttemptManifest(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $terminalStatus = 'interrupted',
        string $terminalMessage = 'Recovery reconstructed this request from its durable reservation; generation never produced a committed batch.',
    ): void {
        $reservation = collect($this->stateStore->events($project, $run))->first(
            fn (array $event): bool => ($event['event'] ?? null) === 'attempt_started'
                && ($event['phase'] ?? null) === $phase
                && ($event['attempt_id'] ?? null) === $attemptId,
        );
        if (! is_array($reservation)) {
            throw new RuntimeException("Interrupted attempt [{$attemptId}] has no durable reservation event.");
        }

        $context = $this->workspace->phaseContext($project, $run, $phase);
        $requiredSlots = $this->stringListOrFallback(
            $reservation['required_slots'] ?? null,
            $context['required_slots'],
        );
        $fallbackSourceArtifactIds = [];
        foreach ($context['inputs'] as $input) {
            $id = $input['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $fallbackSourceArtifactIds[] = $id;
            }
        }
        $sourceArtifactIds = $this->stringListOrFallback(
            $reservation['source_artifact_ids'] ?? null,
            $fallbackSourceArtifactIds,
        );
        if ($requiredSlots === [] || $sourceArtifactIds === []) {
            throw new RuntimeException("Interrupted attempt [{$attemptId}] cannot reconstruct its required slots or source lineage.");
        }

        $application = collect($this->feedbackApplications->applications($project, $run))
            ->firstWhere('attempt_id', $attemptId);
        $applicationRequest = is_array($application) && is_array($application['request'] ?? null)
            ? $application['request']
            : null;
        $feedbackHash = is_array($applicationRequest)
            && ($applicationRequest['phase'] ?? null) === $phase
            && $this->sha256($applicationRequest['generation_feedback_sha256'] ?? null)
                ? $applicationRequest['generation_feedback_sha256']
                : ($this->sha256($reservation['generation_feedback_sha256'] ?? null)
                    ? $reservation['generation_feedback_sha256']
                    : hash('sha256', $context['feedback']));
        $lineageHash = $this->sha256($reservation['lineage_hash'] ?? null)
            ? $reservation['lineage_hash']
            : $this->lineageHash($context['inputs']);
        $retryDecisionEventId = is_string($reservation['retry_decision_event_id'] ?? null)
            ? $reservation['retry_decision_event_id']
            : null;
        $decisionFeedbackHash = $this->sha256($reservation['decision_feedback_sha256'] ?? null)
            ? $reservation['decision_feedback_sha256']
            : null;
        if ($retryDecisionEventId !== null && $decisionFeedbackHash === null) {
            $retryDecision = collect($this->stateStore->events($project, $run))->firstWhere('id', $retryDecisionEventId);
            $decisionFeedbackHash = is_array($retryDecision)
                && $this->sha256($retryDecision['feedback_sha256'] ?? null)
                    ? $retryDecision['feedback_sha256']
                    : null;
        }
        if (($retryDecisionEventId === null) !== ($decisionFeedbackHash === null)) {
            throw new RuntimeException("Interrupted attempt [{$attemptId}] cannot reconstruct its retry-decision linkage.");
        }

        $priorEvaluationId = is_string($reservation['prior_evaluation_id'] ?? null)
            ? $reservation['prior_evaluation_id']
            : null;
        $retryContextHash = $this->sha256($reservation['retry_context_hash'] ?? null)
            ? $reservation['retry_context_hash']
            : null;
        if ($priorEvaluationId !== null && $retryContextHash === null) {
            $evaluation = $this->stateStore->evaluation($project, $run, $phase, $priorEvaluationId);
            $retryContextHash = hash('sha256', json_encode([
                'prior_evaluation_id' => $priorEvaluationId,
                'diagnosis' => $evaluation['diagnosis'] ?? null,
                'fatal_issues' => $evaluation['fatal_issues'] ?? [],
            ], JSON_THROW_ON_ERROR));
        }

        $this->attempts->begin($project, $run, $phase, $attemptId, [
            'automation_id' => $this->stateStore->policy($project, $run)['automation_id'],
            'attempt_number' => is_int($reservation['attempt_number'] ?? null)
                ? $reservation['attempt_number']
                : 1,
            'prior_evaluation_id' => $priorEvaluationId,
            'prior_evaluation_diagnosis' => is_string($reservation['prior_evaluation_diagnosis'] ?? null)
                ? $reservation['prior_evaluation_diagnosis']
                : null,
            'retry_context_hash' => $retryContextHash,
            'retry_decision_event_id' => $retryDecisionEventId,
            'decision_feedback_sha256' => $decisionFeedbackHash,
            'generation_feedback_sha256' => $feedbackHash,
            'lineage_hash' => $lineageHash,
            'driver' => 'simulation',
            'source_artifact_ids' => $sourceArtifactIds,
            'required_slots' => $requiredSlots,
            'expected_output_count' => is_int($reservation['expected_output_count'] ?? null)
                ? $reservation['expected_output_count']
                : $this->generation->expectedOutputCount($phase, $requiredSlots),
            'reserved_simulation_units' => is_int($reservation['reserved_simulation_units'] ?? null)
                ? $reservation['reserved_simulation_units']
                : $this->generation->estimatedSimulationUnits($phase, $requiredSlots),
            'cost_limit_usd' => 0.0,
            'recovered_from_event_id' => $reservation['id'] ?? null,
        ]);
        $this->terminalizeAttempt(
            $project,
            $run,
            $phase,
            $attemptId,
            $terminalStatus,
            $terminalMessage,
        );
    }

    private function prepareAttemptForCancellation(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
    ): void {
        if (! $this->attempts->requestExists($project, $run, $phase, $attemptId)) {
            $this->recoverMissingAttemptManifest(
                $project,
                $run,
                $phase,
                $attemptId,
                'cancelled',
                'Cancellation reconstructed and terminalized this event-only attempt reservation.',
            );
            $this->recordAttemptInterruptedEventIfMissing(
                $project,
                $run,
                $phase,
                $attemptId,
                'Cancellation reconstructed and terminalized the active event-only attempt.',
            );

            return;
        }

        $attempt = $this->attempts->attempt($project, $run, $phase, $attemptId);
        $result = $attempt['result'];
        if (is_array($result) && ($result['status'] ?? null) === 'committed') {
            $artifactIds = is_array($result['artifact_ids'] ?? null)
                ? array_values(array_filter($result['artifact_ids'], 'is_string'))
                : [];
            $this->recordAttemptCommittedEventIfMissing(
                $project,
                $run,
                $phase,
                $attemptId,
                $artifactIds,
                'Cancellation preserved the already committed batch for evaluation after resume.',
            );

            return;
        }

        if (! is_array($result)) {
            $this->terminalizeAttempt(
                $project,
                $run,
                $phase,
                $attemptId,
                'cancelled',
                'Cancellation closed the active incomplete attempt.',
            );
        }
        $this->recordAttemptInterruptedEventIfMissing(
            $project,
            $run,
            $phase,
            $attemptId,
            'Cancellation retained and terminalized the active incomplete attempt.',
        );
    }

    /** @param list<string> $artifactIds */
    private function recordAttemptCommittedEventIfMissing(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        array $artifactIds,
        string $message,
    ): void {
        $recorded = collect($this->stateStore->events($project, $run))->contains(
            fn (array $event): bool => ($event['event'] ?? null) === 'attempt_committed'
                && ($event['phase'] ?? null) === $phase
                && ($event['attempt_id'] ?? null) === $attemptId,
        );
        if ($recorded) {
            return;
        }

        $this->stateStore->appendEvent($project, $run, 'attempt_committed', [
            'phase' => $phase,
            'attempt_id' => $attemptId,
            'artifact_ids' => $artifactIds,
        ], $message);
    }

    private function recordAttemptInterruptedEventIfMissing(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $message,
    ): void {
        $recorded = collect($this->stateStore->events($project, $run))->contains(
            fn (array $event): bool => ($event['event'] ?? null) === 'attempt_interrupted'
                && ($event['phase'] ?? null) === $phase
                && ($event['attempt_id'] ?? null) === $attemptId,
        );
        if ($recorded) {
            return;
        }

        $this->stateStore->appendEvent($project, $run, 'attempt_interrupted', [
            'phase' => $phase,
            'attempt_id' => $attemptId,
        ], $message);
    }

    private function latestCancelledAttemptId(string $project, string $run): ?string
    {
        $events = $this->stateStore->events($project, $run);
        $cancelIndex = null;
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) === 'automation_cancelled') {
                $cancelIndex = $index;
                $attemptId = $events[$index]['active_attempt_id'] ?? null;

                if (is_string($attemptId) && $attemptId !== '') {
                    return $attemptId;
                }

                break;
            }
        }
        if ($cancelIndex === null) {
            return null;
        }

        for ($index = $cancelIndex - 1; $index >= 0; $index--) {
            $event = $events[$index];
            if (in_array($event['event'] ?? null, ['automation_cancelled', 'automation_resumed'], true)) {
                break;
            }
            if (($event['event'] ?? null) === 'cancel_requested') {
                $attemptId = $event['active_attempt_id'] ?? null;

                return is_string($attemptId) && $attemptId !== '' ? $attemptId : null;
            }
        }

        return null;
    }

    private function latestPendingCancellationAttemptId(string $project, string $run): ?string
    {
        foreach (array_reverse($this->stateStore->events($project, $run)) as $event) {
            if (in_array($event['event'] ?? null, ['automation_cancelled', 'automation_resumed'], true)) {
                return null;
            }
            if (($event['event'] ?? null) !== 'cancel_requested') {
                continue;
            }

            $attemptId = $event['active_attempt_id'] ?? null;

            return is_string($attemptId) && $attemptId !== '' ? $attemptId : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function stringListOrFallback(mixed $value, array $fallback): array
    {
        $strings = is_array($value) && array_is_list($value)
            ? array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''))
            : [];

        return $strings !== [] ? $strings : $fallback;
    }

    private function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function terminalizeAttempt(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        string $status,
        string $message,
    ): void {
        if (! $this->attempts->requestExists($project, $run, $phase, $attemptId)
            || $this->attempts->resultExists($project, $run, $phase, $attemptId)) {
            return;
        }
        $attempt = $this->attempts->attempt($project, $run, $phase, $attemptId);
        $request = $attempt['request'];
        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        $partialIds = collect($phaseWorkspace['artifacts'])
            ->filter(fn (array $artifact): bool => ($artifact['metadata']['generation_attempt_id'] ?? null) === $attemptId)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();

        if (in_array($phase, ['images', 'scenes'], true)) {
            $this->mediaJobs->recoverAttempt($project, $run, $phase, $attemptId);
        }

        $this->attempts->finish($project, $run, $phase, $attemptId, $status, [
            'driver' => $request['driver'] ?? 'simulation',
            'artifact_ids' => $partialIds,
            'output_count' => count($partialIds),
            'expected_output_count' => $request['expected_output_count'] ?? 1,
            'simulation_units' => $request['reserved_simulation_units'] ?? 0,
            'cost_usd' => 0.0,
            'error' => $message,
        ], $message);
        if (in_array($status, ['failed', 'cancelled', 'interrupted'], true)) {
            $this->feedbackApplications->markNotApplied(
                $project,
                $run,
                $phase,
                $attemptId,
                $message,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function retryContext(string $project, string $run, string $phase, array $state): array
    {
        $evaluationId = is_string($state['latest_evaluation_id'] ?? null)
            ? $state['latest_evaluation_id']
            : '';
        if ($evaluationId === '') {
            return [];
        }
        $evaluation = $this->stateStore->evaluation($project, $run, $phase, $evaluationId);

        return [
            'prior_evaluation_id' => $evaluationId,
            'diagnosis' => $evaluation['diagnosis'] ?? null,
            'fatal_issues' => $evaluation['fatal_issues'] ?? [],
        ];
    }

    /** @return array{event_id: string, feedback_sha256: string}|array{} */
    private function retryDecisionContext(string $project, string $run, string $phase): array
    {
        $event = collect($this->stateStore->events($project, $run))
            ->reverse()
            ->first(fn (array $candidate): bool => ($candidate['event'] ?? null) === 'gate_decided'
                && ($candidate['phase'] ?? null) === $phase
                && ($candidate['decision'] ?? null) === 'retry_with_feedback');
        if (! is_array($event)
            || ! is_string($event['id'] ?? null)
            || ! is_string($event['feedback_sha256'] ?? null)) {
            return [];
        }

        return [
            'event_id' => $event['id'],
            'feedback_sha256' => $event['feedback_sha256'],
        ];
    }

    /** @param array<string, mixed> $retryContext */
    private function withRetryContext(string $feedback, array $retryContext, bool $explicitRetry): string
    {
        if ($retryContext === [] && ! $explicitRetry) {
            return $feedback;
        }

        $diagnosis = trim((string) ($retryContext['diagnosis'] ?? 'A person requested a bounded retry with explicit feedback.'));
        $priorEvaluationId = is_string($retryContext['prior_evaluation_id'] ?? null)
            ? $retryContext['prior_evaluation_id']
            : 'none';
        $marker = "[automatic-retry-context]\nPrior evaluation: {$priorEvaluationId}\nDiagnosis: {$diagnosis}";

        return trim($feedback) === '' ? $marker : trim($feedback)."\n\n{$marker}";
    }

    /** @param list<array<string, mixed>> $inputs */
    private function lineageHash(array $inputs): string
    {
        $ids = collect($inputs)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->map(fn (mixed $id): string => (string) $id)
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode("\0", $ids));
    }

    private function validateStoredPolicy(string $project, string $run): void
    {
        $stored = $this->stateStore->policy($project, $run);
        $startPhase = is_string($stored['start_phase'] ?? null) ? $stored['start_phase'] : '';
        $this->phases->assertValid($startPhase);
        $policy = $this->policies->make($startPhase, $stored);
        foreach ($policy['human_gates'] as $gate) {
            $this->phases->assertValid($gate);
        }
    }

    private function lockKey(string $project, string $run): string
    {
        return "orchestrator:{$project}:{$run}";
    }
}
