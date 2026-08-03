import { Link, router } from '@inertiajs/react';
import type { RequestPayload } from '@inertiajs/core';
import {
    Bot,
    CheckCircle2,
    CirclePause,
    LoaderCircle,
    MessageSquarePlus,
    RotateCcw,
    ShieldCheck,
    Square,
    UserCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { AutomationGate, AutomationStatus } from '@/types/creative';

interface AutomationPanelProps {
    automation: AutomationStatus | null;
    projectId: string;
    runId: string;
    phaseKey: string;
}

type AutomationDecision =
    'approve' | 'manual_override' | 'retry_with_feedback' | 'cancel';

const defaultPolicy = {
    max_attempts_per_phase: 2,
    max_total_attempts: 12,
    max_simulation_units: 60,
    score_threshold: 80,
    human_gates: ['brief-script', 'sequence'],
};

function phaseLabel(phase: string): string {
    return phase.replaceAll('-', ' & ');
}

function exactRecommendationSelected(gate: AutomationGate): boolean {
    const recommendationEntries = Object.entries(gate.recommendations);

    return (
        recommendationEntries.length > 0 &&
        recommendationEntries.every(
            ([slot, artifactId]) => gate.selection_ids[slot] === artifactId,
        ) &&
        Object.keys(gate.selection_ids).length === recommendationEntries.length
    );
}

export function AutomationPanel({
    automation,
    projectId,
    runId,
    phaseKey,
}: AutomationPanelProps) {
    const [pendingAction, setPendingAction] = useState<string | null>(null);
    const [retryFeedback, setRetryFeedback] = useState('');
    const [retryError, setRetryError] = useState<string | null>(null);
    const baseUrl = `/projects/${projectId}/runs/${runId}/automation`;
    const decisionUrl = `${baseUrl}/decision`;
    const state = automation?.state;
    const policy = automation?.policy;
    const gate = automation?.gate ?? null;
    const canCancelOutsideGate =
        state !== undefined &&
        state.status !== 'complete' &&
        state.status !== 'cancelled' &&
        state.status !== 'awaiting_review';
    const canResumeCheckpoint =
        state !== undefined &&
        !['awaiting_review', 'complete'].includes(state.status) &&
        (state.status === 'cancelled' ||
            state.status === 'failed' ||
            automation?.recovery_available === true);
    const checkpointLabel = automation?.error
        ? 'Checkpoint status unavailable'
        : state === undefined
          ? 'No automation checkpoint yet'
          : automation?.checkpoint_consistent === true
            ? 'Filesystem checkpoint is consistent'
            : automation?.checkpoint_consistent === false
              ? 'Checkpoint needs recovery'
              : 'Checkpoint consistency not verified';
    const checkpointConsistent =
        state !== undefined && automation?.checkpoint_consistent === true;

    function post(url: string, payload: RequestPayload, action: string) {
        router.post(url, payload, {
            preserveScroll: true,
            onStart: () => setPendingAction(action),
            onFinish: () => setPendingAction(null),
        });
    }

    function decide(decision: AutomationDecision) {
        if (!gate) {
            return;
        }

        post(decisionUrl, { decision, gate_token: gate.gate_token }, decision);
    }

    function retry(event: FormEvent) {
        event.preventDefault();
        const feedback = retryFeedback.trim();

        if (!feedback) {
            setRetryError(
                'Explain what the next bounded simulation attempt should change.',
            );

            return;
        }

        if (!gate) {
            return;
        }

        setRetryError(null);
        post(
            decisionUrl,
            {
                decision: 'retry_with_feedback',
                feedback,
                gate_token: gate.gate_token,
            },
            'retry_with_feedback',
        );
    }

    return (
        <section className="rounded-xl border border-[#d8d6ce] bg-white p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2 text-sm font-semibold text-[#304542]">
                        <Bot className="size-4" aria-hidden="true" />
                        Automatic simulation
                    </div>
                    <p className="mt-1 text-xs leading-5 text-[#7a827f]">
                        Evaluates structural eligibility and selects the same
                        immutable files as manual mode. It does not judge final
                        creative quality. External providers are disabled.
                    </p>
                </div>
                <span className="shrink-0 rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-semibold text-emerald-800">
                    $0 API cost
                </span>
            </div>

            {automation?.error ? (
                <p
                    role="alert"
                    className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700"
                >
                    {automation.error}
                </p>
            ) : state && policy ? (
                <div className="mt-4 space-y-3">
                    <div className="grid grid-cols-2 gap-2 text-xs">
                        <div className="rounded-lg bg-[#f2f1ec] p-2.5">
                            <span className="block text-[10px] tracking-wide text-[#848c88] uppercase">
                                Status
                            </span>
                            <strong className="mt-0.5 block text-[#304542]">
                                {state.status.replaceAll('_', ' ')}
                            </strong>
                        </div>
                        <div className="rounded-lg bg-[#f2f1ec] p-2.5">
                            <span className="block text-[10px] tracking-wide text-[#848c88] uppercase">
                                Current phase
                            </span>
                            <strong className="mt-0.5 block text-[#304542]">
                                {phaseLabel(state.current_phase)}
                            </strong>
                        </div>
                        <div className="rounded-lg bg-[#f2f1ec] p-2.5">
                            <span className="block text-[10px] tracking-wide text-[#848c88] uppercase">
                                Attempts
                            </span>
                            <strong className="mt-0.5 block text-[#304542]">
                                {state.total_attempts}/
                                {policy.max_total_attempts}
                            </strong>
                        </div>
                        <div className="rounded-lg bg-[#f2f1ec] p-2.5">
                            <span className="block text-[10px] tracking-wide text-[#848c88] uppercase">
                                Simulation units
                            </span>
                            <strong className="mt-0.5 block text-[#304542]">
                                {state.simulation_units_spent}/
                                {policy.max_simulation_units}
                            </strong>
                        </div>
                    </div>

                    {state.gate_reason ? (
                        <div className="flex gap-2 rounded-lg bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                            <CirclePause
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{state.gate_reason}</span>
                        </div>
                    ) : null}

                    {state.last_error ? (
                        <p className="rounded-lg bg-red-50 px-3 py-2 text-xs leading-5 text-red-800">
                            <strong>Last error:</strong> {state.last_error}
                        </p>
                    ) : null}

                    {gate ? (
                        <div className="rounded-lg border border-[#dedbd3] bg-[#f8f7f3] p-3">
                            <div className="flex items-center justify-between gap-2">
                                <strong className="text-xs text-[#304542]">
                                    Human decision · {phaseLabel(gate.phase)}
                                </strong>
                                <span className="rounded-full bg-white px-2 py-0.5 text-[9px] font-semibold text-[#68736f]">
                                    {gate.kind === 'review'
                                        ? 'recommendation review'
                                        : 'manual recovery'}
                                </span>
                            </div>
                            {gate.evaluation_id ? (
                                <p className="mt-1 font-mono text-[9px] break-all text-[#8b928f]">
                                    Evaluation: {gate.evaluation_id}
                                </p>
                            ) : null}
                            {Object.keys(gate.recommendations).length > 0 ? (
                                <div className="mt-2 text-[10px] leading-5 text-[#65706c]">
                                    <strong className="block text-[#4b5a56]">
                                        Structural recommendations
                                    </strong>
                                    {Object.entries(gate.recommendations).map(
                                        ([slot, artifactId]) => (
                                            <p
                                                key={slot}
                                                className="font-mono break-all"
                                            >
                                                {slot} → {artifactId}
                                                {gate.selection_ids[slot] ===
                                                artifactId
                                                    ? ' · selected'
                                                    : ' · overridden'}
                                            </p>
                                        ),
                                    )}
                                </div>
                            ) : null}

                            {phaseKey !== gate.phase ? (
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="mt-3"
                                >
                                    <Link
                                        href={`/projects/${projectId}/runs/${runId}/${gate.phase}`}
                                    >
                                        Review {phaseLabel(gate.phase)} files
                                    </Link>
                                </Button>
                            ) : null}

                            <div className="mt-3 flex flex-wrap gap-2">
                                {gate.decisions.includes('approve') ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={
                                            !exactRecommendationSelected(
                                                gate,
                                            ) || pendingAction !== null
                                        }
                                        onClick={() => decide('approve')}
                                        className="bg-[#294c47] text-white hover:bg-[#203e3a]"
                                    >
                                        {pendingAction === 'approve' ? (
                                            <LoaderCircle
                                                className="animate-spin"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <CheckCircle2 aria-hidden="true" />
                                        )}
                                        Approve exact recommendation
                                    </Button>
                                ) : null}
                                {gate.decisions.includes('manual_override') ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={pendingAction !== null}
                                        onClick={() =>
                                            decide('manual_override')
                                        }
                                    >
                                        {pendingAction === 'manual_override' ? (
                                            <LoaderCircle
                                                className="animate-spin"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <UserCheck aria-hidden="true" />
                                        )}
                                        Accept my selections
                                    </Button>
                                ) : null}
                            </div>

                            {gate.decisions.includes('retry_with_feedback') ? (
                                <form onSubmit={retry} className="mt-3">
                                    <label className="block">
                                        <span className="text-[10px] font-semibold text-[#53615d]">
                                            Retry with feedback
                                        </span>
                                        <textarea
                                            value={retryFeedback}
                                            onChange={(event) =>
                                                setRetryFeedback(
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            maxLength={5000}
                                            rows={3}
                                            placeholder="What should the next bounded simulation attempt change?"
                                            className="mt-1 w-full resize-y rounded-md border border-[#d8d7d0] bg-white px-3 py-2 text-xs leading-5 outline-none focus:border-[#8fa59e]"
                                        />
                                    </label>
                                    {retryError ? (
                                        <p className="mt-1 text-xs text-red-600">
                                            {retryError}
                                        </p>
                                    ) : null}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={pendingAction !== null}
                                        className="mt-2"
                                    >
                                        {pendingAction ===
                                        'retry_with_feedback' ? (
                                            <LoaderCircle
                                                className="animate-spin"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <MessageSquarePlus aria-hidden="true" />
                                        )}
                                        Save feedback and retry
                                    </Button>
                                    <p className="mt-1 text-[9px] leading-4 text-[#8b928f]">
                                        The exact snapshot is linked by hash to
                                        the retry. It becomes applied history
                                        only if that attempt commits; otherwise
                                        it remains pending.
                                    </p>
                                </form>
                            ) : null}

                            {gate.decisions.includes('cancel') ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    disabled={pendingAction !== null}
                                    onClick={() => decide('cancel')}
                                    className="mt-2 text-[#7c5144]"
                                >
                                    <Square aria-hidden="true" /> Cancel at
                                    checkpoint
                                </Button>
                            ) : null}
                        </div>
                    ) : null}

                    <p className="text-[11px] leading-5 text-[#7a827f]">
                        Structural threshold {policy.score_threshold}/100 · max{' '}
                        {policy.max_attempts_per_phase} attempts per phase ·
                        gates at{' '}
                        {policy.human_gates.map(phaseLabel).join(', ') ||
                            'none'}
                        . {automation.event_count ?? 0} immutable events
                        recorded.
                    </p>

                    {automation?.projection_issues?.length ? (
                        <div className="rounded-lg bg-red-50 px-3 py-2 text-xs leading-5 text-red-800">
                            <strong>Checkpoint projection issues</strong>
                            {automation.projection_issues.map((issue) => (
                                <p key={issue}>{issue}</p>
                            ))}
                        </div>
                    ) : null}

                    <div className="flex flex-wrap gap-2">
                        {canResumeCheckpoint ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={pendingAction !== null}
                                onClick={() =>
                                    post(`${baseUrl}/resume`, {}, 'resume')
                                }
                                className="bg-[#294c47] text-white hover:bg-[#203e3a]"
                            >
                                {pendingAction === 'resume' ? (
                                    <LoaderCircle
                                        className="animate-spin"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <RotateCcw aria-hidden="true" />
                                )}
                                Resume from checkpoint
                            </Button>
                        ) : null}
                        {canCancelOutsideGate ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={pendingAction !== null}
                                onClick={() =>
                                    post(`${baseUrl}/cancel`, {}, 'cancel')
                                }
                            >
                                <Square aria-hidden="true" /> Cancel
                            </Button>
                        ) : null}
                    </div>
                </div>
            ) : (
                <div className="mt-4">
                    <p className="text-[11px] leading-5 text-[#7a827f]">
                        Default policy: 2 attempts per phase, 12 total, 60
                        simulation units, structural score 80/100, with human
                        review after Brief &amp; script and Sequence.
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        disabled={pendingAction !== null}
                        onClick={() =>
                            post(`${baseUrl}/start`, defaultPolicy, 'start')
                        }
                        className="mt-3 bg-[#294c47] text-white hover:bg-[#203e3a]"
                    >
                        {pendingAction === 'start' ? (
                            <LoaderCircle
                                className="animate-spin"
                                aria-hidden="true"
                            />
                        ) : (
                            <Bot aria-hidden="true" />
                        )}
                        Start automatic run
                    </Button>
                </div>
            )}

            <div className="mt-4 border-t border-[#e5e3dc] pt-3 text-[11px] leading-5 text-[#737d79]">
                <div className="flex items-center gap-1.5 font-semibold text-[#40514d]">
                    {checkpointConsistent ? (
                        <ShieldCheck className="size-3.5" aria-hidden="true" />
                    ) : (
                        <CirclePause className="size-3.5" aria-hidden="true" />
                    )}
                    {checkpointLabel}
                </div>
                {state ? (
                    <p className="mt-1">
                        Cancellation preserves the checkpoint, files,
                        evaluations, and event history.
                    </p>
                ) : (
                    <p className="mt-1">
                        Start automatic simulation to create the first
                        recoverable checkpoint.
                    </p>
                )}
            </div>
        </section>
    );
}
