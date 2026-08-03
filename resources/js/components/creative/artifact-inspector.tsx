import { useForm } from '@inertiajs/react';
import { ArrowRight, Check, FileCode2, TriangleAlert } from 'lucide-react';
import { MarkdownPreview } from '@/components/creative/markdown-preview';
import { Button } from '@/components/ui/button';
import type { CreativeArtifact, CreativePhase } from '@/types/creative';

interface ArtifactInspectorProps {
    artifact: CreativeArtifact | null;
    phase: CreativePhase;
    projectId: string;
    runId: string;
}

export function ArtifactInspector({
    artifact,
    phase,
    projectId,
    runId,
}: ArtifactInspectorProps) {
    const selectionForm = useForm({ reason: '' });

    if (!artifact) {
        return (
            <div className="grid min-h-72 place-items-center rounded-xl border border-dashed border-[#d6d4cd] bg-[#f8f7f3] px-6 text-center">
                <div>
                    <FileCode2
                        className="mx-auto size-6 text-[#a1a6a3]"
                        aria-hidden="true"
                    />
                    <p className="mt-3 text-sm font-medium text-[#56625e]">
                        No output to inspect yet
                    </p>
                    <p className="mt-1 text-xs text-[#929895]">
                        Start this phase to create new immutable files.
                    </p>
                </div>
            </div>
        );
    }

    const url = `/projects/${projectId}/runs/${runId}/${phase.key}/artifacts/${artifact.id}/select`;

    return (
        <section className="rounded-xl border border-[#deddd6] bg-[#fbfaf7]">
            <header className="flex flex-col gap-3 border-b border-[#e3e1da] p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <p className="font-mono text-[9px] text-[#9aa09d]">
                        {artifact.path}
                    </p>
                    <h2 className="mt-1 truncate text-base font-semibold text-[#263e3b]">
                        {artifact.title}
                    </h2>
                </div>
                <Button
                    type="button"
                    size="sm"
                    disabled={
                        artifact.selected ||
                        !artifact.validation.valid ||
                        selectionForm.processing
                    }
                    onClick={() =>
                        selectionForm.post(url, { preserveScroll: true })
                    }
                    className="shrink-0 bg-[#df7145] text-white hover:bg-[#ca6038]"
                >
                    {!artifact.validation.valid ? (
                        <>
                            <TriangleAlert aria-hidden="true" /> Not selectable
                        </>
                    ) : artifact.selected ? (
                        <>
                            <Check aria-hidden="true" /> Selected
                        </>
                    ) : (
                        <>
                            {phase.key === 'sequence'
                                ? 'Select editor handoff'
                                : 'Use in next phase'}{' '}
                            <ArrowRight aria-hidden="true" />
                        </>
                    )}
                </Button>
            </header>
            <div className="creative-scrollbar max-h-[660px] overflow-auto p-4">
                {(artifact.validation.errors.length > 0 ||
                    artifact.validation.warnings.length > 0) && (
                    <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        <div className="flex items-center gap-1.5 font-semibold">
                            <TriangleAlert
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            Artifact integrity
                        </div>
                        {[
                            ...artifact.validation.errors,
                            ...artifact.validation.warnings,
                        ].map((message) => (
                            <p key={message} className="mt-1">
                                {message}
                            </p>
                        ))}
                    </div>
                )}
                {artifact.selection ? (
                    <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs leading-5 text-emerald-950">
                        <div className="font-semibold">Effective selection</div>
                        <p className="mt-1">
                            Selected by {artifact.selection.selected_by} on{' '}
                            {artifact.selection.created_at}.
                        </p>
                        <p className="mt-1">
                            <strong>Reason:</strong>{' '}
                            {artifact.selection.reason ??
                                'No additional reason was recorded.'}
                        </p>
                        {artifact.selection.evaluation_id ? (
                            <p className="mt-1 font-mono text-[10px] break-all">
                                Evaluation: {artifact.selection.evaluation_id}
                            </p>
                        ) : null}
                    </div>
                ) : artifact.validation.valid ? (
                    <label className="mb-4 block rounded-lg border border-[#dfddd6] bg-[#f5f4ef] p-3">
                        <span className="text-xs font-semibold text-[#445550]">
                            Selection reason{' '}
                            <span className="font-normal text-[#8b928f]">
                                · optional but recommended
                            </span>
                        </span>
                        <textarea
                            value={selectionForm.data.reason}
                            onChange={(event) =>
                                selectionForm.setData(
                                    'reason',
                                    event.target.value,
                                )
                            }
                            maxLength={1000}
                            rows={2}
                            placeholder="Why should this exact artifact move forward?"
                            className="mt-2 w-full resize-y rounded-md border border-[#d8d7d0] bg-white px-3 py-2 text-xs leading-5 outline-none placeholder:text-[#a5aaa7] focus:border-[#8fa59e]"
                        />
                        {selectionForm.errors.reason ? (
                            <span className="mt-1 block text-xs text-red-600">
                                {selectionForm.errors.reason}
                            </span>
                        ) : null}
                    </label>
                ) : null}
                {artifact.asset_status === 'ready' && artifact.asset_url ? (
                    <div className="mb-4 overflow-hidden rounded-lg border border-[#dfddd6] bg-black">
                        {artifact.type === 'image_sidecar' ? (
                            <img
                                src={artifact.asset_url}
                                alt={`Verified preview of ${artifact.title}`}
                                className="max-h-[520px] w-full object-contain"
                            />
                        ) : artifact.type === 'video_sidecar' ? (
                            <video
                                src={artifact.asset_url}
                                controls
                                playsInline
                                preload="metadata"
                                aria-label={`Verified preview of ${artifact.title}`}
                                className="max-h-[520px] w-full"
                            />
                        ) : null}
                    </div>
                ) : null}
                <MarkdownPreview document={artifact} />
            </div>
        </section>
    );
}
