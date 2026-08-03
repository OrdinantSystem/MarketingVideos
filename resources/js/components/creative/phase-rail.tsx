import { Link } from '@inertiajs/react';
import {
    Check,
    Circle,
    LockKeyhole,
    SkipForward,
    TriangleAlert,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { CreativePhase } from '@/types/creative';

interface PhaseRailProps {
    phases: CreativePhase[];
    projectId: string;
    runId: string;
}

export function PhaseRail({ phases, projectId, runId }: PhaseRailProps) {
    return (
        <nav
            aria-label="Creative production phases"
            className="creative-scrollbar overflow-x-auto border-b border-[#deddd7] bg-[#f8f7f3]"
        >
            <div className="mx-auto flex max-w-[1700px] min-w-max items-stretch px-4 lg:px-7">
                {phases.map((phase) => (
                    <Link
                        key={phase.key}
                        href={`/projects/${projectId}/runs/${runId}/${phase.key}`}
                        className={cn(
                            'group relative flex min-w-36 items-center gap-2.5 border-b-2 px-3 py-3.5 transition md:min-w-44',
                            phase.active
                                ? 'border-[#e37245] bg-white text-[#1e3533]'
                                : 'border-transparent text-[#7b8480] hover:bg-white/70 hover:text-[#334744]',
                        )}
                    >
                        <span
                            className={cn(
                                'grid size-6 shrink-0 place-items-center rounded-full border text-[10px] font-semibold',
                                phase.complete
                                    ? 'border-[#2f745d] bg-[#2f745d] text-white'
                                    : phase.active
                                      ? 'border-[#e37245] bg-[#fff1e9] text-[#cf6338]'
                                      : 'border-[#d6d6cf] bg-white text-[#858d89]',
                            )}
                        >
                            {phase.status === 'skipped' ? (
                                <SkipForward
                                    className="size-3"
                                    aria-hidden="true"
                                />
                            ) : phase.complete ? (
                                <Check className="size-3" aria-hidden="true" />
                            ) : phase.status === 'stale' ? (
                                <TriangleAlert
                                    className="size-3"
                                    aria-hidden="true"
                                />
                            ) : phase.available ? (
                                phase.number
                            ) : (
                                <LockKeyhole
                                    className="size-2.5"
                                    aria-hidden="true"
                                />
                            )}
                        </span>
                        <span>
                            <span className="block text-xs font-semibold">
                                {phase.name}
                            </span>
                            <span className="mt-0.5 flex items-center gap-1 text-[10px] text-[#9a9f9c]">
                                {phase.optional && (
                                    <Circle
                                        className="size-2 fill-current"
                                        aria-hidden="true"
                                    />
                                )}
                                {phase.status === 'skipped'
                                    ? 'Skipped · project idea used'
                                    : phase.complete
                                      ? 'Selected'
                                      : phase.status === 'stale'
                                        ? `${phase.selection_issue_count} stale or invalid`
                                        : phase.selected_count > 0
                                          ? `${phase.selected_count}/${phase.required_count} selected`
                                          : phase.artifact_count > 0
                                            ? `${phase.artifact_count} files`
                                            : phase.status === 'blocked'
                                              ? 'Needs previous selection'
                                              : phase.optional
                                                ? 'Optional'
                                                : phase.short}
                            </span>
                        </span>
                    </Link>
                ))}
            </div>
        </nav>
    );
}
