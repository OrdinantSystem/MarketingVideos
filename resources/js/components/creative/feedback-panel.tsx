import { router, useForm } from '@inertiajs/react';
import { Archive, MessageSquareText, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { MarkdownPreview } from '@/components/creative/markdown-preview';
import { MetadataInspector } from '@/components/creative/metadata-inspector';
import { Button } from '@/components/ui/button';
import type { FeedbackState, TraceDocument } from '@/types/creative';

interface FeedbackPanelProps {
    feedback: FeedbackState;
    projectId: string;
    runId: string;
    phaseKey: string;
}

const referenceKeys = [
    'implementation_references',
    'generation_attempt_ids',
    'artifact_ids',
    'selection_ids',
    'verification_references',
];

interface FeedbackReference {
    key: string;
    value: unknown;
}

function feedbackReferences(document: TraceDocument): FeedbackReference[] {
    return referenceKeys.flatMap((key) => {
        const value = document.metadata[key];

        if (Array.isArray(value)) {
            return value.map((reference) => ({ key, value: reference }));
        }

        return value ? [{ key, value }] : [];
    });
}

function isMetadata(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function FeedbackHistoryRecord({ document }: { document: TraceDocument }) {
    const allReferences = feedbackReferences(document);
    const references = allReferences.slice(0, 30);

    return (
        <details className="rounded-lg border border-[#e1dfd8] bg-white">
            <summary className="cursor-pointer px-3 py-2.5 text-xs">
                <span className="font-semibold text-[#3f514d]">
                    {document.title}
                </span>
                <span className="ml-2 text-[10px] text-[#8c9390]">
                    {String(document.metadata.status ?? 'status unknown')}
                </span>
            </summary>
            <div className="space-y-3 border-t border-[#e5e3dc] p-3">
                {references.length > 0 ? (
                    <div className="rounded-md bg-emerald-50 px-3 py-2 text-[10px] leading-5 text-emerald-900">
                        <strong className="block">
                            Implementation references
                        </strong>
                        {references.map((reference, index) => (
                            <div
                                key={`${reference.key}-${index}`}
                                className="mt-2 rounded-md border border-emerald-200 bg-white/70 p-2"
                            >
                                <span className="block font-mono text-[9px] font-semibold break-all text-emerald-800">
                                    {reference.key}
                                </span>
                                {isMetadata(reference.value) ? (
                                    <div className="mt-1 text-[#43514e]">
                                        <MetadataInspector
                                            metadata={reference.value}
                                        />
                                    </div>
                                ) : (
                                    <code className="mt-1 block break-all">
                                        {String(reference.value)}
                                    </code>
                                )}
                            </div>
                        ))}
                        {allReferences.length > references.length ? (
                            <p className="mt-2 text-[9px]">
                                Showing the first {references.length}{' '}
                                references.
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <p className="rounded-md bg-amber-50 px-3 py-2 text-[10px] leading-5 text-amber-900">
                        No implementation, generation, selection, or
                        verification reference is recorded for this snapshot.
                    </p>
                )}
                <MarkdownPreview document={document} />
            </div>
        </details>
    );
}

export function FeedbackPanel({
    feedback,
    projectId,
    runId,
    phaseKey,
}: FeedbackPanelProps) {
    const form = useForm({ scope: 'phase', feedback: '' });
    const [archivePending, setArchivePending] = useState(false);
    const baseUrl = `/projects/${projectId}/runs/${runId}/${phaseKey}/feedback`;
    const pendingCount = feedback.current.length + feedback.cycle.length;
    const visiblePending =
        form.data.scope === 'phase' ? feedback.current : feedback.cycle;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(baseUrl, {
            preserveScroll: true,
            onSuccess: () => form.reset('feedback'),
        });
    }

    function archiveCurrentScope() {
        router.post(
            `${baseUrl}/archive`,
            { scope: form.data.scope },
            {
                preserveScroll: true,
                onStart: () => setArchivePending(true),
                onFinish: () => setArchivePending(false),
            },
        );
    }

    return (
        <section className="rounded-xl border border-[#deddd6] bg-[#fbfaf7] p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-semibold text-[#2e423f]">
                        <MessageSquareText
                            className="size-4 text-[#d26d44]"
                            aria-hidden="true"
                        />
                        Feedback inbox
                    </div>
                    <p className="mt-1 text-xs leading-5 text-[#818986]">
                        Stored as Markdown. Moving it to history does not claim
                        it was implemented.
                    </p>
                </div>
                <span className="rounded-full bg-[#eeece5] px-2 py-1 text-[10px] font-medium text-[#747d79]">
                    {pendingCount} pending
                </span>
            </div>

            <form onSubmit={submit} className="mt-4 space-y-3">
                <div className="flex gap-1 rounded-lg bg-[#efeee8] p-1">
                    {(['phase', 'cycle'] as const).map((scope) => (
                        <button
                            key={scope}
                            type="button"
                            onClick={() => form.setData('scope', scope)}
                            aria-pressed={form.data.scope === scope}
                            className={`flex-1 rounded-md px-2 py-1.5 text-[11px] font-medium ${form.data.scope === scope ? 'bg-white text-[#2b403d] shadow-sm' : 'text-[#858c89]'}`}
                        >
                            {scope === 'phase' ? 'This phase' : 'Whole cycle'} ·{' '}
                            {scope === 'phase'
                                ? feedback.current.length
                                : feedback.cycle.length}
                        </button>
                    ))}
                </div>
                <textarea
                    aria-label="Feedback note"
                    value={form.data.feedback}
                    onChange={(event) =>
                        form.setData('feedback', event.target.value)
                    }
                    rows={4}
                    required
                    placeholder="What should be clearer, stronger, or different?"
                    className="w-full resize-none rounded-lg border border-[#d8d7d0] bg-white px-3 py-2.5 text-sm leading-5 outline-none placeholder:text-[#a8adaa] focus:border-[#8fa59e]"
                />
                {form.errors.feedback ? (
                    <p className="text-xs text-red-600">
                        {form.errors.feedback}
                    </p>
                ) : null}
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing}
                    className="w-full bg-[#294c47] text-white hover:bg-[#203e3a]"
                >
                    <Send className="size-3.5" aria-hidden="true" /> Add
                    feedback
                </Button>
            </form>

            <div className="mt-4 border-t border-[#e6e4de] pt-3">
                <p className="text-[10px] font-semibold tracking-wide text-[#69736f] uppercase">
                    Pending in{' '}
                    {form.data.scope === 'phase'
                        ? 'this phase'
                        : 'the whole cycle'}
                </p>
                {visiblePending.length > 0 ? (
                    <div className="mt-2 space-y-2">
                        {visiblePending.map((section) => (
                            <article
                                key={section.title}
                                className="rounded-lg border border-[#e1dfd8] bg-white px-3 py-2 text-xs leading-5 text-[#56625e]"
                            >
                                <strong className="block text-[10px] text-[#7b8480]">
                                    {section.title}
                                </strong>
                                <p className="mt-1 whitespace-pre-wrap">
                                    {section.content}
                                </p>
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-xs text-[#929895]">
                        No feedback is waiting in this scope.
                    </p>
                )}
            </div>

            <div className="mt-4 border-t border-[#e6e4de] pt-3">
                <div className="flex items-center justify-between gap-3 text-[11px] text-[#858c89]">
                    <span>{feedback.history_count} archived snapshots</span>
                    <button
                        type="button"
                        onClick={archiveCurrentScope}
                        disabled={visiblePending.length === 0 || archivePending}
                        className="flex items-center gap-1.5 text-right font-medium text-[#60716c] hover:text-[#263f3b] disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        <Archive className="size-3" aria-hidden="true" />
                        {archivePending
                            ? 'Archiving…'
                            : 'Move current to history'}
                    </button>
                </div>
                {feedback.history.length > 0 ? (
                    <details className="mt-3">
                        <summary className="cursor-pointer text-[11px] font-semibold text-[#60716c]">
                            Browse archived feedback and references
                        </summary>
                        <div className="creative-scrollbar mt-2 max-h-[520px] space-y-2 overflow-auto">
                            {feedback.history.map((document) => (
                                <FeedbackHistoryRecord
                                    key={document.id}
                                    document={document}
                                />
                            ))}
                        </div>
                    </details>
                ) : null}
            </div>
        </section>
    );
}
