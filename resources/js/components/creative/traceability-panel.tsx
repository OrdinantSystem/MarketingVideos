import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    FileClock,
    Film,
    History,
    MessageSquareText,
    Route,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { MetadataInspector } from '@/components/creative/metadata-inspector';
import type {
    AutomationEvent,
    FeedbackApplicationTrace,
    GenerationAttempt,
    MediaJobTrace,
    TraceDocument,
    TraceabilityState,
} from '@/types/creative';

interface TraceabilityPanelProps {
    traceability: TraceabilityState;
}

function timestampLabel(value: unknown): string {
    if (typeof value !== 'string' || value === '') {
        return 'time not recorded';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}

function DocumentRecord({
    document,
    kind,
}: {
    document: TraceDocument;
    kind: string;
}) {
    const passed = document.metadata.passed;

    return (
        <details className="rounded-lg border border-[#e1dfd8] bg-white">
            <summary className="flex cursor-pointer list-none items-start justify-between gap-3 px-3 py-2.5 text-xs">
                <span className="min-w-0">
                    <span className="block truncate font-semibold text-[#334744]">
                        {document.title}
                    </span>
                    <span className="mt-0.5 block truncate font-mono text-[9px] text-[#969c99]">
                        {document.path ?? 'path unavailable'}
                    </span>
                </span>
                {typeof passed === 'boolean' ? (
                    <span
                        className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-semibold ${passed ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'}`}
                    >
                        {passed ? 'passed' : 'not passed'}
                    </span>
                ) : (
                    <span className="shrink-0 text-[9px] tracking-wide text-[#909693] uppercase">
                        {kind}
                    </span>
                )}
            </summary>
            <div className="space-y-3 border-t border-[#e5e3dc] p-3">
                {document.parse_error ? (
                    <p className="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
                        {document.parse_error}
                    </p>
                ) : null}
                {document.body ? (
                    <pre className="creative-scrollbar max-h-48 overflow-auto rounded-md bg-[#f5f4ef] p-3 font-mono text-[10px] leading-5 whitespace-pre-wrap text-[#55615d]">
                        {document.body}
                    </pre>
                ) : null}
                <MetadataInspector metadata={document.metadata} />
            </div>
        </details>
    );
}

function AttemptRecord({ attempt }: { attempt: GenerationAttempt }) {
    const isCommitted = attempt.status === 'committed';

    return (
        <details className="rounded-lg border border-[#e1dfd8] bg-white">
            <summary className="flex cursor-pointer list-none items-start justify-between gap-3 px-3 py-2.5 text-xs">
                <span className="min-w-0">
                    <span className="block truncate font-semibold text-[#334744]">
                        {attempt.driver} · {timestampLabel(attempt.started_at)}
                    </span>
                    <span className="mt-0.5 block truncate font-mono text-[9px] text-[#969c99]">
                        {attempt.id}
                    </span>
                </span>
                <span
                    className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-semibold ${isCommitted ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'}`}
                >
                    {attempt.status}
                </span>
            </summary>
            <div className="space-y-3 border-t border-[#e5e3dc] p-3">
                <p className="text-[11px] leading-5 text-[#737d79]">
                    {attempt.artifact_ids.length} artifacts ·{' '}
                    {attempt.simulation_units ?? 0} simulation units · cost{' '}
                    {attempt.cost_usd === null
                        ? 'not recorded'
                        : `$${attempt.cost_usd.toFixed(2)}`}
                </p>
                {attempt.parse_errors.length > 0 ? (
                    <div className="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
                        {attempt.parse_errors.map((error) => (
                            <p key={error}>{error}</p>
                        ))}
                    </div>
                ) : null}
                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Request manifest
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector metadata={attempt.request} />
                    </div>
                </details>
                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Result manifest
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector
                            metadata={attempt.result ?? {}}
                            emptyMessage="This attempt has no terminal result manifest."
                        />
                    </div>
                </details>
            </div>
        </details>
    );
}

function EventRecord({ event }: { event: AutomationEvent }) {
    const metadata = Object.fromEntries(
        Object.entries(event).filter(
            ([key]) => !['_body', 'path'].includes(key),
        ),
    );

    return (
        <details className="rounded-lg border border-[#e1dfd8] bg-white">
            <summary className="cursor-pointer px-3 py-2.5 text-xs">
                <span className="font-semibold text-[#334744]">
                    {String(event.event ?? 'Automation event').replaceAll(
                        '_',
                        ' ',
                    )}
                </span>
                <span className="ml-2 text-[10px] text-[#8d9491]">
                    {timestampLabel(event.created_at)}
                </span>
            </summary>
            <div className="space-y-3 border-t border-[#e5e3dc] p-3">
                {typeof event._body === 'string' ? (
                    <p className="text-xs leading-5 text-[#596460]">
                        {event._body.replace(/^#[^\n]*\n*/, '').trim()}
                    </p>
                ) : null}
                <MetadataInspector metadata={metadata} />
                {event.path ? (
                    <p className="font-mono text-[9px] break-all text-[#969c99]">
                        {event.path}
                    </p>
                ) : null}
            </div>
        </details>
    );
}

function MediaJobRecord({ job }: { job: MediaJobTrace }) {
    const status = String(job.state?.status ?? 'state unavailable');
    const mediaType = String(job.request?.media_type ?? 'media');

    return (
        <details className="rounded-lg border border-[#e1dfd8] bg-white">
            <summary className="flex cursor-pointer list-none items-start justify-between gap-3 px-3 py-2.5 text-xs">
                <span className="min-w-0">
                    <span className="block truncate font-semibold text-[#334744]">
                        {mediaType} job · {status}
                    </span>
                    <span className="mt-0.5 block truncate font-mono text-[9px] text-[#969c99]">
                        {job.id}
                    </span>
                </span>
                <span className="shrink-0 rounded-full bg-sky-100 px-2 py-0.5 text-[9px] font-semibold text-sky-900">
                    simulated
                </span>
            </summary>
            <div className="space-y-3 border-t border-[#e5e3dc] p-3">
                {job.parse_errors.length > 0 ? (
                    <div className="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
                        {job.parse_errors.map((error) => (
                            <p key={error}>{error}</p>
                        ))}
                    </div>
                ) : null}
                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Request and asset contract
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector metadata={job.request ?? {}} />
                    </div>
                </details>
                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Atomic state checkpoint
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector metadata={job.state ?? {}} />
                    </div>
                </details>
                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Immutable result
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector
                            metadata={job.result ?? {}}
                            emptyMessage="No terminal result is recorded yet."
                        />
                    </div>
                </details>
                {job.events.length > 0 ? (
                    <details>
                        <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                            Job events · {job.events.length}
                        </summary>
                        <div className="mt-2 space-y-2">
                            {job.events.map((event, index) => (
                                <EventRecord
                                    key={String(
                                        event.id ?? event.path ?? index,
                                    )}
                                    event={event}
                                />
                            ))}
                        </div>
                    </details>
                ) : null}
                {job.path ? (
                    <p className="font-mono text-[9px] break-all text-[#969c99]">
                        {job.path}
                    </p>
                ) : null}
            </div>
        </details>
    );
}

function FeedbackApplicationRecord({
    application,
}: {
    application: FeedbackApplicationTrace;
}) {
    const isApplied = application.status.startsWith('applied');
    const isFailed = ['not_applied', 'unreadable'].includes(application.status);
    const visibleSources = application.source_snapshots.slice(0, 12);
    const visibleReferences = application.implementation_references.slice(
        0,
        20,
    );
    const visibleHistoryIds = application.history_ids.slice(0, 20);

    return (
        <details className="rounded-lg border border-[#e1dfd8] bg-white">
            <summary className="flex cursor-pointer list-none items-start justify-between gap-3 px-3 py-2.5 text-xs">
                <span className="min-w-0">
                    <span className="block truncate font-semibold text-[#334744]">
                        {application.phase ?? 'unknown phase'} ·{' '}
                        {timestampLabel(
                            application.finished_at ?? application.created_at,
                        )}
                    </span>
                    <span className="mt-0.5 block truncate font-mono text-[9px] text-[#969c99]">
                        {application.attempt_id}
                    </span>
                </span>
                <span
                    className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-semibold ${isApplied ? 'bg-emerald-100 text-emerald-800' : isFailed ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900'}`}
                >
                    {application.status.replaceAll('_', ' ')}
                </span>
            </summary>
            <div className="space-y-3 border-t border-[#e5e3dc] p-3">
                {application.parse_error ? (
                    <p className="max-h-32 overflow-auto rounded-md bg-red-50 px-3 py-2 text-xs break-words text-red-700">
                        {application.parse_error}
                    </p>
                ) : null}

                <div>
                    <p className="text-[10px] font-semibold tracking-wide text-[#77817d] uppercase">
                        Generation feedback hash
                    </p>
                    <code className="mt-1 block max-h-20 overflow-auto rounded-md bg-[#f5f4ef] px-2.5 py-2 text-[9px] break-all text-[#55615d]">
                        {application.generation_feedback_sha256 ??
                            'not recorded'}
                    </code>
                </div>

                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Source snapshots · {application.source_snapshots.length}
                    </summary>
                    <div className="creative-scrollbar mt-2 max-h-52 space-y-2 overflow-auto">
                        {visibleSources.length > 0 ? (
                            visibleSources.map((source, index) => (
                                <div
                                    key={`${source.scope}-${source.path ?? index}`}
                                    className="rounded-md border border-[#e5e3dc] bg-[#f8f7f3] px-3 py-2"
                                >
                                    <p className="text-[10px] font-semibold text-[#56625e]">
                                        {source.scope} feedback
                                    </p>
                                    <code className="mt-1 block text-[9px] break-all text-[#65706c]">
                                        sha256:{' '}
                                        {source.pending_sha256 ??
                                            'not recorded'}
                                    </code>
                                    {source.path ? (
                                        <p className="mt-1 font-mono text-[9px] break-all text-[#969c99]">
                                            {source.path}
                                        </p>
                                    ) : null}
                                </div>
                            ))
                        ) : (
                            <p className="text-[10px] text-[#8a918e]">
                                No source snapshots were recorded.
                            </p>
                        )}
                        {application.source_snapshots.length >
                        visibleSources.length ? (
                            <p className="text-[10px] text-[#8a918e]">
                                Showing the first {visibleSources.length} source
                                snapshots.
                            </p>
                        ) : null}
                    </div>
                </details>

                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Implementation references ·{' '}
                        {application.implementation_references.length}
                    </summary>
                    <div className="creative-scrollbar mt-2 max-h-64 space-y-2 overflow-auto">
                        {visibleReferences.length > 0 ? (
                            visibleReferences.map((reference, index) => (
                                <MetadataInspector
                                    key={String(
                                        reference.id ?? reference.path ?? index,
                                    )}
                                    metadata={reference}
                                />
                            ))
                        ) : (
                            <p className="text-[10px] text-[#8a918e]">
                                No implementation references were committed.
                            </p>
                        )}
                        {application.implementation_references.length >
                        visibleReferences.length ? (
                            <p className="text-[10px] text-[#8a918e]">
                                Showing the first {visibleReferences.length}{' '}
                                implementation references.
                            </p>
                        ) : null}
                    </div>
                </details>

                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Feedback history IDs · {application.history_ids.length}
                    </summary>
                    <div className="creative-scrollbar mt-2 max-h-40 space-y-1 overflow-auto rounded-md bg-[#f5f4ef] p-2.5">
                        {visibleHistoryIds.length > 0 ? (
                            visibleHistoryIds.map((historyId) => (
                                <code
                                    key={historyId}
                                    className="block text-[9px] break-all text-[#55615d]"
                                >
                                    {historyId}
                                </code>
                            ))
                        ) : (
                            <p className="text-[10px] text-[#8a918e]">
                                No history records were created.
                            </p>
                        )}
                        {application.history_ids.length >
                        visibleHistoryIds.length ? (
                            <p className="text-[10px] text-[#8a918e]">
                                Showing the first {visibleHistoryIds.length}{' '}
                                history IDs.
                            </p>
                        ) : null}
                    </div>
                </details>

                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Request record
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector
                            metadata={application.request ?? {}}
                            emptyMessage="No readable request record was found."
                        />
                    </div>
                </details>
                <details>
                    <summary className="cursor-pointer text-[11px] font-semibold text-[#56625e]">
                        Result record
                    </summary>
                    <div className="mt-2">
                        <MetadataInspector
                            metadata={application.result ?? {}}
                            emptyMessage="This request has no readable result record yet."
                        />
                    </div>
                </details>
                {application.path ? (
                    <p className="font-mono text-[9px] break-all text-[#969c99]">
                        {application.path}
                    </p>
                ) : null}
            </div>
        </details>
    );
}

function TraceGroup({
    title,
    count,
    icon,
    children,
}: {
    title: string;
    count: number;
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <details className="rounded-xl border border-[#deddd6] bg-[#f8f7f3]">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-[#304542]">
                <span className="flex items-center gap-2">
                    {icon}
                    {title}
                </span>
                <span className="rounded-full bg-[#e9e7df] px-2 py-0.5 text-[10px] text-[#727b77]">
                    {count}
                </span>
            </summary>
            <div className="creative-scrollbar max-h-[560px] space-y-2 overflow-auto border-t border-[#e3e1da] p-3">
                {count > 0 ? (
                    children
                ) : (
                    <p className="px-2 py-4 text-center text-xs text-[#8a918e]">
                        No records yet.
                    </p>
                )}
            </div>
        </details>
    );
}

export function TraceabilityPanel({ traceability }: TraceabilityPanelProps) {
    return (
        <section aria-labelledby="traceability-heading">
            <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-[#304542]">
                <History className="size-4" aria-hidden="true" />
                <h2 id="traceability-heading">Traceability history</h2>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                <TraceGroup
                    title="Generation attempts"
                    count={traceability.attempts.length}
                    icon={<FileClock className="size-4" aria-hidden="true" />}
                >
                    {traceability.attempts.map((attempt) => (
                        <AttemptRecord key={attempt.id} attempt={attempt} />
                    ))}
                </TraceGroup>
                <TraceGroup
                    title="Evaluations"
                    count={traceability.evaluations.length}
                    icon={
                        <ClipboardCheck className="size-4" aria-hidden="true" />
                    }
                >
                    {traceability.evaluations.map((evaluation) => (
                        <DocumentRecord
                            key={evaluation.id}
                            document={evaluation}
                            kind="evaluation"
                        />
                    ))}
                </TraceGroup>
                <TraceGroup
                    title="Selection records"
                    count={traceability.selections.length}
                    icon={
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                    }
                >
                    {traceability.selections.map((selection) => (
                        <DocumentRecord
                            key={selection.id}
                            document={selection}
                            kind="selection"
                        />
                    ))}
                </TraceGroup>
                <TraceGroup
                    title="Ideas source decisions"
                    count={traceability.source_decisions.length}
                    icon={<Route className="size-4" aria-hidden="true" />}
                >
                    {traceability.source_decisions.map((decision) => (
                        <DocumentRecord
                            key={decision.id}
                            document={decision}
                            kind="source decision"
                        />
                    ))}
                </TraceGroup>
                <TraceGroup
                    title="Feedback applications"
                    count={traceability.feedback_applications.length}
                    icon={
                        <MessageSquareText
                            className="size-4"
                            aria-hidden="true"
                        />
                    }
                >
                    {traceability.feedback_applications.map((application) => (
                        <FeedbackApplicationRecord
                            key={application.attempt_id}
                            application={application}
                        />
                    ))}
                </TraceGroup>
                <TraceGroup
                    title="Simulated media jobs"
                    count={traceability.media_jobs.length}
                    icon={<Film className="size-4" aria-hidden="true" />}
                >
                    {traceability.media_jobs.map((job) => (
                        <MediaJobRecord key={job.id} job={job} />
                    ))}
                </TraceGroup>
                <TraceGroup
                    title="Automation events"
                    count={traceability.automation_events.length}
                    icon={<Activity className="size-4" aria-hidden="true" />}
                >
                    {traceability.automation_events.map((event, index) => (
                        <EventRecord
                            key={String(event.id ?? event.path ?? index)}
                            event={event}
                        />
                    ))}
                </TraceGroup>
            </div>
            {traceability.attempts.some(
                (attempt) => attempt.parse_errors.length > 0,
            ) ||
            traceability.feedback_applications.some(
                (application) => application.parse_error !== null,
            ) ? (
                <p className="mt-3 flex items-center gap-1.5 text-xs text-amber-800">
                    <TriangleAlert className="size-3.5" aria-hidden="true" />
                    At least one trace record is malformed; see the attempt and
                    integrity details.
                </p>
            ) : null}
        </section>
    );
}
