<?php

namespace App\Creative\Automation;

use App\Creative\CreativePhaseCatalog;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\FilesystemCreativeStore;
use App\Creative\Generation\GenerationAttemptStore;
use RuntimeException;

final class AutomationRunProjector
{
    public function __construct(
        private readonly AutomationStateStore $stateStore,
        private readonly AutomationRecordValidator $validator,
        private readonly GenerationAttemptStore $attempts,
        private readonly CreativeWorkspaceRepository $workspace,
        private readonly FilesystemCreativeStore $store,
        private readonly CreativePhaseCatalog $phases,
    ) {}

    /**
     * @return array{state: array<string, mixed>, issues: list<string>}
     */
    public function project(string $project, string $run): array
    {
        $policy = $this->stateStore->policy($project, $run);
        $state = $this->initialState($policy);
        $issues = [];
        $reservations = [];
        $evaluationEvents = [];
        $selectionEvents = [];
        $phaseCompletionEvents = [];
        $attemptOutcomeEvents = [];
        $gateEvents = [];
        $eventsById = [];

        $events = $this->stateStore->events($project, $run);
        foreach ($events as $index => $event) {
            if (is_string($event['parse_error'] ?? null)) {
                throw new RuntimeException('Automation history contains an unreadable event.');
            }
            $sequence = $index + 1;
            $this->validator->event(
                $event,
                $policy,
                $sequence,
                is_string($event['_filename'] ?? null) ? $event['_filename'] : null,
            );
            $eventsById[(string) $event['id']] = $event;
            if (($event['event'] ?? null) === 'selection_recorded') {
                $selectionEvents[] = $event;
            } elseif (in_array($event['event'] ?? null, ['attempt_committed', 'attempt_failed', 'attempt_interrupted'], true)) {
                $attemptOutcomeEvents[] = $event;
            } elseif (($event['event'] ?? null) === 'phase_completed') {
                $phaseCompletionEvents[] = $event;
                $gateEvents[] = $event;
            } elseif (in_array($event['event'] ?? null, ['phase_reused', 'gate_requested', 'gate_decided'], true)) {
                $gateEvents[] = $event;
            }
            $this->applyEvent($state, $policy, $event, $reservations, $evaluationEvents);
        }

        $attemptsById = $this->attemptRecords(
            $project,
            $run,
            $policy,
            $reservations,
            $eventsById,
        );
        $this->applyAttemptAccounting($state, $reservations, $attemptsById);
        $this->validateAttemptOutcomeEvidence($attemptOutcomeEvents, $attemptsById);

        $evaluations = $this->evaluationRecords($project, $run, $policy, $attemptsById);
        foreach ($evaluationEvents as $evaluationId) {
            if (! isset($evaluations[$evaluationId])) {
                throw new RuntimeException("Automation event references missing evaluation [{$evaluationId}].");
            }
        }
        $this->validateSelectionEvidence(
            $project,
            $run,
            $selectionEvents,
            $phaseCompletionEvents,
            $gateEvents,
            $evaluations,
            $policy,
        );
        $this->recoverDurableSubstep($state, $attemptsById, $evaluations);
        $this->validateReservedAttemptEvidence($state, $reservations, $attemptsById);
        $this->reconcileCurrentFilesystemTruth($project, $run, $policy, $events, $state, $issues);
        $this->validatePendingGate($project, $run, $state, $evaluations);

        $state['updated_at'] = now('UTC')->format('Y-m-d\TH:i:s.uP');
        $this->validator->state($state, $policy);

        return ['state' => $state, 'issues' => $issues];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $projection
     */
    public function consistent(array $checkpoint, array $projection): bool
    {
        $keys = [
            'automation_id',
            'status',
            'step',
            'current_phase',
            'attempts_by_phase',
            'total_attempts',
            'simulation_units_spent',
            'active_attempt_id',
            'latest_evaluation_id',
            'gate_phase',
            'gate_reason',
            'cancel_requested',
            'retry_requested',
            'last_error',
        ];

        foreach ($keys as $key) {
            if (($checkpoint[$key] ?? null) !== ($projection[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function initialState(array $policy): array
    {
        $createdAt = is_string($policy['created_at'] ?? null)
            ? $policy['created_at']
            : now('UTC')->format('Y-m-d\TH:i:s.uP');

        return [
            'schema_version' => 1,
            'id' => $policy['automation_id'],
            'type' => 'automation_state',
            'automation_id' => $policy['automation_id'],
            'status' => 'pending',
            'step' => 'ready',
            'current_phase' => $policy['start_phase'],
            'attempts_by_phase' => [],
            'total_attempts' => 0,
            'simulation_units_spent' => 0,
            'active_attempt_id' => null,
            'latest_evaluation_id' => null,
            'gate_phase' => null,
            'gate_reason' => null,
            'cancel_requested' => false,
            'retry_requested' => false,
            'last_error' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $event
     * @param  array<string, array{phase: string, units: int, attempt_number: int, prior_evaluation_id: string|null, retry_decision_event_id: string|null}>  $reservations
     * @param  list<string>  $evaluationEvents
     */
    private function applyEvent(
        array &$state,
        array $policy,
        array $event,
        array &$reservations,
        array &$evaluationEvents,
    ): void {
        $name = (string) $event['event'];
        $phase = is_string($event['phase'] ?? null) ? $event['phase'] : (string) $state['current_phase'];
        $body = is_string($event['message'] ?? null)
            ? trim($event['message'])
            : (is_string($event['_body'] ?? null) ? trim($event['_body']) : null);

        switch ($name) {
            case 'attempt_started':
                $attemptId = (string) $event['attempt_id'];
                if (isset($reservations[$attemptId])) {
                    throw new RuntimeException("Automatic attempt [{$attemptId}] was reserved more than once.");
                }
                $reservations[$attemptId] = [
                    'phase' => $phase,
                    'units' => (int) $event['reserved_simulation_units'],
                    'attempt_number' => (int) $event['attempt_number'],
                    'prior_evaluation_id' => is_string($event['prior_evaluation_id'] ?? null)
                        ? $event['prior_evaluation_id']
                        : null,
                    'retry_decision_event_id' => is_string($event['retry_decision_event_id'] ?? null)
                        ? $event['retry_decision_event_id']
                        : null,
                ];
                $state = array_merge($state, [
                    'status' => 'running',
                    'step' => 'generating',
                    'current_phase' => $phase,
                    'active_attempt_id' => $attemptId,
                    'retry_requested' => false,
                    'gate_phase' => null,
                    'gate_reason' => null,
                    'last_error' => null,
                ]);
                break;

            case 'attempt_committed':
                $state = array_merge($state, [
                    'status' => 'running',
                    'step' => 'evaluating',
                    'current_phase' => $phase,
                    'active_attempt_id' => $event['attempt_id'],
                ]);
                break;

            case 'attempt_failed':
            case 'attempt_interrupted':
                $state = array_merge($state, [
                    'status' => 'pending',
                    'step' => 'ready',
                    'current_phase' => $phase,
                    'active_attempt_id' => null,
                    'last_error' => $body,
                ]);
                break;

            case 'evaluation_recorded':
                $evaluationId = (string) ($event['evaluation_id'] ?? '');
                if ($evaluationId === '') {
                    throw new RuntimeException('Evaluation-recorded event has no evaluation ID.');
                }
                $evaluationEvents[] = $evaluationId;
                $passed = ($event['passed'] ?? null) === true;
                $state = array_merge($state, [
                    'status' => $passed ? 'running' : 'pending',
                    'step' => $passed ? 'selecting' : 'ready',
                    'current_phase' => $phase,
                    'latest_evaluation_id' => $evaluationId,
                    'active_attempt_id' => $passed ? $state['active_attempt_id'] : null,
                    'last_error' => $passed ? null : $body,
                ]);
                break;

            case 'retry_scheduled':
                $state = array_merge($state, [
                    'status' => 'pending',
                    'step' => 'ready',
                    'current_phase' => $phase,
                    'active_attempt_id' => null,
                    'last_error' => $body,
                ]);
                break;

            case 'phase_completed':
                $state['current_phase'] = $phase;
                if (in_array($phase, $policy['human_gates'], true)) {
                    $state = array_merge($state, [
                        'status' => 'awaiting_review',
                        'step' => 'gate',
                        'gate_phase' => $phase,
                        'gate_reason' => 'Review the structural recommendation and make an explicit gate decision.',
                        'active_attempt_id' => null,
                    ]);
                } else {
                    $this->advance($state, $phase);
                }
                break;

            case 'phase_reused':
                if (in_array($phase, $policy['human_gates'], true)) {
                    $state = array_merge($state, [
                        'status' => 'awaiting_review',
                        'step' => 'human_action',
                        'current_phase' => $phase,
                        'latest_evaluation_id' => null,
                        'gate_phase' => $phase,
                        'gate_reason' => 'This pre-existing complete phase is a configured human gate; explicitly continue with its current selections.',
                        'active_attempt_id' => null,
                    ]);
                } else {
                    $this->advance($state, $phase);
                }
                break;

            case 'gate_requested':
                $kind = ($event['gate_kind'] ?? null) === 'review' || isset($event['evaluation_id'])
                    ? 'gate'
                    : 'human_action';
                $state = array_merge($state, [
                    'status' => 'awaiting_review',
                    'step' => $kind,
                    'current_phase' => $phase,
                    'gate_phase' => $phase,
                    'gate_reason' => is_string($event['reason'] ?? null) ? $event['reason'] : $body,
                    'active_attempt_id' => null,
                ]);
                break;

            case 'gate_decided':
                $decision = (string) $event['decision'];
                if ($decision === 'retry_with_feedback') {
                    $state = array_merge($state, [
                        'status' => 'pending',
                        'step' => 'retry_requested',
                        'current_phase' => $phase,
                        'gate_phase' => null,
                        'gate_reason' => null,
                        'active_attempt_id' => null,
                        'retry_requested' => true,
                        'last_error' => null,
                    ]);
                } elseif ($decision === 'cancel') {
                    $state = array_merge($state, [
                        'status' => 'cancelled',
                        'step' => 'cancelled',
                        'current_phase' => $phase,
                        'cancel_requested' => false,
                    ]);
                } else {
                    $this->advance($state, $phase);
                }
                break;

            case 'cancel_requested':
                $state['cancel_requested'] = true;
                break;

            case 'automation_cancelled':
                $state = array_merge($state, [
                    'status' => 'cancelled',
                    'step' => 'cancelled',
                    'current_phase' => $phase,
                    'active_attempt_id' => null,
                    'gate_phase' => null,
                    'gate_reason' => null,
                    'cancel_requested' => false,
                ]);
                break;

            case 'automation_resumed':
                if (in_array($state['status'], ['cancelled', 'failed'], true)) {
                    $activeAttemptId = is_string($event['active_attempt_id'] ?? null)
                        ? $event['active_attempt_id']
                        : null;
                    $state = array_merge($state, [
                        'status' => $activeAttemptId !== null ? 'running' : 'pending',
                        'step' => $activeAttemptId !== null ? 'generating' : 'ready',
                        'active_attempt_id' => $activeAttemptId,
                        'cancel_requested' => false,
                        'last_error' => null,
                    ]);
                }
                break;

            case 'automation_failed':
                $state = array_merge($state, [
                    'status' => 'failed',
                    'step' => 'halted',
                    'active_attempt_id' => null,
                    'gate_phase' => null,
                    'gate_reason' => null,
                    'last_error' => $body,
                ]);
                break;

            case 'automation_completed':
                $state = array_merge($state, [
                    'status' => 'complete',
                    'step' => 'complete',
                    'current_phase' => $phase,
                    'active_attempt_id' => null,
                    'gate_phase' => null,
                    'gate_reason' => null,
                    'retry_requested' => false,
                    'last_error' => null,
                ]);
                break;

            case 'completed_checkpoint_reopened':
                $state = array_merge($state, [
                    'status' => 'pending',
                    'step' => 'repair',
                    'current_phase' => $phase,
                    'active_attempt_id' => null,
                    'gate_phase' => null,
                    'gate_reason' => is_string($event['reason'] ?? null) ? $event['reason'] : $body,
                    'retry_requested' => false,
                ]);
                break;
        }
    }

    /** @param array<string, mixed> $state */
    private function advance(array &$state, string $phase): void
    {
        $next = $this->phases->next($phase);
        if ($next === null) {
            $state = array_merge($state, [
                'status' => 'complete',
                'step' => 'complete',
                'current_phase' => $phase,
                'active_attempt_id' => null,
                'latest_evaluation_id' => null,
                'gate_phase' => null,
                'gate_reason' => null,
                'retry_requested' => false,
                'last_error' => null,
            ]);

            return;
        }

        $state = array_merge($state, [
            'status' => 'pending',
            'step' => 'ready',
            'current_phase' => $next,
            'active_attempt_id' => null,
            'latest_evaluation_id' => null,
            'gate_phase' => null,
            'gate_reason' => null,
            'retry_requested' => false,
            'last_error' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, array{phase: string, units: int, attempt_number: int, prior_evaluation_id: string|null, retry_decision_event_id: string|null}>  $reservations
     * @param  array<string, array<string, mixed>>  $eventsById
     * @return array<string, array{phase: string, request: array<string, mixed>|null, result: array<string, mixed>|null}>
     */
    private function attemptRecords(
        string $project,
        string $run,
        array $policy,
        array &$reservations,
        array $eventsById,
    ): array {
        $records = [];
        foreach (array_keys($this->phases->all()) as $phase) {
            foreach ($this->attempts->attempts($project, $run, $phase) as $attempt) {
                if ($attempt['parse_errors'] !== []) {
                    throw new RuntimeException('Automation projection cannot trust a malformed attempt record.');
                }
                $attemptId = $attempt['attempt_id'];
                $request = $attempt['request'];
                $belongs = is_array($request)
                    && ($request['automation_id'] ?? null) === ($policy['automation_id'] ?? null);
                if (! $belongs && ! isset($reservations[$attemptId])) {
                    continue;
                }
                if (! is_array($request)) {
                    throw new RuntimeException("Automatic attempt [{$attemptId}] has no readable request.");
                }
                $this->validator->attemptRequest($request, $policy, $phase, $attemptId);
                $retryDecisionEventId = is_string($request['retry_decision_event_id'] ?? null)
                    ? $request['retry_decision_event_id']
                    : null;
                if ($retryDecisionEventId !== null) {
                    $retryDecision = $eventsById[$retryDecisionEventId] ?? null;
                    if (! is_array($retryDecision)
                        || ($retryDecision['event'] ?? null) !== 'gate_decided'
                        || ($retryDecision['phase'] ?? null) !== $phase
                        || ($retryDecision['decision'] ?? null) !== 'retry_with_feedback'
                        || ($retryDecision['feedback_sha256'] ?? null) !== ($request['decision_feedback_sha256'] ?? null)) {
                        throw new RuntimeException("Automatic attempt [{$attemptId}] retry feedback is not linked to its exact gate decision.");
                    }
                }
                $result = $attempt['result'];
                if (is_array($result)) {
                    $this->validator->attemptResult($result, $phase, $attemptId);
                    if (($result['driver'] ?? null) !== ($request['driver'] ?? null)
                        || ($result['expected_output_count'] ?? null) !== ($request['expected_output_count'] ?? null)
                        || ($result['simulation_units'] ?? null) !== ($request['reserved_simulation_units'] ?? null)) {
                        throw new RuntimeException("Automatic attempt [{$attemptId}] result contradicts its request accounting.");
                    }
                }
                if (! isset($reservations[$attemptId])) {
                    $reservations[$attemptId] = [
                        'phase' => $phase,
                        'units' => (int) $request['reserved_simulation_units'],
                        'attempt_number' => (int) $request['attempt_number'],
                        'prior_evaluation_id' => is_string($request['prior_evaluation_id'] ?? null)
                            ? $request['prior_evaluation_id']
                            : null,
                        'retry_decision_event_id' => $retryDecisionEventId,
                    ];
                } elseif ($reservations[$attemptId]['phase'] !== $phase
                    || $reservations[$attemptId]['units'] !== (int) $request['reserved_simulation_units']
                    || $reservations[$attemptId]['attempt_number'] !== (int) $request['attempt_number']
                    || $reservations[$attemptId]['prior_evaluation_id'] !== ($request['prior_evaluation_id'] ?? null)
                    || $reservations[$attemptId]['retry_decision_event_id'] !== $retryDecisionEventId) {
                    throw new RuntimeException("Automatic attempt [{$attemptId}] reservation contradicts its request.");
                }
                $records[$attemptId] = [
                    'phase' => $phase,
                    'request' => $request,
                    'result' => $result,
                ];
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array{phase: string, units: int, attempt_number: int, prior_evaluation_id: string|null, retry_decision_event_id: string|null}>  $reservations
     * @param  array<string, array{phase: string, request: array<string, mixed>|null, result: array<string, mixed>|null}>  $attemptsById
     */
    private function applyAttemptAccounting(array &$state, array $reservations, array $attemptsById): void
    {
        $counts = [];
        $numbers = [];
        $units = 0;
        foreach ($reservations as $attemptId => $reservation) {
            $counts[$reservation['phase']] = ($counts[$reservation['phase']] ?? 0) + 1;
            $numbers[$reservation['phase']][] = $reservation['attempt_number'];
            $units += $reservation['units'];
            if (isset($attemptsById[$attemptId])
                && $attemptsById[$attemptId]['phase'] !== $reservation['phase']) {
                throw new RuntimeException("Automatic attempt [{$attemptId}] changed phase.");
            }
        }
        foreach ($numbers as $phase => $attemptNumbers) {
            sort($attemptNumbers);
            if ($attemptNumbers !== range(1, count($attemptNumbers))) {
                throw new RuntimeException("Automatic phase [{$phase}] attempt numbers are duplicated or incomplete.");
            }
        }
        $state['attempts_by_phase'] = $counts;
        $state['total_attempts'] = count($reservations);
        $state['simulation_units_spent'] = $units;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, array{phase: string, request: array<string, mixed>|null, result: array<string, mixed>|null}>  $attemptsById
     */
    private function validateAttemptOutcomeEvidence(array $events, array $attemptsById): void
    {
        foreach ($events as $event) {
            $attemptId = (string) $event['attempt_id'];
            $result = $attemptsById[$attemptId]['result'] ?? null;
            if (! is_array($result)) {
                throw new RuntimeException("Automation outcome event references attempt [{$attemptId}] without a terminal result.");
            }

            $eventName = (string) $event['event'];
            $status = $result['status'] ?? null;
            if ($eventName === 'attempt_committed' && $status !== 'committed') {
                throw new RuntimeException("Committed automation event contradicts attempt [{$attemptId}] result status.");
            }
            if ($eventName === 'attempt_failed' && $status !== 'failed') {
                throw new RuntimeException("Failed automation event contradicts attempt [{$attemptId}] result status.");
            }
            if ($eventName === 'attempt_interrupted' && ! in_array($status, ['failed', 'cancelled', 'interrupted'], true)) {
                throw new RuntimeException("Interrupted automation event contradicts attempt [{$attemptId}] result status.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array{phase: string, units: int, attempt_number: int, prior_evaluation_id: string|null, retry_decision_event_id: string|null}>  $reservations
     * @param  array<string, array{phase: string, request: array<string, mixed>|null, result: array<string, mixed>|null}>  $attemptsById
     */
    private function validateReservedAttemptEvidence(array $state, array $reservations, array $attemptsById): void
    {
        foreach ($reservations as $attemptId => $reservation) {
            $attempt = $attemptsById[$attemptId] ?? null;
            if (is_array($attempt)
                && is_array($attempt['request'])
                && is_array($attempt['result'])) {
                continue;
            }

            $activeGeneration = ($state['status'] ?? null) === 'running'
                && ($state['step'] ?? null) === 'generating'
                && ($state['active_attempt_id'] ?? null) === $attemptId;
            if ($activeGeneration) {
                continue;
            }

            throw new RuntimeException(
                "Reserved automatic attempt [{$attemptId}] in phase [{$reservation['phase']}] has no complete request/result evidence outside active generation.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, array{phase: string, request: array<string, mixed>|null, result: array<string, mixed>|null}>  $attemptsById
     * @return array<string, array<string, mixed>>
     */
    private function evaluationRecords(string $project, string $run, array $policy, array $attemptsById): array
    {
        $records = [];
        foreach (array_keys($this->phases->all()) as $phase) {
            $workspace = null;
            foreach ($this->stateStore->evaluations($project, $run, $phase) as $evaluation) {
                if (is_string($evaluation['parse_error'] ?? null)) {
                    throw new RuntimeException('Automation projection cannot trust a malformed evaluation.');
                }
                if (($evaluation['automation_id'] ?? null) !== ($policy['automation_id'] ?? null)) {
                    continue;
                }
                $this->validator->evaluation(
                    $evaluation,
                    $policy,
                    $phase,
                    is_string($evaluation['_filename'] ?? null) ? $evaluation['_filename'] : null,
                );
                $attemptId = (string) $evaluation['attempt_id'];
                $attempt = $attemptsById[$attemptId] ?? null;
                if (! is_array($attempt)
                    || ! is_array($attempt['result'])
                    || ($attempt['result']['status'] ?? null) !== 'committed') {
                    throw new RuntimeException("Automation evaluation references uncommitted attempt [{$attemptId}].");
                }
                $candidateIds = $evaluation['candidate_ids'];
                $resultIds = $attempt['result']['artifact_ids'];
                if (! is_array($candidateIds) || ! is_array($resultIds)) {
                    throw new RuntimeException('Automation evaluation candidate manifest is invalid.');
                }
                $left = $candidateIds;
                $right = $resultIds;
                sort($left);
                sort($right);
                if ($left !== $right) {
                    throw new RuntimeException('Automation evaluation candidates do not match the committed attempt.');
                }
                $request = $attempt['request'];
                if (! is_array($request)) {
                    throw new RuntimeException("Automation evaluation attempt [{$attemptId}] has no request evidence.");
                }
                $evaluationSources = $evaluation['source_artifact_ids'];
                $requestSources = $request['source_artifact_ids'];
                if (! is_array($evaluationSources) || ! is_array($requestSources)) {
                    throw new RuntimeException('Automation evaluation lineage source manifest is invalid.');
                }
                sort($evaluationSources);
                sort($requestSources);
                if ($evaluationSources !== $requestSources
                    || ($evaluation['required_slots'] ?? null) !== ($request['required_slots'] ?? null)
                    || ($evaluation['lineage_hash'] ?? null) !== ($request['lineage_hash'] ?? null)) {
                    throw new RuntimeException('Automation evaluation contradicts its generation request lineage or slots.');
                }
                $workspace ??= $this->workspace->automationPhaseState($project, $run, $phase);
                $artifacts = $workspace['artifacts'];
                $scoresById = [];
                foreach (is_array($evaluation['scores'] ?? null) ? $evaluation['scores'] : [] as $score) {
                    if (is_array($score) && is_string($score['artifact_id'] ?? null)) {
                        $scoresById[$score['artifact_id']] = $score;
                    }
                }
                foreach ($candidateIds as $candidateId) {
                    $artifact = collect($artifacts)->firstWhere('id', $candidateId);
                    $score = $scoresById[$candidateId] ?? null;
                    if (! is_array($artifact)
                        || ! is_array($score)
                        || ($artifact['metadata']['generation_attempt_id'] ?? null) !== $attemptId
                        || ($artifact['metadata']['candidate_order'] ?? null) !== ($score['candidate_order'] ?? null)) {
                        throw new RuntimeException('Automation evaluation candidate cannot be resolved to its exact attempt.');
                    }
                    if ((is_string($request['prior_evaluation_id'] ?? null)
                            || is_string($request['retry_decision_event_id'] ?? null))
                        && (($artifact['metadata']['retry_context_applied'] ?? null) !== true
                            || ($artifact['metadata']['retry_context_sha256'] ?? null)
                                !== ($request['generation_feedback_sha256'] ?? null))) {
                        throw new RuntimeException('Automatic retry candidate does not prove which generation feedback it applied.');
                    }
                }
                $records[(string) $evaluation['id']] = $evaluation;
            }
        }

        foreach ($records as $evaluation) {
            $priorEvaluationId = $evaluation['prior_evaluation_id'] ?? null;
            if ($priorEvaluationId !== null
                && (! is_string($priorEvaluationId)
                    || ! isset($records[$priorEvaluationId])
                    || ($records[$priorEvaluationId]['phase'] ?? null) !== ($evaluation['phase'] ?? null))) {
                throw new RuntimeException('Automation evaluation prior-evaluation lineage is missing or crosses phases.');
            }
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $selectionEvents
     * @param  list<array<string, mixed>>  $phaseCompletionEvents
     * @param  list<array<string, mixed>>  $gateEvents
     * @param  array<string, array<string, mixed>>  $evaluations
     * @param  array<string, mixed>  $policy
     */
    private function validateSelectionEvidence(
        string $project,
        string $run,
        array $selectionEvents,
        array $phaseCompletionEvents,
        array $gateEvents,
        array $evaluations,
        array $policy,
    ): void {
        $phaseArtifacts = [];
        $selectionRecords = [];
        $selectedByEvaluation = [];

        foreach ($gateEvents as $event) {
            if (($event['phase'] ?? null) === 'ideas') {
                $this->validateIdeasSourceEvidenceAgainstFiles($project, $run, $event);
                $supersededEvaluationId = $event['superseded_evaluation_id'] ?? null;
                if ($supersededEvaluationId !== null) {
                    $superseded = is_string($supersededEvaluationId)
                        ? ($evaluations[$supersededEvaluationId] ?? null)
                        : null;
                    if (! is_array($superseded) || ($superseded['phase'] ?? null) !== 'ideas') {
                        throw new RuntimeException('Ideas source-reuse evidence references a missing or cross-phase superseded evaluation.');
                    }
                }
            }
        }

        foreach ($selectionEvents as $event) {
            $phase = (string) $event['phase'];
            $slot = (string) $event['slot'];
            $artifactId = (string) $event['artifact_id'];
            $evaluationId = (string) $event['evaluation_id'];
            $evaluation = $evaluations[$evaluationId] ?? null;
            if (! is_array($evaluation)
                || ($evaluation['phase'] ?? null) !== $phase
                || ($evaluation['recommendations'][$slot] ?? null) !== $artifactId) {
                throw new RuntimeException('Automatic selection event is not authorized by its exact evaluation recommendation.');
            }

            $phaseArtifacts[$phase] ??= collect(
                $this->workspace->automationPhaseState($project, $run, $phase)['artifacts'],
            )->keyBy('id')->all();
            $artifact = $phaseArtifacts[$phase][$artifactId] ?? null;
            if (! is_array($artifact)
                || ($artifact['metadata']['generation_attempt_id'] ?? null) !== ($evaluation['attempt_id'] ?? null)) {
                throw new RuntimeException('Automatic selection event references an artifact outside its evaluated attempt.');
            }

            $selectionRecords[$phase] ??= $this->store->selectionRecords($project, $run, $phase);
            $hasImmutableSelection = collect($selectionRecords[$phase])->contains(
                fn (array $selection): bool => ($selection['slot'] ?? null) === $slot
                    && ($selection['artifact_id'] ?? null) === $artifactId
                    && ($selection['selected_by'] ?? null) === 'automation'
                    && ($selection['evaluation_id'] ?? null) === $evaluationId,
            );
            if (! $hasImmutableSelection) {
                throw new RuntimeException('Automatic selection event has no matching immutable selection record.');
            }
            $selectedByEvaluation[$evaluationId][$slot] = $artifactId;
        }

        foreach ($phaseCompletionEvents as $event) {
            $evaluationId = (string) $event['evaluation_id'];
            $evaluation = $evaluations[$evaluationId] ?? null;
            if (! is_array($evaluation) || ($evaluation['phase'] ?? null) !== ($event['phase'] ?? null)) {
                throw new RuntimeException('Phase-completed event has no matching phase evaluation.');
            }
            $completed = $this->sortedSelectionMap($event['selection_ids'] ?? []);
            $recommended = $this->sortedSelectionMap($evaluation['recommendations'] ?? []);
            $recorded = $this->sortedSelectionMap($selectedByEvaluation[$evaluationId] ?? []);
            if ($completed !== $recommended || $completed !== $recorded) {
                throw new RuntimeException('Phase-completed evidence does not match its exact recommendations and selection events.');
            }
        }

        $pendingGate = null;
        foreach ($gateEvents as $event) {
            $phase = (string) $event['phase'];
            if (($event['event'] ?? null) === 'phase_completed') {
                if (in_array($phase, $policy['human_gates'], true)) {
                    $pendingGate = $event;
                }

                continue;
            }
            if (($event['event'] ?? null) === 'phase_reused') {
                if (in_array($phase, $policy['human_gates'], true)) {
                    $pendingGate = $event;
                }

                continue;
            }
            if (($event['event'] ?? null) === 'gate_requested') {
                if (($event['gate_kind'] ?? null) === 'review') {
                    $evaluationId = is_string($event['evaluation_id'] ?? null) ? $event['evaluation_id'] : '';
                    $evaluation = $evaluations[$evaluationId] ?? null;
                    if (! is_array($evaluation)
                        || $this->sortedSelectionMap($event['selection_ids'] ?? [])
                            !== $this->sortedSelectionMap($evaluation['recommendations'] ?? [])) {
                        throw new RuntimeException('Review gate evidence does not match its structural recommendations.');
                    }
                }
                $pendingGate = $event;

                continue;
            }

            $request = $pendingGate;
            if (! is_array($request)) {
                throw new RuntimeException('Gate decision has no pending gate request.');
            }
            if (($request['id'] ?? null) !== ($event['gate_token'] ?? null)) {
                throw new RuntimeException('Gate decision does not reference the exact pending gate token.');
            }
            if (($request['phase'] ?? null) !== $phase) {
                throw new RuntimeException('Gate decision phase does not match the exact pending gate.');
            }
            if ($phase === 'ideas') {
                if (($request['ideas_source'] ?? null) !== ($event['ideas_source'] ?? null)) {
                    throw new RuntimeException('Ideas gate decision does not preserve the exact reviewed source decision evidence.');
                }
                if ($this->sortedSelectionMap($request['selection_ids'] ?? [])
                    !== $this->sortedSelectionMap($event['selection_ids'] ?? [])) {
                    throw new RuntimeException('Ideas gate decision does not preserve the exact reviewed effective source.');
                }
            }
            $requestEvent = $request['event'];
            if (($event['decision'] ?? null) === 'approve'
                && $requestEvent === 'phase_reused') {
                throw new RuntimeException('A human-action gate cannot be approved as a structural recommendation.');
            }
            if (($event['decision'] ?? null) === 'approve') {
                $requestEvaluationId = is_string($request['evaluation_id'] ?? null)
                    ? $request['evaluation_id']
                    : null;
                if (! in_array($requestEvent, ['phase_completed', 'gate_requested'], true)
                    || ($requestEvent === 'gate_requested'
                        && ($request['gate_kind'] ?? null) !== 'review')
                    || $requestEvaluationId === null
                    || ! isset($evaluations[$requestEvaluationId])
                    || ($event['evaluation_id'] ?? null) !== $requestEvaluationId) {
                    throw new RuntimeException('Approved gate decision is not linked to the exact review evaluation.');
                }
                if ($this->sortedSelectionMap($event['selection_ids'] ?? [])
                    !== $this->sortedSelectionMap($request['selection_ids'] ?? [])) {
                    throw new RuntimeException('Approved gate decision does not preserve the exact reviewed selections.');
                }
            }
            $pendingGate = null;
        }
    }

    /** @return array<string, string> */
    private function sortedSelectionMap(mixed $value): array
    {
        $map = is_array($value) ? array_filter($value, 'is_string') : [];
        ksort($map);

        return $map;
    }

    /** @param array<string, mixed> $event */
    private function validateIdeasSourceEvidenceAgainstFiles(
        string $project,
        string $run,
        array $event,
    ): void {
        $evidence = is_array($event['ideas_source'] ?? null) ? $event['ideas_source'] : [];
        if (($evidence['explicit'] ?? null) !== true) {
            return;
        }

        $decisionId = is_string($evidence['decision_id'] ?? null)
            ? $evidence['decision_id']
            : '';
        $decision = collect($this->store->ideaSourceDecisions($project, $run))->first(
            fn (array $candidate): bool => ($candidate['id'] ?? null) === $decisionId,
        );
        if (! is_array($decision)
            || is_string($decision['parse_error'] ?? null)
            || ($decision['type'] ?? null) !== 'ideas_source_decision'
            || ($decision['phase'] ?? null) !== 'ideas'
            || ($decision['sequence'] ?? null) !== ($evidence['decision_sequence'] ?? null)
            || ($decision['lineage_sequence'] ?? null) !== ($evidence['lineage_sequence'] ?? null)
            || ($decision['mode'] ?? null) !== ($evidence['mode'] ?? null)
            || ($decision['source_id'] ?? null) !== ($evidence['source_id'] ?? null)
            || ($decision['artifact_id'] ?? null) !== ($evidence['artifact_id'] ?? null)
            || ($decision['selection_id'] ?? null) !== ($evidence['selection_id'] ?? null)
            || ($decision['selected_by'] ?? null) !== ($evidence['selected_by'] ?? null)) {
            throw new RuntimeException('Ideas automation evidence does not match its exact immutable source-decision record.');
        }
        if (($evidence['mode'] ?? null) === 'project_idea') {
            if (($evidence['source_id'] ?? null) !== $project) {
                throw new RuntimeException('Ideas project-source evidence does not identify this exact project.');
            }

            return;
        }

        $selectionId = $evidence['selection_id'] ?? null;
        $selection = collect($this->store->selectionRecords($project, $run, 'ideas'))->first(
            fn (array $candidate): bool => ($candidate['id'] ?? null) === $selectionId,
        );
        if (! is_array($selection)
            || is_string($selection['parse_error'] ?? null)
            || ($selection['phase'] ?? null) !== 'ideas'
            || ($selection['slot'] ?? null) !== 'primary'
            || ($selection['artifact_id'] ?? null) !== ($evidence['source_id'] ?? null)
            || ($selection['selected_by'] ?? null) !== ($evidence['selected_by'] ?? null)) {
            throw new RuntimeException('Ideas automation evidence does not match its exact immutable selection record.');
        }
    }

    /**
     * Rebase projection before validating a gate so stale upstream truth cannot
     * strand or accidentally authorize a later human review.
     *
     * @param  array<string, mixed>  $policy
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $state
     * @param  list<string>  $issues
     */
    private function reconcileCurrentFilesystemTruth(
        string $project,
        string $run,
        array $policy,
        array $events,
        array &$state,
        array &$issues,
    ): void {
        if (in_array($state['status'] ?? null, ['cancelled', 'failed'], true)) {
            return;
        }

        if (($policy['start_phase'] ?? null) === 'ideas'
            && $this->ideasSourceAuthorityChanged($project, $run, $policy, $events, $state)) {
            $issues[] = 'Ideas source changed after durable automation review evidence; the old gate or approval was invalidated.';
            $this->repairState(
                $state,
                'ideas',
                'The effective Ideas source no longer matches the exact source evidence reviewed by automation.',
            );

            return;
        }

        $status = (string) ($state['status'] ?? '');
        $currentPhase = (string) ($state['current_phase'] ?? '');
        $includeCurrent = $status === 'complete'
            || ($status === 'awaiting_review' && ($state['step'] ?? null) === 'gate');
        $incomplete = $this->firstIncompletePhaseThrough(
            $project,
            $run,
            (string) $policy['start_phase'],
            $currentPhase,
            $includeCurrent,
        );
        if ($incomplete === null) {
            return;
        }

        $issues[] = $status === 'complete'
            ? "Completed checkpoint reopened because phase [{$incomplete}] is no longer complete."
            : "Automation checkpoint rebased because phase [{$incomplete}] is no longer complete before the current gate or phase.";
        $this->repairState(
            $state,
            $incomplete,
            'The projected checkpoint no longer matches current immutable selections and artifacts.',
        );
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $state
     */
    private function ideasSourceAuthorityChanged(
        string $project,
        string $run,
        array $policy,
        array $events,
        array $state,
    ): bool {
        $currentPhase = (string) ($state['current_phase'] ?? '');
        $activelyReworkingIdeas = $currentPhase === 'ideas'
            && in_array($state['step'] ?? null, ['ready', 'generating', 'evaluating', 'selecting', 'retry_requested'], true);
        if ($activelyReworkingIdeas) {
            return false;
        }

        $pendingGate = null;
        $authority = null;
        foreach ($events as $event) {
            if (($event['phase'] ?? null) !== 'ideas') {
                continue;
            }
            $name = $event['event'] ?? null;
            if ($name === 'phase_completed') {
                if (in_array('ideas', $policy['human_gates'], true)) {
                    $pendingGate = $event;
                } else {
                    $authority = $event;
                }
            } elseif ($name === 'phase_reused') {
                if (in_array('ideas', $policy['human_gates'], true)) {
                    $pendingGate = $event;
                } else {
                    $authority = $event;
                }
            } elseif ($name === 'gate_requested') {
                $pendingGate = $event;
            } elseif ($name === 'gate_decided') {
                if (is_array($pendingGate)
                    && ($pendingGate['id'] ?? null) === ($event['gate_token'] ?? null)) {
                    $pendingGate = null;
                }
                if (in_array($event['decision'] ?? null, ['approve', 'manual_override'], true)) {
                    $authority = $event;
                }
            }
        }

        $expected = is_array($pendingGate)
            ? ($pendingGate['ideas_source'] ?? null)
            : ($authority['ideas_source'] ?? null);
        if (! is_array($expected)) {
            return false;
        }

        return $expected !== $this->currentIdeasSourceEvidence($project, $run);
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
    private function currentIdeasSourceEvidence(string $project, string $run): array
    {
        $source = $this->workspace->effectiveIdeaSource($project, $run);
        $decision = is_array($source['decision'] ?? null) ? $source['decision'] : null;

        return [
            'mode' => $source['mode'],
            'source_id' => $source['source_id'],
            'explicit' => $source['explicit'],
            'skipped' => $source['skipped'],
            'decision_id' => is_string($decision['id'] ?? null) ? $decision['id'] : null,
            'decision_sequence' => is_int($decision['sequence'] ?? null) ? $decision['sequence'] : null,
            'lineage_sequence' => is_int($decision['lineage_sequence'] ?? null) ? $decision['lineage_sequence'] : null,
            'artifact_id' => is_string($decision['artifact_id'] ?? null) ? $decision['artifact_id'] : null,
            'selection_id' => is_string($decision['selection_id'] ?? null) ? $decision['selection_id'] : null,
            'selected_by' => is_string($decision['selected_by'] ?? null) ? $decision['selected_by'] : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function repairState(array &$state, string $phase, string $reason): void
    {
        $state = array_merge($state, [
            'status' => 'pending',
            'step' => 'repair',
            'current_phase' => $phase,
            'active_attempt_id' => null,
            'latest_evaluation_id' => null,
            'gate_phase' => null,
            'gate_reason' => $reason,
            'retry_requested' => false,
            'cancel_requested' => false,
            'last_error' => null,
        ]);
    }

    private function firstIncompletePhaseThrough(
        string $project,
        string $run,
        string $startPhase,
        string $currentPhase,
        bool $includeCurrent,
    ): ?string {
        $started = false;
        foreach (array_keys($this->phases->all()) as $phase) {
            $started = $started || $phase === $startPhase;
            if (! $started) {
                continue;
            }
            if ($phase === $currentPhase && ! $includeCurrent) {
                return null;
            }
            $workspace = $this->workspace->automationPhaseState($project, $run, $phase);
            if (! $workspace['complete']) {
                return $phase;
            }
            if ($phase === $currentPhase) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array{phase: string, request: array<string, mixed>|null, result: array<string, mixed>|null}>  $attemptsById
     * @param  array<string, array<string, mixed>>  $evaluations
     */
    private function recoverDurableSubstep(array &$state, array $attemptsById, array $evaluations): void
    {
        if (! in_array($state['status'] ?? null, ['pending', 'running'], true)
            || ! in_array($state['step'] ?? null, ['generating', 'evaluating'], true)) {
            return;
        }

        $attemptId = is_string($state['active_attempt_id'] ?? null) ? $state['active_attempt_id'] : '';
        if ($attemptId === '') {
            return;
        }
        $attempt = $attemptsById[$attemptId] ?? null;
        if (! is_array($attempt)) {
            return;
        }
        $result = $attempt['result'];
        if (! is_array($result)) {
            $state['status'] = 'running';
            $state['step'] = 'generating';

            return;
        }
        if (($result['status'] ?? null) !== 'committed') {
            $state['status'] = 'pending';
            $state['step'] = 'ready';
            $state['active_attempt_id'] = null;

            return;
        }

        $matching = array_values(array_filter(
            $evaluations,
            fn (array $evaluation): bool => ($evaluation['attempt_id'] ?? null) === $attemptId,
        ));
        if ($matching === []) {
            $state['status'] = 'running';
            $state['step'] = 'evaluating';

            return;
        }
        usort($matching, fn (array $left, array $right): int => strcmp((string) $left['created_at'], (string) $right['created_at']));
        $evaluation = $matching[array_key_last($matching)];
        $state['latest_evaluation_id'] = $evaluation['id'];
        $state['status'] = ($evaluation['passed'] ?? false) ? 'running' : 'pending';
        $state['step'] = ($evaluation['passed'] ?? false) ? 'selecting' : 'ready';
        if (! ($evaluation['passed'] ?? false)) {
            $state['active_attempt_id'] = null;
            $state['last_error'] = $evaluation['diagnosis'];
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $evaluations
     */
    private function validatePendingGate(string $project, string $run, array $state, array $evaluations): void
    {
        if (($state['status'] ?? null) !== 'awaiting_review') {
            return;
        }
        $phase = (string) $state['current_phase'];
        $phaseWorkspace = $this->workspace->automationPhaseState($project, $run, $phase);
        if (($state['step'] ?? null) === 'gate') {
            if (! $phaseWorkspace['complete']) {
                throw new RuntimeException('A review gate cannot be projected from an incomplete phase.');
            }
            $evaluationId = is_string($state['latest_evaluation_id'] ?? null)
                ? $state['latest_evaluation_id']
                : '';
            if ($evaluationId === '' || ! isset($evaluations[$evaluationId])) {
                throw new RuntimeException('A review gate has no valid latest evaluation.');
            }
        }
    }
}
