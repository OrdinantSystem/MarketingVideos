import { Head, router } from '@inertiajs/react';
import { ArrowDown, Files, Sparkles } from 'lucide-react';
import { ArtifactCard } from '@/components/creative/artifact-card';
import { ArtifactInspector } from '@/components/creative/artifact-inspector';
import { CreativeShell } from '@/components/creative/creative-shell';
import { FeedbackPanel } from '@/components/creative/feedback-panel';
import { MarkdownPreview } from '@/components/creative/markdown-preview';
import { Button } from '@/components/ui/button';
import type {
    CreativeArtifact,
    CreativeDocument,
    CreativeInput,
    CreativePhase,
    FeedbackState,
    FileTreeNode,
    ProjectSummary,
    RunSummary,
} from '@/types/creative';

interface WorkspaceProps {
    projects: ProjectSummary[];
    project: CreativeDocument;
    run: CreativeDocument;
    runs: RunSummary[];
    phases: CreativePhase[];
    phase: CreativePhase;
    inputs: CreativeInput[];
    artifacts: CreativeArtifact[];
    activeArtifact: CreativeArtifact | null;
    feedback: FeedbackState;
    tree: FileTreeNode[];
    flash: { success?: string | null; error?: string | null };
}

function groupArtifacts(
    artifacts: CreativeArtifact[],
): Record<string, CreativeArtifact[]> {
    const groups = artifacts.reduce<Record<string, CreativeArtifact[]>>(
        (groups, artifact) => {
            (groups[artifact.slot] ??= []).push(artifact);

            return groups;
        },
        {},
    );

    return Object.fromEntries(
        Object.entries(groups)
            .sort(([left], [right]) => left.localeCompare(right))
            .map(([slot, items]) => [
                slot,
                items.sort((left, right) =>
                    (left.variant ?? '').localeCompare(right.variant ?? ''),
                ),
            ]),
    );
}

export default function Workspace({
    projects,
    project,
    run,
    runs,
    phases,
    phase,
    inputs,
    artifacts,
    activeArtifact,
    feedback,
    tree,
    flash,
}: WorkspaceProps) {
    const grouped = groupArtifacts(artifacts);
    const baseUrl = `/projects/${project.id}/runs/${run.id}/${phase.key}`;
    const isMultiShot = phase.required_count > 1;

    return (
        <CreativeShell
            projects={projects}
            project={project}
            runs={runs}
            run={run}
            phases={phases}
            tree={tree}
        >
            <Head
                title={`${phase.name} · ${String(project.metadata.name ?? 'Creative project')}`}
            />

            {(flash.success || flash.error) && (
                <div
                    className={`mb-4 rounded-lg border px-4 py-3 text-sm ${flash.error ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}
                    role="status"
                >
                    {flash.error ?? flash.success}
                </div>
            )}

            <div className="mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p className="text-[10px] font-semibold tracking-[0.15em] text-[#ce673e] uppercase">
                        Phase {phase.number}
                        {phase.optional ? ' · optional' : ''}
                    </p>
                    <h1 className="mt-1 text-2xl font-semibold tracking-[-0.035em] text-[#203a36] sm:text-3xl">
                        {phase.name}
                    </h1>
                    <p className="mt-1 text-sm text-[#77817d]">
                        {phase.short}. Inspect the files, choose what works, and
                        move it forward.
                    </p>
                </div>
                <Button
                    type="button"
                    onClick={() => router.post(`${baseUrl}/simulate`)}
                    disabled={!phase.available}
                    className="bg-[#294c47] text-white hover:bg-[#203e3a]"
                >
                    <Sparkles aria-hidden="true" />{' '}
                    {artifacts.length > 0
                        ? `Generate another ${phase.output_label}`
                        : phase.action}
                </Button>
            </div>

            <div className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_310px]">
                <div className="min-w-0 space-y-5">
                    <section className="rounded-xl border border-[#deddd6] bg-[#f8f7f3] p-4">
                        <div className="mb-3 flex items-center gap-2 text-[10px] font-semibold tracking-[0.13em] text-[#858d89] uppercase">
                            <ArrowDown
                                className="size-3.5"
                                aria-hidden="true"
                            />{' '}
                            Input to this phase
                        </div>
                        {inputs.length > 0 ? (
                            <details>
                                <summary className="cursor-pointer text-sm font-semibold text-[#304542]">
                                    {inputs.length === 1
                                        ? inputs[0].title
                                        : `${inputs.length} selected files`}{' '}
                                    <span className="ml-1 font-normal text-[#909693]">
                                        · inspect
                                    </span>
                                </summary>
                                <div className="mt-4 grid gap-3 lg:grid-cols-2">
                                    {inputs.map((input) => (
                                        <MarkdownPreview
                                            key={input.id}
                                            document={input}
                                        />
                                    ))}
                                </div>
                            </details>
                        ) : (
                            <p className="text-sm text-[#7f8884]">
                                Select an output in the previous phase first.
                            </p>
                        )}
                    </section>

                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2 text-sm font-semibold text-[#304542]">
                                <Files className="size-4" aria-hidden="true" />{' '}
                                Output files
                            </div>
                            <span className="text-[11px] text-[#8a928e]">
                                {phase.selected_count}/{phase.required_count}{' '}
                                selected
                            </span>
                        </div>
                        {artifacts.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-[#d5d3cc] bg-[#f8f7f3] px-6 py-12 text-center text-sm text-[#8a918e]">
                                No outputs yet. Start the phase when you are
                                ready.
                            </div>
                        ) : isMultiShot ? (
                            <div className="space-y-5">
                                {Object.entries(grouped).map(
                                    ([slot, slotArtifacts]) => (
                                        <div key={slot}>
                                            <h2 className="mb-2 text-xs font-semibold tracking-[0.12em] text-[#7a827f] uppercase">
                                                {slot.replace('-', ' ')}
                                            </h2>
                                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                                {slotArtifacts.map(
                                                    (artifact) => (
                                                        <ArtifactCard
                                                            key={artifact.id}
                                                            artifact={artifact}
                                                            href={`${baseUrl}?artifact=${artifact.id}`}
                                                            active={
                                                                activeArtifact?.id ===
                                                                artifact.id
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    ),
                                )}
                            </div>
                        ) : (
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {artifacts.map((artifact) => (
                                    <ArtifactCard
                                        key={artifact.id}
                                        artifact={artifact}
                                        href={`${baseUrl}?artifact=${artifact.id}`}
                                        active={
                                            activeArtifact?.id === artifact.id
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </section>

                    <ArtifactInspector
                        artifact={activeArtifact}
                        phase={phase}
                        projectId={project.id}
                        runId={run.id}
                    />
                </div>
                <aside className="space-y-4">
                    <FeedbackPanel
                        feedback={feedback}
                        projectId={project.id}
                        runId={run.id}
                        phaseKey={phase.key}
                    />
                    <div className="rounded-xl border border-[#deddd6] bg-[#eceae3] p-4 text-xs leading-5 text-[#737d79]">
                        <strong className="text-[#40514d]">
                            Prototype mode.
                        </strong>{' '}
                        Generation is simulated, but every output, selection,
                        and feedback entry is a real traceable file.
                    </div>
                </aside>
            </div>
        </CreativeShell>
    );
}
