import { router, useForm } from '@inertiajs/react';
import { Archive, MessageSquareText, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import type { FeedbackState } from '@/types/creative';

interface FeedbackPanelProps {
    feedback: FeedbackState;
    projectId: string;
    runId: string;
    phaseKey: string;
}

export function FeedbackPanel({
    feedback,
    projectId,
    runId,
    phaseKey,
}: FeedbackPanelProps) {
    const form = useForm({ scope: 'phase', feedback: '' });
    const baseUrl = `/projects/${projectId}/runs/${runId}/${phaseKey}/feedback`;
    const pendingCount = feedback.current.length + feedback.cycle.length;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(baseUrl, {
            preserveScroll: true,
            onSuccess: () => form.reset('feedback'),
        });
    }

    return (
        <section className="rounded-xl border border-[#deddd6] bg-[#fbfaf7] p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-semibold text-[#2e423f]">
                        <MessageSquareText
                            className="size-4 text-[#d26d44]"
                            aria-hidden="true"
                        />{' '}
                        Feedback inbox
                    </div>
                    <p className="mt-1 text-xs leading-5 text-[#818986]">
                        Stored as Markdown now; archived after it improves the
                        system.
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
                            className={`flex-1 rounded-md px-2 py-1.5 text-[11px] font-medium ${form.data.scope === scope ? 'bg-white text-[#2b403d] shadow-sm' : 'text-[#858c89]'}`}
                        >
                            {scope === 'phase' ? 'This phase' : 'Whole cycle'}
                        </button>
                    ))}
                </div>
                <textarea
                    value={form.data.feedback}
                    onChange={(event) =>
                        form.setData('feedback', event.target.value)
                    }
                    rows={4}
                    required
                    placeholder="What should be clearer, stronger, or different?"
                    className="w-full resize-none rounded-lg border border-[#d8d7d0] bg-white px-3 py-2.5 text-sm leading-5 outline-none placeholder:text-[#a8adaa] focus:border-[#8fa59e]"
                />
                {form.errors.feedback && (
                    <p className="text-xs text-red-600">
                        {form.errors.feedback}
                    </p>
                )}
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
                <div className="flex items-center justify-between text-[11px] text-[#858c89]">
                    <span>{feedback.history_count} archived snapshots</span>
                    <button
                        type="button"
                        onClick={() =>
                            router.post(
                                `${baseUrl}/archive`,
                                { scope: form.data.scope },
                                { preserveScroll: true },
                            )
                        }
                        className="flex items-center gap-1.5 font-medium text-[#60716c] hover:text-[#263f3b]"
                    >
                        <Archive className="size-3" aria-hidden="true" />{' '}
                        Archive current
                    </button>
                </div>
            </div>
        </section>
    );
}
