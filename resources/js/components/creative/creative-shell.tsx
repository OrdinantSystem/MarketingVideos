import { Link, router } from '@inertiajs/react';
import {
    Aperture,
    ChevronDown,
    FileCode2,
    Plus,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { FileTree } from '@/components/creative/file-tree';
import { PhaseRail } from '@/components/creative/phase-rail';
import { Button } from '@/components/ui/button';
import type {
    CreativeDocument,
    CreativePhase,
    FileTreeNode,
    ProjectSummary,
    RunSummary,
} from '@/types/creative';

interface CreativeShellProps extends PropsWithChildren {
    projects: ProjectSummary[];
    project: CreativeDocument;
    runs: RunSummary[];
    run: CreativeDocument;
    phases: CreativePhase[];
    tree: FileTreeNode[];
}

export function CreativeShell({
    projects,
    project,
    runs,
    run,
    phases,
    tree,
    children,
}: CreativeShellProps) {
    return (
        <div className="min-h-screen bg-[#f2f1ec] text-[#203532]">
            <header className="sticky top-0 z-30 border-b border-[#deddd7] bg-[#fbfaf7]/95 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-[1700px] items-center gap-3 px-4 lg:px-7">
                    <Link
                        href="/"
                        className="flex shrink-0 items-center gap-2.5"
                        aria-label="VideoAuto home"
                    >
                        <span className="grid size-8 place-items-center rounded-lg bg-[#173a37] text-white">
                            <Aperture className="size-4" aria-hidden="true" />
                        </span>
                        <span className="hidden text-sm font-semibold tracking-tight sm:block">
                            VideoAuto
                        </span>
                    </Link>
                    <span
                        className="h-6 w-px bg-[#dfded8]"
                        aria-hidden="true"
                    />

                    <label className="relative max-w-64 min-w-0 flex-1">
                        <span className="sr-only">Current project</span>
                        <select
                            value={project.id}
                            onChange={(event) => {
                                const selected = projects.find(
                                    (item) => item.id === event.target.value,
                                );

                                if (selected) {
                                    router.visit(
                                        `/projects/${selected.id}/runs/${selected.latest_run}/${selected.current_phase}`,
                                    );
                                }
                            }}
                            className="w-full appearance-none truncate rounded-lg border border-transparent bg-transparent py-2 pr-7 pl-2 text-sm font-medium outline-none hover:border-[#d8d7d0] focus:border-[#9aaca7]"
                        >
                            {projects.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                        <ChevronDown
                            className="pointer-events-none absolute top-1/2 right-2 size-3.5 -translate-y-1/2 text-[#89918e]"
                            aria-hidden="true"
                        />
                    </label>

                    <label className="relative hidden sm:block">
                        <span className="sr-only">Current creative run</span>
                        <select
                            value={run.id}
                            onChange={(event) =>
                                router.visit(
                                    `/projects/${project.id}/runs/${event.target.value}/${phases.find((item) => item.active)?.key ?? 'concepts'}`,
                                )
                            }
                            className="appearance-none rounded-lg border border-[#deddd7] bg-white py-2 pr-8 pl-3 text-xs text-[#65706c] outline-none focus:border-[#9aaca7]"
                        >
                            {runs.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                        <ChevronDown
                            className="pointer-events-none absolute top-1/2 right-2.5 size-3 -translate-y-1/2 text-[#89918e]"
                            aria-hidden="true"
                        />
                    </label>

                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={() =>
                            router.post(`/projects/${project.id}/runs`)
                        }
                        className="size-8 shrink-0 text-[#74807c]"
                        title="Start a new run"
                        aria-label="Start a new run"
                    >
                        <RotateCcw className="size-3.5" aria-hidden="true" />
                    </Button>

                    <span
                        className="flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-800"
                        title="Creative routes accept requests only from this machine."
                    >
                        <ShieldCheck className="size-3" aria-hidden="true" />
                        <span className="hidden md:inline">Local-only</span>
                    </span>

                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="ml-auto shrink-0 bg-white"
                    >
                        <Link href="/projects/new">
                            <Plus aria-hidden="true" />{' '}
                            <span className="hidden sm:inline">
                                New project
                            </span>
                        </Link>
                    </Button>
                </div>
            </header>

            <PhaseRail phases={phases} projectId={project.id} runId={run.id} />

            <div className="mx-auto grid max-w-[1700px] gap-5 px-4 py-5 lg:px-7 xl:grid-cols-[220px_minmax(0,1fr)]">
                <aside className="hidden xl:block">
                    <div className="sticky top-[138px] rounded-xl border border-[#deddd6] bg-[#faf9f6] p-3">
                        <div className="mb-3 flex items-center gap-2 px-1 text-[10px] font-semibold tracking-[0.13em] text-[#8b928f] uppercase">
                            <FileCode2
                                className="size-3.5"
                                aria-hidden="true"
                            />{' '}
                            Project files
                        </div>
                        <div className="creative-scrollbar max-h-[calc(100vh-190px)] overflow-auto">
                            <FileTree nodes={tree} />
                        </div>
                        <p className="mt-3 border-t border-[#e5e3dc] px-1 pt-3 font-mono text-[9px] leading-4 text-[#a0a5a2]">
                            creative-projects/{project.id}/
                        </p>
                    </div>
                </aside>
                <main className="min-w-0">{children}</main>
            </div>
        </div>
    );
}
