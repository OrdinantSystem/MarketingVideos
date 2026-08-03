import { Clock3, Layers3, TriangleAlert } from 'lucide-react';
import { ArtifactCard } from '@/components/creative/artifact-card';
import type {
    CreativeArtifact,
    CreativePhase,
    GenerationAttempt,
    ShotDetail,
    TraceDocument,
    TraceabilityState,
} from '@/types/creative';

interface ArtifactBatchesProps {
    artifacts: CreativeArtifact[];
    phase: CreativePhase;
    shots: Record<string, ShotDetail>;
    traceability: TraceabilityState;
    baseUrl: string;
    activeArtifactId: string | null;
}

interface ArtifactBatch {
    id: string;
    artifacts: CreativeArtifact[];
    attempt: GenerationAttempt | null;
    evaluation: TraceDocument | null;
    createdAt: string;
    generator: string;
}

function stringMetadata(
    metadata: Record<string, unknown>,
    key: string,
): string | null {
    const value = metadata[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

function formatTimestamp(value: string): string {
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value || 'time not recorded'
        : parsed.toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}

function buildBatches(
    artifacts: CreativeArtifact[],
    traceability: TraceabilityState,
): ArtifactBatch[] {
    const attempts = new Map(
        traceability.attempts.map((attempt) => [attempt.id, attempt]),
    );
    const evaluations = new Map<string, TraceDocument>();
    traceability.evaluations.forEach((evaluation) => {
        const attemptId = stringMetadata(evaluation.metadata, 'attempt_id');

        if (attemptId) {
            evaluations.set(attemptId, evaluation);
        }
    });

    const grouped = new Map<string, CreativeArtifact[]>();
    artifacts.forEach((artifact) => {
        const attemptId =
            stringMetadata(artifact.metadata, 'generation_attempt_id') ??
            `untracked-${artifact.id}`;
        const items = grouped.get(attemptId) ?? [];
        items.push(artifact);
        grouped.set(attemptId, items);
    });

    return Array.from(grouped, ([id, items]) => {
        const attempt = attempts.get(id) ?? null;
        const first = items[0];

        return {
            id,
            artifacts: items.toSorted((left, right) =>
                (left.variant ?? '').localeCompare(right.variant ?? ''),
            ),
            attempt,
            evaluation: evaluations.get(id) ?? null,
            createdAt: attempt?.started_at ?? first.created_at,
            generator:
                attempt?.driver ??
                stringMetadata(first.metadata, 'generator') ??
                'untracked',
        };
    }).toSorted((left, right) => right.createdAt.localeCompare(left.createdAt));
}

function ShotHeading({
    slot,
    shot,
    stale,
    order,
}: {
    slot: string;
    shot?: ShotDetail;
    stale: boolean;
    order: number;
}) {
    return (
        <div
            className={`mb-2 rounded-lg border px-3 py-2 ${stale ? 'border-amber-200 bg-amber-50' : 'border-[#dfddd6] bg-[#f4f2ec]'}`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-xs font-semibold tracking-[0.1em] text-[#596460] uppercase">
                    Shot {order} · {slot.replaceAll('-', ' ')}
                </h4>
                {shot ? (
                    <span className="rounded-full bg-white px-2 py-0.5 font-mono text-[9px] text-[#6d7874]">
                        {shot.start_seconds.toFixed(1)}–
                        {shot.end_seconds.toFixed(1)}s
                    </span>
                ) : null}
                {stale ? (
                    <span className="flex items-center gap-1 text-[10px] font-semibold text-amber-800">
                        <TriangleAlert className="size-3" aria-hidden="true" />
                        Stale slot from an older script
                    </span>
                ) : null}
            </div>
            {shot ? (
                <div className="mt-1 text-[11px] leading-5 text-[#6f7975]">
                    <p>
                        <strong className="text-[#4d5c58]">Purpose:</strong>{' '}
                        {shot.purpose}
                    </p>
                    <p>
                        <strong className="text-[#4d5c58]">Visual:</strong>{' '}
                        {shot.visual}
                    </p>
                </div>
            ) : null}
        </div>
    );
}

function BatchArtifacts({
    batch,
    phase,
    shots,
    baseUrl,
    activeArtifactId,
}: {
    batch: ArtifactBatch;
    phase: CreativePhase;
    shots: Record<string, ShotDetail>;
    baseUrl: string;
    activeArtifactId: string | null;
}) {
    if (phase.selection_mode === 'single') {
        return (
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {batch.artifacts.map((artifact) => (
                    <ArtifactCard
                        key={artifact.id}
                        artifact={artifact}
                        href={`${baseUrl}?artifact=${artifact.id}`}
                        active={activeArtifactId === artifact.id}
                    />
                ))}
            </div>
        );
    }

    const bySlot = new Map<string, CreativeArtifact[]>();
    batch.artifacts.forEach((artifact) => {
        const items = bySlot.get(artifact.slot) ?? [];
        items.push(artifact);
        bySlot.set(artifact.slot, items);
    });
    const extraSlots = Array.from(bySlot.keys()).filter(
        (slot) => !phase.required_slots.includes(slot),
    );
    const displayedSlots = [...phase.required_slots, ...extraSlots];

    return (
        <div className="space-y-4">
            {displayedSlots.map((slot, slotIndex) => {
                const slotArtifacts = bySlot.get(slot) ?? [];
                const stale = !phase.required_slots.includes(slot);

                return (
                    <section key={slot}>
                        <ShotHeading
                            slot={slot}
                            shot={shots[slot]}
                            stale={stale}
                            order={slotIndex + 1}
                        />
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {slotArtifacts.length > 0 ? (
                                slotArtifacts.map((artifact) => (
                                    <ArtifactCard
                                        key={artifact.id}
                                        artifact={artifact}
                                        href={`${baseUrl}?artifact=${artifact.id}`}
                                        active={
                                            activeArtifactId === artifact.id
                                        }
                                    />
                                ))
                            ) : (
                                <p className="rounded-lg border border-dashed border-[#d5d3cc] px-3 py-4 text-xs text-[#8a918e]">
                                    This attempt produced no candidate for the
                                    required shot.
                                </p>
                            )}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}

export function ArtifactBatches({
    artifacts,
    phase,
    shots,
    traceability,
    baseUrl,
    activeArtifactId,
}: ArtifactBatchesProps) {
    const batches = buildBatches(artifacts, traceability);

    return (
        <div className="space-y-5">
            {batches.map((batch) => {
                const evaluationPassed = batch.evaluation?.metadata.passed;
                const diagnosis = batch.evaluation?.metadata.diagnosis;

                return (
                    <section
                        key={batch.id}
                        className="rounded-xl border border-[#dcdad3] bg-[#f8f7f3] p-3 sm:p-4"
                    >
                        <header className="mb-4 flex flex-col gap-2 border-b border-[#e2e0d9] pb-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="flex items-center gap-1.5 text-xs font-semibold text-[#304542]">
                                        <Layers3
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Generation attempt
                                    </h3>
                                    <span className="rounded-full bg-white px-2 py-0.5 text-[9px] font-semibold text-[#66716d]">
                                        {batch.generator}
                                    </span>
                                    <span className="rounded-full bg-white px-2 py-0.5 text-[9px] font-semibold text-[#66716d]">
                                        {batch.attempt?.status ?? 'untracked'}
                                    </span>
                                    {typeof evaluationPassed === 'boolean' ? (
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[9px] font-semibold ${evaluationPassed ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'}`}
                                        >
                                            evaluation{' '}
                                            {evaluationPassed
                                                ? 'passed'
                                                : 'not passed'}
                                        </span>
                                    ) : null}
                                </div>
                                <p className="mt-1 truncate font-mono text-[9px] text-[#929895]">
                                    {batch.id}
                                </p>
                                {typeof diagnosis === 'string' && diagnosis ? (
                                    <p className="mt-1 text-[11px] leading-5 text-[#6e7874]">
                                        {diagnosis}
                                    </p>
                                ) : null}
                            </div>
                            <span className="flex shrink-0 items-center gap-1 text-[10px] text-[#858d89]">
                                <Clock3 className="size-3" aria-hidden="true" />
                                {formatTimestamp(batch.createdAt)}
                            </span>
                        </header>
                        <BatchArtifacts
                            batch={batch}
                            phase={phase}
                            shots={shots}
                            baseUrl={baseUrl}
                            activeArtifactId={activeArtifactId}
                        />
                    </section>
                );
            })}
        </div>
    );
}
