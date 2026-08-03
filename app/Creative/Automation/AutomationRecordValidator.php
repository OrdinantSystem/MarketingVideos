<?php

namespace App\Creative\Automation;

use App\Creative\CreativePhaseCatalog;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class AutomationRecordValidator
{
    /** @var list<string> */
    private const STATUSES = ['pending', 'running', 'awaiting_review', 'failed', 'cancelled', 'complete'];

    /** @var list<string> */
    private const STEPS = ['ready', 'generating', 'evaluating', 'selecting', 'gate', 'human_action', 'retry_requested', 'repair', 'halted', 'cancelled', 'complete'];

    /** @var list<string> */
    private const EVENTS = [
        'automation_started',
        'automation_bootstrap_recovered',
        'checkpoint_reconciled',
        'completed_checkpoint_reopened',
        'phase_reused',
        'attempt_started',
        'attempt_committed',
        'attempt_failed',
        'attempt_interrupted',
        'evaluation_recorded',
        'retry_scheduled',
        'selection_recorded',
        'phase_completed',
        'gate_requested',
        'gate_decided',
        'cancel_requested',
        'automation_cancelled',
        'automation_resumed',
        'automation_failed',
        'automation_completed',
    ];

    public function __construct(private readonly CreativePhaseCatalog $phases) {}

    /** @param array<string, mixed> $policy */
    public function policy(array $policy): void
    {
        $this->sameIdentity($policy, 'automation_policy', 'Automation policy');
        if (($policy['schema_version'] ?? null) !== 1
            || ($policy['mode'] ?? null) !== 'automatic'
            || ($policy['driver'] ?? null) !== 'simulation') {
            throw new RuntimeException('Automation policy has an unsupported schema, mode, or driver.');
        }

        $startPhase = $this->requiredString($policy, 'start_phase', 'Automation policy');
        $this->phases->assertValid($startPhase);
        foreach (['max_attempts_per_phase', 'max_total_attempts', 'max_simulation_units'] as $field) {
            if (! is_int($policy[$field] ?? null) || $policy[$field] < 1) {
                throw new RuntimeException("Automation policy {$field} must be a positive integer.");
            }
        }
        if (! is_numeric($policy['max_cost_usd'] ?? null) || (float) $policy['max_cost_usd'] !== 0.0) {
            throw new RuntimeException('Automation policy must keep max_cost_usd at zero.');
        }
        if (! is_int($policy['score_threshold'] ?? null)
            || $policy['score_threshold'] < 0
            || $policy['score_threshold'] > 100) {
            throw new RuntimeException('Automation policy score_threshold must be between 0 and 100.');
        }
        foreach ($this->stringList($policy['human_gates'] ?? null, 'Automation policy human_gates') as $gate) {
            $this->phases->assertValid($gate);
        }
        $this->timestamp($policy['created_at'] ?? null, 'Automation policy created_at');
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $policy
     */
    public function state(array $state, array $policy): void
    {
        $this->sameIdentity($state, 'automation_state', 'Automation state');
        if (($state['schema_version'] ?? null) !== 1
            || ($state['automation_id'] ?? null) !== ($policy['automation_id'] ?? null)) {
            throw new RuntimeException('Automation state schema or identity does not match its policy.');
        }
        if (! in_array($state['status'] ?? null, self::STATUSES, true)
            || ! in_array($state['step'] ?? null, self::STEPS, true)) {
            throw new RuntimeException('Automation state has an unsupported status or step.');
        }
        $phase = $this->requiredString($state, 'current_phase', 'Automation state');
        $this->phases->assertValid($phase);
        $attempts = $state['attempts_by_phase'] ?? null;
        if (! is_array($attempts) || ($attempts !== [] && array_is_list($attempts))) {
            throw new RuntimeException('Automation state attempts_by_phase must be a phase map.');
        }
        $attemptTotal = 0;
        foreach ($attempts as $attemptPhase => $count) {
            if (! is_string($attemptPhase) || ! is_int($count) || $count < 0) {
                throw new RuntimeException('Automation state contains an invalid attempt counter.');
            }
            $this->phases->assertValid($attemptPhase);
            if ($count > (int) $policy['max_attempts_per_phase']) {
                throw new RuntimeException('Automation state exceeds its per-phase attempt limit.');
            }
            $attemptTotal += $count;
        }
        if (! is_int($state['total_attempts'] ?? null)
            || $state['total_attempts'] < 0
            || $state['total_attempts'] !== $attemptTotal) {
            throw new RuntimeException('Automation state total_attempts does not equal its phase counters.');
        }
        if ($state['total_attempts'] > (int) $policy['max_total_attempts']) {
            throw new RuntimeException('Automation state exceeds its total attempt limit.');
        }
        if (! is_int($state['simulation_units_spent'] ?? null) || $state['simulation_units_spent'] < 0) {
            throw new RuntimeException('Automation state simulation_units_spent must be a non-negative integer.');
        }
        if ($state['simulation_units_spent'] > (int) $policy['max_simulation_units']) {
            throw new RuntimeException('Automation state exceeds its simulation-unit budget.');
        }
        if (! is_bool($state['cancel_requested'] ?? null) || ! is_bool($state['retry_requested'] ?? null)) {
            throw new RuntimeException('Automation state cancellation and retry flags must be booleans.');
        }
        foreach (['active_attempt_id', 'latest_evaluation_id'] as $field) {
            $value = $state[$field] ?? null;
            if ($value !== null && (! is_string($value) || ! $this->safeIdentifier($value))) {
                throw new RuntimeException("Automation state {$field} must be a safe identifier or null.");
            }
        }
        $gatePhase = $state['gate_phase'] ?? null;
        if ($gatePhase !== null) {
            if (! is_string($gatePhase)) {
                throw new RuntimeException('Automation state gate_phase must be a phase or null.');
            }
            $this->phases->assertValid($gatePhase);
        }
        $status = (string) $state['status'];
        $step = (string) $state['step'];
        $allowedSteps = match ($status) {
            'pending' => ['ready', 'retry_requested', 'repair'],
            'running' => ['generating', 'evaluating', 'selecting'],
            'awaiting_review' => ['gate', 'human_action'],
            'failed' => ['halted'],
            'cancelled' => ['cancelled'],
            default => ['complete'],
        };
        if (! in_array($step, $allowedSteps, true)) {
            throw new RuntimeException('Automation state status and step contradict each other.');
        }
        if ($status === 'awaiting_review') {
            if ($gatePhase !== $phase
                || ! is_string($state['gate_reason'] ?? null)
                || trim($state['gate_reason']) === '') {
                throw new RuntimeException('Automation review state is missing its exact gate phase or reason.');
            }
        } elseif ($gatePhase !== null) {
            throw new RuntimeException('Automation state has a gate phase while it is not awaiting review.');
        }
        $activeAttemptId = $state['active_attempt_id'] ?? null;
        if ($status === 'running' && ! is_string($activeAttemptId)) {
            throw new RuntimeException('Running automation state requires an active attempt ID.');
        }
        if (! in_array($status, ['running'], true) && $activeAttemptId !== null) {
            throw new RuntimeException('Inactive automation state cannot retain an active attempt ID.');
        }
        $this->timestamp($state['created_at'] ?? null, 'Automation state created_at');
        $this->timestamp($state['updated_at'] ?? null, 'Automation state updated_at');
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $policy
     */
    public function event(array $event, array $policy, int $expectedSequence, ?string $expectedFilename = null): void
    {
        if (($event['schema_version'] ?? null) !== 1
            || ($event['type'] ?? null) !== 'automation_event'
            || ($event['automation_id'] ?? null) !== ($policy['automation_id'] ?? null)
            || ($event['sequence'] ?? null) !== $expectedSequence) {
            throw new RuntimeException("Automation event {$expectedSequence} has an invalid schema, identity, or sequence.");
        }
        $id = $this->requiredString($event, 'id', 'Automation event');
        if (! $this->safeIdentifier($id)
            || ($expectedFilename !== null && $expectedFilename !== $id.'.md')) {
            throw new RuntimeException("Automation event {$expectedSequence} ID does not match its immutable filename.");
        }
        $name = $this->requiredString($event, 'event', 'Automation event');
        if (! in_array($name, self::EVENTS, true)) {
            throw new RuntimeException("Automation event [{$name}] is unsupported.");
        }
        $this->timestamp($event['created_at'] ?? null, 'Automation event created_at');

        if (in_array($name, [
            'phase_reused', 'attempt_started', 'attempt_committed', 'attempt_failed', 'attempt_interrupted',
            'evaluation_recorded', 'retry_scheduled', 'selection_recorded', 'phase_completed', 'gate_requested',
            'gate_decided', 'automation_cancelled',
            'automation_completed', 'completed_checkpoint_reopened',
        ], true)) {
            $phase = $this->requiredString($event, 'phase', 'Automation event');
            $this->phases->assertValid($phase);
        }
        if (in_array($name, ['attempt_started', 'attempt_committed', 'attempt_failed', 'attempt_interrupted'], true)) {
            $attemptId = $this->requiredString($event, 'attempt_id', 'Automation event');
            if (! $this->safeIdentifier($attemptId)) {
                throw new RuntimeException('Automation event attempt_id is unsafe.');
            }
        }
        if (in_array($name, ['automation_cancelled', 'automation_resumed'], true)) {
            $activeAttemptId = $event['active_attempt_id'] ?? null;
            if ($activeAttemptId !== null
                && (! is_string($activeAttemptId) || ! $this->safeIdentifier($activeAttemptId))) {
                throw new RuntimeException('Automation cancellation/resume event active attempt ID is unsafe.');
            }
        }
        if ($name === 'attempt_started') {
            if (! is_int($event['reserved_simulation_units'] ?? null)
                || $event['reserved_simulation_units'] < 0
                || ! is_int($event['attempt_number'] ?? null)
                || $event['attempt_number'] < 1) {
                throw new RuntimeException('Attempt-start event has an invalid simulation-unit reservation.');
            }
            $retryDecisionEventId = $event['retry_decision_event_id'] ?? null;
            if ($retryDecisionEventId !== null
                && (! is_string($retryDecisionEventId) || ! $this->safeIdentifier($retryDecisionEventId))) {
                throw new RuntimeException('Attempt-start event retry decision ID is unsafe.');
            }
            $priorEvaluationId = $event['prior_evaluation_id'] ?? null;
            if ($priorEvaluationId !== null
                && (! is_string($priorEvaluationId) || ! $this->safeIdentifier($priorEvaluationId))) {
                throw new RuntimeException('Attempt-start event prior evaluation ID is unsafe.');
            }
        }
        if (in_array($name, ['evaluation_recorded', 'retry_scheduled', 'selection_recorded', 'phase_completed'], true)
            && isset($event['evaluation_id'])
            && (! is_string($event['evaluation_id']) || ! $this->safeIdentifier($event['evaluation_id']))) {
            throw new RuntimeException('Automation event evaluation_id is unsafe.');
        }
        if ($name === 'gate_decided') {
            $decision = $event['decision'] ?? null;
            if (! in_array($decision, ['approve', 'manual_override', 'retry_with_feedback', 'cancel'], true)
                || ! is_array($event['selection_ids'] ?? null)) {
                throw new RuntimeException('Gate decision event is missing its explicit decision or exact selections.');
            }
            $gateToken = $this->requiredString($event, 'gate_token', 'Gate decision event');
            if (! $this->safeIdentifier($gateToken)) {
                throw new RuntimeException('Gate decision event has an unsafe gate token.');
            }
            $this->selectionMap($event['selection_ids'], 'Gate decision event selection_ids');

            $evaluationId = $event['evaluation_id'] ?? null;
            if ($evaluationId !== null
                && (! is_string($evaluationId) || ! $this->safeIdentifier($evaluationId))) {
                throw new RuntimeException('Gate decision event evaluation_id is unsafe.');
            }
            if ($decision === 'approve' && ! is_string($evaluationId)) {
                throw new RuntimeException('Approve gate decision requires exact evaluation evidence.');
            }

            $feedbackHash = $event['feedback_sha256'] ?? null;
            if ($decision === 'retry_with_feedback') {
                if (! is_string($feedbackHash) || preg_match('/^[a-f0-9]{64}$/', $feedbackHash) !== 1) {
                    throw new RuntimeException('Retry gate decision requires a valid feedback hash.');
                }
            } elseif ($feedbackHash !== null) {
                throw new RuntimeException('Only a retry gate decision may contain a feedback hash.');
            }
        }
        if ($name === 'selection_recorded') {
            foreach (['slot', 'artifact_id', 'evaluation_id'] as $field) {
                $value = $this->requiredString($event, $field, 'Selection-recorded event');
                if (! $this->safeIdentifier($value)) {
                    throw new RuntimeException("Selection-recorded event {$field} is unsafe.");
                }
            }
        }
        if ($name === 'phase_reused' && isset($event['superseded_evaluation_id'])) {
            if (! is_string($event['superseded_evaluation_id'])
                || ! $this->safeIdentifier($event['superseded_evaluation_id'])) {
                throw new RuntimeException('Phase-reused event superseded evaluation ID is unsafe.');
            }
        }
        if ($name === 'phase_completed') {
            $this->selectionMap($event['selection_ids'] ?? null, 'Phase-completed event selection_ids', true);
            $evaluationId = $this->requiredString($event, 'evaluation_id', 'Phase-completed event');
            if (! $this->safeIdentifier($evaluationId)) {
                throw new RuntimeException('Phase-completed event evaluation_id is unsafe.');
            }
        }
        if ($name === 'gate_requested') {
            if (! in_array($event['gate_kind'] ?? null, ['review', 'human_action'], true)) {
                throw new RuntimeException('Gate-requested event has an invalid gate kind.');
            }
            $this->selectionMap($event['selection_ids'] ?? null, 'Gate-requested event selection_ids');
            $evaluationId = $event['evaluation_id'] ?? null;
            if ($event['gate_kind'] === 'review') {
                if (! is_string($evaluationId) || ! $this->safeIdentifier($evaluationId)) {
                    throw new RuntimeException('Review gate request requires exact evaluation evidence.');
                }
            } elseif ($evaluationId !== null) {
                throw new RuntimeException('Human-action gate request cannot claim structural evaluation evidence.');
            }
        }

        $phase = is_string($event['phase'] ?? null) ? $event['phase'] : null;
        if ($phase === 'ideas'
            && in_array($name, ['phase_completed', 'phase_reused', 'gate_requested', 'gate_decided'], true)) {
            $allowImplicit = ($name === 'gate_requested' && $event['gate_kind'] === 'human_action')
                || ($name === 'gate_decided'
                    && in_array($event['decision'] ?? null, ['retry_with_feedback', 'cancel'], true));
            $ideasSource = $this->ideasSourceEvidence(
                $event['ideas_source'] ?? null,
                "{$name} Ideas source evidence",
                ! $allowImplicit,
            );
            $selectionIds = $this->selectionMap(
                $event['selection_ids'] ?? null,
                "{$name} Ideas selection_ids",
                ! $allowImplicit || $ideasSource['explicit'],
            );
            $expectedSelections = $ideasSource['explicit']
                ? ['primary' => $ideasSource['source_id']]
                : [];
            if ($selectionIds !== $expectedSelections) {
                throw new RuntimeException("{$name} Ideas selections do not match its exact effective source evidence.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  array<string, mixed>  $policy
     */
    public function evaluation(array $evaluation, array $policy, string $phase, ?string $expectedFilename = null): void
    {
        if (($evaluation['schema_version'] ?? null) !== 1
            || ($evaluation['type'] ?? null) !== 'artifact_evaluation'
            || ($evaluation['automation_id'] ?? null) !== ($policy['automation_id'] ?? null)
            || ($evaluation['phase'] ?? null) !== $phase
            || ($evaluation['simulation'] ?? null) !== true) {
            throw new RuntimeException('Automation evaluation has an invalid schema, identity, phase, or driver boundary.');
        }
        $id = $this->requiredString($evaluation, 'id', 'Automation evaluation');
        if (! $this->safeIdentifier($id)
            || ($expectedFilename !== null && $expectedFilename !== $id.'.md')) {
            throw new RuntimeException('Automation evaluation ID does not match its immutable filename.');
        }
        foreach (['attempt_id', 'rubric_version', 'rubric_name', 'diagnosis', 'lineage_hash'] as $field) {
            $this->requiredString($evaluation, $field, 'Automation evaluation');
        }
        if (! $this->safeIdentifier((string) $evaluation['attempt_id'])
            || preg_match('/^[a-f0-9]{64}$/', (string) $evaluation['lineage_hash']) !== 1) {
            throw new RuntimeException('Automation evaluation attempt or lineage identity is invalid.');
        }
        if (! is_bool($evaluation['passed'] ?? null)
            || ! is_int($evaluation['threshold'] ?? null)
            || $evaluation['threshold'] < 0
            || $evaluation['threshold'] > 100) {
            throw new RuntimeException('Automation evaluation pass state or threshold is invalid.');
        }
        $candidateIds = $this->stringList($evaluation['candidate_ids'] ?? null, 'Automation evaluation candidate_ids', true);
        $requiredSlots = $this->stringList($evaluation['required_slots'] ?? null, 'Automation evaluation required_slots', true);
        $this->stringList($evaluation['source_artifact_ids'] ?? null, 'Automation evaluation source_artifact_ids');
        $this->stringList($evaluation['fatal_issues'] ?? null, 'Automation evaluation fatal_issues');
        if (count($candidateIds) !== count(array_unique($candidateIds))
            || count($requiredSlots) !== count(array_unique($requiredSlots))) {
            throw new RuntimeException('Automation evaluation candidates and required slots must be unique.');
        }
        $recommendations = $evaluation['recommendations'] ?? null;
        if (! is_array($recommendations) || ($recommendations !== [] && array_is_list($recommendations))) {
            throw new RuntimeException('Automation evaluation recommendations must be a slot map.');
        }
        foreach ($recommendations as $slot => $artifactId) {
            if (! is_string($slot)
                || ! in_array($slot, $requiredSlots, true)
                || ! is_string($artifactId)
                || ! in_array($artifactId, $candidateIds, true)) {
                throw new RuntimeException('Automation evaluation contains an invalid slot recommendation.');
            }
        }
        if ($evaluation['passed'] === true
            && array_keys($recommendations) !== $requiredSlots) {
            throw new RuntimeException('A passed automation evaluation must recommend every required slot in order.');
        }
        $scores = $evaluation['scores'] ?? null;
        if (! is_array($scores) || ! array_is_list($scores) || count($scores) !== count($candidateIds)) {
            throw new RuntimeException('Automation evaluation must have one score record per candidate.');
        }
        $scoredIds = [];
        $candidateOrders = [];
        foreach ($scores as $score) {
            if (! is_array($score)
                || ! is_string($score['artifact_id'] ?? null)
                || ! in_array($score['artifact_id'], $candidateIds, true)
                || ! is_string($score['slot'] ?? null)
                || ! is_int($score['candidate_order'] ?? null)
                || $score['candidate_order'] < 1
                || ! is_int($score['score'] ?? null)
                || $score['score'] < 0
                || $score['score'] > 100
                || ! is_bool($score['passed'] ?? null)
                || ! is_array($score['reasons'] ?? null)
                || ! is_array($score['dimensions'] ?? null)) {
                throw new RuntimeException('Automation evaluation contains an invalid structural score record.');
            }
            $dimensionScore = 0;
            foreach ($score['dimensions'] as $dimension => $detail) {
                if (! is_string($dimension)
                    || $dimension === ''
                    || ! is_array($detail)
                    || ! is_bool($detail['met'] ?? null)
                    || ! is_int($detail['points'] ?? null)
                    || ! is_int($detail['max_points'] ?? null)
                    || $detail['points'] < 0
                    || $detail['max_points'] < 0
                    || $detail['points'] > $detail['max_points']
                    || ($detail['met'] === false && $detail['points'] !== 0)) {
                    throw new RuntimeException('Automation evaluation contains an invalid rubric dimension.');
                }
                $dimensionScore += $detail['points'];
            }
            if (min(100, $dimensionScore) !== $score['score']) {
                throw new RuntimeException('Automation evaluation score does not equal its structural dimensions.');
            }
            $scoredIds[] = $score['artifact_id'];
            $candidateOrders[] = $score['candidate_order'];
        }
        sort($scoredIds);
        $expectedIds = $candidateIds;
        sort($expectedIds);
        if ($scoredIds !== $expectedIds) {
            throw new RuntimeException('Automation evaluation scores do not cover the exact candidate set.');
        }
        sort($candidateOrders);
        if ($candidateOrders !== range(1, count($candidateIds))) {
            throw new RuntimeException('Automation evaluation candidate order is incomplete or duplicated.');
        }
        $this->timestamp($evaluation['created_at'] ?? null, 'Automation evaluation created_at');
        $priorEvaluationId = $evaluation['prior_evaluation_id'] ?? null;
        if ($priorEvaluationId !== null
            && (! is_string($priorEvaluationId) || ! $this->safeIdentifier($priorEvaluationId))) {
            throw new RuntimeException('Automation evaluation prior_evaluation_id is unsafe.');
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $policy
     */
    public function attemptRequest(array $request, array $policy, string $phase, string $attemptId): void
    {
        if (($request['schema_version'] ?? null) !== 1
            || ($request['type'] ?? null) !== 'generation_attempt_request'
            || ($request['id'] ?? null) !== $attemptId
            || ($request['attempt_id'] ?? null) !== $attemptId
            || ($request['phase'] ?? null) !== $phase
            || ($request['automation_id'] ?? null) !== ($policy['automation_id'] ?? null)
            || ($request['driver'] ?? null) !== 'simulation') {
            throw new RuntimeException("Automatic attempt [{$attemptId}] request identity is invalid.");
        }
        $this->stringList($request['source_artifact_ids'] ?? null, 'Attempt source_artifact_ids', true);
        $this->stringList($request['required_slots'] ?? null, 'Attempt required_slots', true);
        if (! is_int($request['expected_output_count'] ?? null) || $request['expected_output_count'] < 1
            || ! is_int($request['reserved_simulation_units'] ?? null) || $request['reserved_simulation_units'] < 0
            || ! is_int($request['attempt_number'] ?? null) || $request['attempt_number'] < 1
            || ! is_numeric($request['cost_limit_usd'] ?? null) || (float) $request['cost_limit_usd'] !== 0.0) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] request limits are invalid.");
        }
        if (! is_string($request['lineage_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $request['lineage_hash']) !== 1) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] lineage hash is invalid.");
        }
        foreach ([
            'prior_evaluation_id',
            'prior_evaluation_diagnosis',
            'retry_context_hash',
            'retry_decision_event_id',
            'decision_feedback_sha256',
        ] as $field) {
            $value = $request[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new RuntimeException("Automatic attempt [{$attemptId}] {$field} must be a string or null.");
            }
        }
        if (is_string($request['prior_evaluation_id'] ?? null)
            && ! $this->safeIdentifier($request['prior_evaluation_id'])) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] prior evaluation ID is unsafe.");
        }
        if (is_string($request['retry_context_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $request['retry_context_hash']) !== 1) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] retry context hash is invalid.");
        }
        if (! is_string($request['generation_feedback_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $request['generation_feedback_sha256']) !== 1) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] generation feedback hash is invalid.");
        }
        $retryDecisionEventId = $request['retry_decision_event_id'] ?? null;
        $decisionFeedbackHash = $request['decision_feedback_sha256'] ?? null;
        if (($retryDecisionEventId === null) !== ($decisionFeedbackHash === null)
            || (is_string($retryDecisionEventId) && ! $this->safeIdentifier($retryDecisionEventId))
            || (is_string($decisionFeedbackHash) && preg_match('/^[a-f0-9]{64}$/', $decisionFeedbackHash) !== 1)) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] retry-decision linkage is invalid.");
        }
        if (is_string($request['prior_evaluation_id'] ?? null)
            && ! is_string($request['retry_context_hash'] ?? null)) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] is missing its prior-evaluation retry context hash.");
        }
        $this->timestamp($request['started_at'] ?? null, 'Attempt started_at');
    }

    /** @param array<string, mixed> $result */
    public function attemptResult(array $result, string $phase, string $attemptId): void
    {
        if (($result['schema_version'] ?? null) !== 1
            || ($result['type'] ?? null) !== 'generation_attempt_result'
            || ($result['id'] ?? null) !== $attemptId.'--result'
            || ($result['attempt_id'] ?? null) !== $attemptId
            || ($result['phase'] ?? null) !== $phase
            || ($result['driver'] ?? null) !== 'simulation'
            || ! in_array($result['status'] ?? null, ['committed', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] result identity or status is invalid.");
        }
        $artifactIds = $this->stringList($result['artifact_ids'] ?? null, 'Attempt artifact_ids');
        if (! is_int($result['output_count'] ?? null)
            || $result['output_count'] !== count($artifactIds)
            || ! is_int($result['expected_output_count'] ?? null)
            || $result['expected_output_count'] < 1
            || ! is_int($result['simulation_units'] ?? null)
            || $result['simulation_units'] < 0
            || ! is_numeric($result['cost_usd'] ?? null)
            || (float) $result['cost_usd'] !== 0.0) {
            throw new RuntimeException("Automatic attempt [{$attemptId}] result accounting is invalid.");
        }
        if ($result['status'] === 'committed') {
            if ($result['output_count'] !== $result['expected_output_count']) {
                throw new RuntimeException("Committed automatic attempt [{$attemptId}] is incomplete.");
            }
            $hashes = $result['artifact_sha256'] ?? null;
            if (! is_array($hashes) || array_is_list($hashes) || array_keys($hashes) !== $artifactIds) {
                throw new RuntimeException("Committed automatic attempt [{$attemptId}] artifact hashes are invalid.");
            }
            foreach ($hashes as $artifactId => $hash) {
                if (! is_string($artifactId)
                    || ! is_string($hash)
                    || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                    throw new RuntimeException("Committed automatic attempt [{$attemptId}] has an invalid artifact hash.");
                }
            }
        }
        $this->timestamp($result['finished_at'] ?? null, 'Attempt finished_at');
    }

    /** @param array<string, mixed> $record */
    private function sameIdentity(array $record, string $type, string $label): void
    {
        $id = $this->requiredString($record, 'id', $label);
        if (($record['type'] ?? null) !== $type
            || ($record['automation_id'] ?? null) !== $id
            || ! $this->safeIdentifier($id)) {
            throw new RuntimeException("{$label} has an invalid type or identity.");
        }
    }

    /** @param array<string, mixed> $record */
    private function requiredString(array $record, string $field, string $label): string
    {
        $value = $record[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$label} {$field} must be a non-empty string.");
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $label, bool $nonEmpty = false): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$label} must be a list of strings.");
        }
        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new RuntimeException("{$label} contains an invalid string.");
            }
            $strings[] = $item;
        }
        if ($nonEmpty && $strings === []) {
            throw new RuntimeException("{$label} cannot be empty.");
        }

        return $strings;
    }

    private function timestamp(mixed $value, string $label): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$label} must be a timestamp.");
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new RuntimeException("{$label} is invalid.");
        }
    }

    private function safeIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $identifier) === 1
            && ! in_array($identifier, ['.', '..'], true);
    }

    /** @return array<string, string> */
    private function selectionMap(mixed $value, string $label, bool $nonEmpty = false): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException("{$label} must be a slot-to-artifact map.");
        }
        $selections = [];
        foreach ($value as $slot => $artifactId) {
            if (! is_string($slot)
                || ! $this->safeIdentifier($slot)
                || ! is_string($artifactId)
                || ! $this->safeIdentifier($artifactId)) {
                throw new RuntimeException("{$label} contains an invalid slot or artifact ID.");
            }
            $selections[$slot] = $artifactId;
        }
        if ($nonEmpty && $selections === []) {
            throw new RuntimeException("{$label} cannot be empty.");
        }

        return $selections;
    }

    /**
     * @return array{mode: string, source_id: string, explicit: bool}
     */
    private function ideasSourceEvidence(mixed $value, string $label, bool $requireExplicit): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("{$label} must be a source-evidence map.");
        }

        $mode = $value['mode'] ?? null;
        $sourceId = $value['source_id'] ?? null;
        $explicit = $value['explicit'] ?? null;
        $skipped = $value['skipped'] ?? null;
        if (! in_array($mode, ['selected_idea', 'project_idea'], true)
            || ! is_string($sourceId)
            || ! $this->safeIdentifier($sourceId)
            || ! is_bool($explicit)
            || ! is_bool($skipped)) {
            throw new RuntimeException("{$label} has an invalid mode, source identity, or explicit state.");
        }
        if ($requireExplicit && ! $explicit) {
            throw new RuntimeException("{$label} requires an explicit immutable source decision.");
        }

        $decisionId = $value['decision_id'] ?? null;
        $decisionSequence = $value['decision_sequence'] ?? null;
        $lineageSequence = $value['lineage_sequence'] ?? null;
        $artifactId = $value['artifact_id'] ?? null;
        $selectionId = $value['selection_id'] ?? null;
        $selectedBy = $value['selected_by'] ?? null;
        if ($explicit) {
            if (! is_string($decisionId)
                || ! $this->safeIdentifier($decisionId)
                || ! is_int($decisionSequence)
                || $decisionSequence < 1
                || ($lineageSequence !== null
                    && (! is_int($lineageSequence) || $lineageSequence < 1))
                || ! in_array($selectedBy, ['human', 'automation'], true)) {
                throw new RuntimeException("{$label} has invalid immutable decision identity.");
            }
            if ($mode === 'selected_idea'
                && (! is_string($artifactId)
                    || $artifactId !== $sourceId
                    || ! is_string($selectionId)
                    || ! $this->safeIdentifier($selectionId)
                    || $skipped)) {
                throw new RuntimeException("{$label} has invalid selected-Idea decision evidence.");
            }
            if ($mode === 'project_idea'
                && ($artifactId !== null || $selectionId !== null || ! $skipped)) {
                throw new RuntimeException("{$label} has invalid project-Idea skip evidence.");
            }
        } elseif ($decisionId !== null
            || $decisionSequence !== null
            || $lineageSequence !== null
            || $artifactId !== null
            || $selectionId !== null
            || $selectedBy !== null
            || $skipped) {
            throw new RuntimeException("{$label} cannot claim decision fields without explicit evidence.");
        }

        return [
            'mode' => $mode,
            'source_id' => $sourceId,
            'explicit' => $explicit,
        ];
    }
}
