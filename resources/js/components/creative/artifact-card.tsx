import { Link } from '@inertiajs/react';
import { Check, FileText, Image, Play, Video } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { CreativeArtifact } from '@/types/creative';

interface ArtifactCardProps {
    artifact: CreativeArtifact;
    href: string;
    active: boolean;
}

export function ArtifactCard({ artifact, href, active }: ArtifactCardProps) {
    const isVisual =
        artifact.type === 'image_sidecar' || artifact.type === 'video_sidecar';
    const TypeIcon =
        artifact.type === 'image_sidecar'
            ? Image
            : artifact.type === 'video_sidecar'
              ? Video
              : FileText;

    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                'group overflow-hidden rounded-xl border bg-[#fbfaf7] transition hover:border-[#aeb7b2] hover:shadow-sm',
                active
                    ? 'border-[#e0784c] ring-2 ring-[#e0784c]/10'
                    : 'border-[#deddd6]',
            )}
        >
            {isVisual && (
                <div
                    className="relative flex aspect-[16/8] items-center justify-center"
                    style={{ backgroundColor: artifact.preview_color }}
                >
                    {artifact.type === 'video_sidecar' ? (
                        <span className="grid size-9 place-items-center rounded-full bg-white/90 text-[#23413e] shadow">
                            <Play
                                className="ml-0.5 size-4 fill-current"
                                aria-hidden="true"
                            />
                        </span>
                    ) : (
                        <Image
                            className="size-6 text-white/75"
                            aria-hidden="true"
                        />
                    )}
                    <span className="absolute top-2 left-2 rounded-md bg-black/25 px-2 py-1 font-mono text-[9px] text-white">
                        {artifact.slot}
                        {artifact.variant ? ` · ${artifact.variant}` : ''}
                    </span>
                </div>
            )}
            <div className="p-3.5">
                <div className="flex items-start gap-2.5">
                    {!isVisual && (
                        <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-[#eeece5] text-[#69736f]">
                            <TypeIcon className="size-3.5" aria-hidden="true" />
                        </span>
                    )}
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <h3 className="truncate text-sm font-semibold text-[#2c403d]">
                                {artifact.title}
                            </h3>
                            {artifact.selected && (
                                <span
                                    className="grid size-4 shrink-0 place-items-center rounded-full bg-[#2f745d] text-white"
                                    title="Selected"
                                >
                                    <Check
                                        className="size-2.5"
                                        aria-hidden="true"
                                    />
                                </span>
                            )}
                        </div>
                        <p className="mt-1 line-clamp-2 text-xs leading-5 text-[#7e8783]">
                            {artifact.summary}
                        </p>
                    </div>
                </div>
                <p className="mt-3 truncate border-t border-[#e8e6df] pt-2 font-mono text-[9px] text-[#a1a6a3]">
                    {artifact.filename}
                </p>
            </div>
        </Link>
    );
}
