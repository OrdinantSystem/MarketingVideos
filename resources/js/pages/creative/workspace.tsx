import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    Files,
    LoaderCircle,
    ShieldCheck,
    SkipForward,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { ArtifactBatches } from '@/components/creative/artifact-batches';
import { ArtifactInspector } from '@/components/creative/artifact-inspector';
import { AutomationPanel } from '@/components/creative/automation-panel';
import { CreativeShell } from '@/components/creative/creative-shell';
import { FeedbackPanel } from '@/components/creative/feedback-panel';
import { IntegrityPanel } from '@/components/creative/integrity-panel';
import { MarkdownPreview } from '@/components/creative/markdown-preview';
import { PhaseRecoveryNotice } from '@/components/creative/phase-recovery-notice';
import { SequenceDeliveryPanel } from '@/components/creative/sequence-delivery-panel';
import { TraceabilityPanel } from '@/components/creative/traceability-panel';
import { Button } from '@/components/ui/button';
import type {
    AutomationStatus,
    ConceptSource,
    CreativeArtifact,
    CreativeDocument,
    CreativeInput,
    CreativePhase,
    FeedbackState,
    FileTreeNode,
    GenerationState,
    IntegrityState,
    ProjectSummary,
    RunSummary,
    ShotDetail,
    TraceabilityState,
} from '@/types/creative';
import type { RenderRecord } from '@/components/creative/sequence-delivery-panel';

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
    generation: GenerationState;
    automation: AutomationStatus | null;
    integrity: IntegrityState;
    traceability: TraceabilityState;
    shots: Record<string, ShotDetail>;
    concept_source: ConceptSource | null;
    renders: RenderRecord[];
    performance_count: number;
    learning_count: number;
    tree: FileTreeNode[];
    flash: { success?: string | null; error?: string | null };
}

const nonterminalWorkStatuses = new Set(['pending', 'running']);
const statusReloadIntervalMs = 2_500;
const maximumStatusReloads = 120;

function recordStatus(value: Record<string, unknown> | null): string | null {
    const status = value?.status;

    return typeof status === 'string' ? status : null;
}

