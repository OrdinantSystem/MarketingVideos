import { CircleCheck, ShieldAlert, TriangleAlert } from 'lucide-react';
import type { IntegrityIssue, IntegrityState } from '@/types/creative';

interface IntegrityPanelProps {
    integrity: IntegrityState;
    activePhase: string;
}

const severityStyles: Record<IntegrityIssue['severity'], string> = {
    error: 'border-red-200 bg-red-50 text-red-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-950',
    info: 'border-sky-200 bg-sky-50 text-sky-950',
};

function IssueRecord({ issue }: { issue: IntegrityIssue }) {
    return (
        <article
            className={`rounded-lg border p-3 text-xs leading-5 ${severityStyles[issue.severity]}`}
        >
            <div className="flex items-start justify-between gap-3">
                <strong className="break-words">
                    {issue.code.replaceAll('_', ' ')}
                </strong>
                <span className="shrink-0 text-[9px] font-semibold tracking-wide uppercase opacity-70">
                    {issue.severity}
                </span>
            </div>
            <p className="mt-1">{issue.message}</p>
            <p className="mt-2 border-t border-current/15 pt-2">
                <span className="font-semibold">Next action:</span>{' '}
                {issue.remediation}
            </p>
            {issue.path ? (
                <p className="mt-2 font-mono text-[9px] break-all opacity-70">
                    {issue.path}
                </p>
            ) : null}
        </article>
    );
}

export function IntegrityPanel({
    integrity,
    activePhase,
}: IntegrityPanelProps) {
    const activeIssues = integrity.issues.filter(
        (issue) => issue.phase === activePhase,
    );
    const otherIssues = integrity.issues.filter(
        (issue) => issue.phase !== activePhase,
    );

    return (
        <section className="rounded-xl border border-[#deddd6] bg-[#fbfaf7] p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2 text-sm font-semibold text-[#304542]">
                        {integrity.errors > 0 ? (
                            <ShieldAlert
                                className="size-4 text-red-600"
                                aria-hidden="true"
                            />
                        ) : integrity.warnings > 0 ? (
                            <TriangleAlert
                                className="size-4 text-amber-600"
                                aria-hidden="true"
                            />
                        ) : (
                            <CircleCheck
                                className="size-4 text-emerald-700"
                                aria-hidden="true"
                            />
                        )}
                        Integrity report
                    </div>
                    <p className="mt-1 text-xs leading-5 text-[#7a827f]">
                        {integrity.errors} errors · {integrity.warnings}{' '}
                        warnings · {integrity.info} progress notes
                    </p>
                </div>
                <span
                    className={`rounded-full px-2 py-1 text-[10px] font-semibold ${integrity.errors > 0 ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800'}`}
                >
                    {integrity.total} issues
                </span>
            </div>

            {integrity.issues.length === 0 ? (
                <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-800">
                    No integrity problems were found for this run.
                </p>
            ) : (
                <div className="mt-3 space-y-2">
                    {activeIssues.length > 0 ? (
                        <details open>
                            <summary className="cursor-pointer text-[11px] font-semibold text-[#53615d]">
                                Current phase · {activeIssues.length}
                            </summary>
                            <div className="mt-2 space-y-2">
                                {activeIssues.map((issue, index) => (
                                    <IssueRecord
                                        key={`${issue.code}-${issue.path ?? issue.entity ?? index}`}
                                        issue={issue}
                                    />
                                ))}
                            </div>
                        </details>
                    ) : null}
                    {otherIssues.length > 0 ? (
                        <details>
                            <summary className="cursor-pointer text-[11px] font-semibold text-[#53615d]">
                                Other run issues · {otherIssues.length}
                            </summary>
                            <div className="creative-scrollbar mt-2 max-h-96 space-y-2 overflow-auto">
                                {otherIssues.map((issue, index) => (
                                    <IssueRecord
                                        key={`${issue.code}-${issue.path ?? issue.entity ?? index}`}
                                        issue={issue}
                                    />
                                ))}
                            </div>
                        </details>
                    ) : null}
                </div>
            )}
        </section>
    );
}
