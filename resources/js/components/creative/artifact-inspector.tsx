import { router } from '@inertiajs/react';
import { ArrowRight, Check, FileCode2 } from 'lucide-react';
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
                    disabled={artifact.selected}
                    onClick={() => router.post(url)}
                    className="shrink-0 bg-[#df7145] text-white hover:bg-[#ca6038]"
                >
                    {artifact.selected ? (
                        <>
                            <Check aria-hidden="true" /> Selected
                        </>
                    ) : (
                        <>
                            Use in next phase <ArrowRight aria-hidden="true" />
                        </>
                    )}
                </Button>
            </header>
            <div className="creative-scrollbar max-h-[660px] overflow-auto p-4">
                <MarkdownPreview document={artifact} />
            </div>
        </section>
    );
}