function ConceptSourceNotice({
    source,
    projectId,
    runId,
}: {
    source: ConceptSource;
    projectId: string;
    runId: string;
}) {
    if (!source.explicit) {
        return (
            <div className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950">
                <strong>Ideas source not decided</strong>
                <p>
                    Concepts cannot generate until you select an Ideas artifact
                    or explicitly skip Ideas and use project.md.
                </p>
                <Link
                    href={`/projects/${projectId}/runs/${runId}/ideas`}
                    className="mt-1 inline-flex font-semibold underline underline-offset-2"
                >
                    Decide the Ideas source
                </Link>
            </div>
        );
    }

    return (
        <div
            className={`mb-3 rounded-lg border px-3 py-2 text-xs leading-5 ${source.mode === 'selected_idea' ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-sky-200 bg-sky-50 text-sky-950'}`}
        >
            <strong>Concepts source: {source.source_title}</strong>
            <p>
                {source.mode === 'selected_idea'
                    ? 'This exact selected Ideas artifact supplies the Concepts lineage.'
                    : 'Ideas was explicitly skipped, so project.md supplies the Concepts lineage.'}
            </p>
            {source.reason ? (
                <p className="mt-1">
                    <span className="font-semibold">Reason:</span>{' '}
                    {source.reason}
                </p>
            ) : null}
        </div>
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
    generation,
    automation,
    integrity,
    traceability,
    shots,
    concept_source: conceptSource,
    renders,
    performance_count: performanceCount,
    learning_count: learningCount,
    tree,
    flash,
}: WorkspaceProps) {
    const [pendingAction, setPendingAction] = useState<
        'generate' | 'skip-ideas' | null
    >(null);
    const baseUrl = `/projects/${project.id}/runs/${run.id}/${phase.key}`;
    const generationDisabledReason = !phase.available
        ? 'Resolve the blocked or stale input before generating.'
        : generation.effective_driver === 'unavailable'
          ? 'The configured generation driver is unavailable for this phase.'
          : null;
    const hasNonterminalWork =
        nonterminalWorkStatuses.has(automation?.state?.status ?? '') ||
        renders.some((render) =>
            nonterminalWorkStatuses.has(recordStatus(render.state) ?? ''),
        ) ||
        traceability.media_jobs.some((job) =>
            nonterminalWorkStatuses.has(recordStatus(job.state) ?? ''),
        );

    useEffect(() => {
        if (!hasNonterminalWork) {
            return;
        }

        let disposed = false;
        let reloadCount = 0;
        let timer: ReturnType<typeof setTimeout> | null = null;

        function scheduleReload() {
            if (disposed || reloadCount >= maximumStatusReloads) {
                return;
            }

            timer = setTimeout(() => {
                if (disposed) {
                    return;
                }

                reloadCount += 1;
                router.reload({
                    onFinish: scheduleReload,
                });
            }, statusReloadIntervalMs);
        }

        scheduleReload();

        return () => {
            disposed = true;

            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, [hasNonterminalWork, phase.key, project.id, run.id]);

    function postAction(url: string, action: 'generate' | 'skip-ideas') {
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setPendingAction(action),
                onFinish: () => setPendingAction(null),
            },
        );
    }

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

            {flash.success || flash.error ? (
                <div
                    className={`mb-4 rounded-lg border px-4 py-3 text-sm ${flash.error ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}
                    role="status"
                >
                    {flash.error ?? flash.success}
                </div>
            ) : null}

            {hasNonterminalWork ? (
                <p
                    role="status"
                    aria-live="polite"
                    className="mb-3 text-[10px] text-[#77817d]"
                >
                    Refreshing local status while automation, media, or render
                    work is pending or running.
                </p>
            ) : null}

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
                    <div
                        className={`mt-2 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold ${generation.external ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'}`}
                    >
                        {generation.external ? (
                            <TriangleAlert
                                className="size-3"
                                aria-hidden="true"
                            />
                        ) : (
                            <ShieldCheck
                                className="size-3"
                                aria-hidden="true"
                            />
                        )}
                        {generation.label} · {generation.cost}
                    </div>
                </div>
                <div className="flex flex-col items-stretch gap-2 sm:items-end">
                    <div className="flex flex-wrap gap-2">
                        {phase.key === 'ideas' ? (
                            phase.status === 'skipped' ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={`/projects/${project.id}/runs/${run.id}/concepts`}
                                    >
                                        <SkipForward aria-hidden="true" />
                                        Continue to Concepts
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={pendingAction !== null}
                                    onClick={() =>
                                        postAction(
                                            `${baseUrl}/skip`,
                                            'skip-ideas',
                                        )
                                    }
                                >
                                    {pendingAction === 'skip-ideas' ? (
                                        <LoaderCircle
                                            className="animate-spin"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <SkipForward aria-hidden="true" />
                                    )}
                                    Skip Ideas
                                </Button>
                            )
                        ) : null}
                        <Button
                            type="button"
                            onClick={() =>
                                postAction(`${baseUrl}/generate`, 'generate')
                            }
                            disabled={
                                generationDisabledReason !== null ||
                                pendingAction !== null
                            }
                            aria-describedby={
                                generationDisabledReason
                                    ? 'generation-disabled-reason'
                                    : undefined
                            }
                            className="bg-[#294c47] text-white hover:bg-[#203e3a]"
                        >
                            {pendingAction === 'generate' ? (
                                <LoaderCircle
                                    className="animate-spin"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Sparkles aria-hidden="true" />
                            )}
                            {pendingAction === 'generate'
                                ? 'Generating…'
                                : artifacts.length > 0
                                  ? `Generate another ${phase.output_label}`
                                  : phase.action}
                        </Button>
                    </div>
                    {generationDisabledReason ? (
                        <p
                            id="generation-disabled-reason"
                            className="max-w-sm text-xs text-amber-800"
                        >
                            {generationDisabledReason}
                        </p>
                    ) : null}
                    {phase.key === 'ideas' && phase.status !== 'skipped' ? (
                        <p className="max-w-sm text-right text-[10px] leading-4 text-[#858d89]">
                            Skip records an immutable decision to use
                            project.md; existing Ideas files remain unchanged.
                        </p>
                    ) : null}
                </div>
            </div>

            <PhaseRecoveryNotice
                phase={phase}
                phases={phases}
                integrity={integrity}
                projectId={project.id}
                runId={run.id}
            />

            {phase.key === 'sequence' ? (
                <SequenceDeliveryPanel
                    complete={phase.complete}
                    projectId={project.id}
                    runId={run.id}
                    renders={renders}
                    performanceCount={performanceCount}
                    learningCount={learningCount}
                />
            ) : null}

            <div className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_330px]">
                <div className="min-w-0 space-y-5">
                    <section className="rounded-xl border border-[#deddd6] bg-[#f8f7f3] p-4">
                        <div className="mb-3 flex items-center gap-2 text-[10px] font-semibold tracking-[0.13em] text-[#858d89] uppercase">
                            <ArrowDown
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            Input to this phase
                        </div>
                        {conceptSource ? (
                            <ConceptSourceNotice
                                source={conceptSource}
                                projectId={project.id}
                                runId={run.id}
                            />
                        ) : null}
                        {inputs.length > 0 ? (
                            <details>
                                <summary className="cursor-pointer text-sm font-semibold text-[#304542]">
                                    {inputs.length === 1
                                        ? inputs[0].title
                                        : `${inputs.length} selected files`}{' '}
                                    <span className="ml-1 font-normal text-[#909693]">
                                        · inspect provenance
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
                                This phase has no valid effective input. Use the
                                recovery action above to repair the previous
                                selection.
                            </p>
                        )}
                    </section>

                    <section aria-labelledby="outputs-heading">
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2 text-sm font-semibold text-[#304542]">
                                <Files className="size-4" aria-hidden="true" />
                                <h2 id="outputs-heading">Output files</h2>
                            </div>
                            <span className="text-[11px] text-[#8a928e]">
                                {phase.status === 'skipped'
                                    ? 'Ideas explicitly skipped'
                                    : `${phase.selected_count}/${phase.required_count} selected`}
                            </span>
                        </div>
                        {artifacts.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-[#d5d3cc] bg-[#f8f7f3] px-6 py-12 text-center text-sm text-[#8a918e]">
                                No outputs yet. Start the phase when you are
                                ready.
                            </div>
                        ) : (
                            <ArtifactBatches
                                artifacts={artifacts}
                                phase={phase}
                                shots={shots}
                                traceability={traceability}
                                baseUrl={baseUrl}
                                activeArtifactId={activeArtifact?.id ?? null}
                            />
                        )}
                    </section>

                    <ArtifactInspector
                        key={activeArtifact?.id ?? 'no-artifact'}
                        artifact={activeArtifact}
                        phase={phase}
                        projectId={project.id}
                        runId={run.id}
                    />

                    <TraceabilityPanel traceability={traceability} />
                </div>
                <aside className="space-y-4">
                    <AutomationPanel
                        automation={automation}
                        projectId={project.id}
                        runId={run.id}
                        phaseKey={phase.key}
                    />
                    <IntegrityPanel
                        integrity={integrity}
                        activePhase={phase.key}
                    />
                    <FeedbackPanel
                        feedback={feedback}
                        projectId={project.id}
                        runId={run.id}
                        phaseKey={phase.key}
                    />
                    <div
                        className={`rounded-xl border p-4 text-xs leading-5 ${generation.external ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-[#deddd6] bg-[#eceae3] text-[#737d79]'}`}
                    >
                        <strong
                            className={
                                generation.external
                                    ? undefined
                                    : 'text-[#40514d]'
                            }
                        >
                            {generation.label} mode.
                        </strong>{' '}
                        {generation.description} Every output and selection is
                        still written as a traceable project file.
                    </div>
                </aside>
            </div>
        </CreativeShell>
    );
}
