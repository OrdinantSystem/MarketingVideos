import { Link } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, RotateCcw, TriangleAlert } from 'lucide-react';
import type { CreativePhase, IntegrityState } from '@/types/creative';

interface PhaseRecoveryNoticeProps {
    phase: CreativePhase;
    phases: CreativePhase[];
    integrity: IntegrityState;
    projectId: string;
    runId: string;
}

export function PhaseRecoveryNotice({
    phase,
    phases,
    integrity,
    projectId,
    runId,
}: PhaseRecoveryNoticeProps) {
    const phaseIndex = phases.findIndex((item) => item.key === phase.key);
    const previous = phaseIndex > 0 ? phases[phaseIndex - 1] : null;
    const issue = integrity.issues.find(
        (item) => item.phase === phase.key && item.severity !== 'info',
    );

    if (
        phase.status === 'ready' &&
        (phase.selected_count === 0 || phase.required_count <= 1)
    ) {
        return null;
    }

    if (phase.status === 'blocked') {
        return (
            <aside className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                <div className="flex items-start gap-2">
                    <CircleAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <strong>This phase is blocked by its input.</strong>
                        <p className="mt-1 text-xs leading-5">
                            {issue?.message ??
                                'Select every required valid output in the previous phase before generating here.'}
                        </p>
                        {previous ? (
                            <Link
                                href={`/projects/${projectId}/runs/${runId}/${previous.key}`}
                                className="mt-2 inline-flex items-center gap-1 text-xs font-semibold underline underline-offset-2"
                            >
                                <ArrowLeft
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                Fix {previous.name}
                            </Link>
                        ) : null}
                    </div>
                </div>
            </aside>
        );
    }

    if (phase.status === 'stale') {
        return (
            <aside className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                <div className="flex items-start gap-2">
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <strong>
                            The effective selection is stale or invalid.
                        </strong>
                        <p className="mt-1 text-xs leading-5">
                            {issue?.message ??
                                'The selected file no longer matches the current upstream selection or artifact contract.'}{' '}
                            Inspect the integrity details, then select a current
                            valid candidate or generate a new immutable attempt.
                        </p>
                    </div>
                </div>
            </aside>
        );
    }

    if (
        phase.required_count > 1 &&
        phase.selected_count > 0 &&
        !phase.complete
    ) {
        return (
            <aside className="mb-5 flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                <RotateCcw
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <div>
                    <strong>
                        {phase.required_count - phase.selected_count} shot
                        {phase.required_count - phase.selected_count === 1
                            ? ''
                            : 's'}{' '}
                        still need a selection.
                    </strong>
                    <p className="mt-1 text-xs leading-5">
                        The next phase stays blocked until every script-defined
                        shot has one valid effective artifact.
                    </p>
                </div>
            </aside>
        );
    }

    return null;
}
