import { router } from '@inertiajs/react';
import {
    Ban,
    BarChart3,
    Captions,
    Film,
    LoaderCircle,
    ShieldCheck,
    Volume2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';

export interface RenderRecord {
    id: string;
    path: string;
    request: Record<string, unknown> | null;
    state: Record<string, unknown> | null;
    result: Record<string, unknown> | null;
    media_url?: string | null;
    audio_url?: string | null;
    parse_error?: string;
}

interface SequenceDeliveryPanelProps {
    complete: boolean;
    projectId: string;
    runId: string;
    renders: RenderRecord[];
    performanceCount: number;
    learningCount: number;
}

const terminalStatuses = new Set([
    'completed',
    'failed',
    'cancelled',
    'timed_out',
]);

function text(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

function number(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function stringList(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((item): item is string => typeof item === 'string')
        : [];
}

export function SequenceDeliveryPanel({
    complete,
    projectId,
    runId,
    renders,
    performanceCount,
    learningCount,
}: SequenceDeliveryPanelProps) {
    const [pendingAction, setPendingAction] = useState<
        'render' | 'cancel' | 'performance' | 'learning' | null
    >(null);
    const [platform, setPlatform] = useState('');
    const [observations, setObservations] = useState('');
    const [metrics, setMetrics] = useState({
        impressions: '',
        views: '',
        completions: '',
        clicks: '',
        conversions: '',
        spend_usd: '',
        revenue_usd: '',
    });
    const latest = renders[0] ?? null;
    const status = text(latest?.state?.status) ?? 'not_started';
    const result = latest?.result ?? null;
    const running = latest !== null && !terminalStatuses.has(status);
    const completed = status === 'completed' && result !== null;
    const finalPath = text(result?.final_path);
    const finalHash = text(result?.final_sha256);
    const mediaProbe =
        result?.media_probe && typeof result.media_probe === 'object'
            ? (result.media_probe as Record<string, unknown>)
            : null;
    const duration = number(mediaProbe?.duration_seconds);
    const sceneCount = stringList(result?.scene_artifact_ids).length;
    const hasMetric = Object.values(metrics).some((value) => value !== '');

    function post(url: string, action: typeof pendingAction, data = {}) {
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setPendingAction(action),
            onFinish: () => setPendingAction(null),
        });
    }

    function submitPerformance(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!latest || platform.trim() === '' || !hasMetric) {
            return;
        }

        const submittedMetrics = Object.fromEntries(
            Object.entries(metrics)
                .filter(([, value]) => value !== '')
                .map(([key, value]) => [key, Number(value)]),
        );
        post(
            `/projects/${projectId}/runs/${runId}/renders/${latest.id}/performance`,
            'performance',
            {
                platform: platform.trim(),
                observations: observations.trim(),
                metrics: submittedMetrics,
            },
        );
    }

    return (
        <section className="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-950">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex items-start gap-3">
                    <ShieldCheck
                        className="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 className="text-sm font-semibold">
                            Local delivery and evidence
                        </h2>
                        <p className="mt-1 max-w-2xl text-xs leading-5">
                            Assemble the exact selected scenes with FFmpeg, an
                            embedded caption track, a source-hashed manifest,
                            and a quiet timing-reference tone. This remains
                            entirely local: no speech or paid media is
                            generated.
                        </p>
                    </div>
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    {running && latest ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={pendingAction !== null}
                            onClick={() =>
                                post(
                                    `/projects/${projectId}/runs/${runId}/renders/${latest.id}/cancel`,
                                    'cancel',
                                )
                            }
                        >
                            {pendingAction === 'cancel' ? (
                                <LoaderCircle
                                    className="animate-spin"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Ban aria-hidden="true" />
                            )}
                            Cancel render
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        disabled={
                            !complete || running || pendingAction !== null
                        }
                        aria-describedby={
                            complete ? undefined : 'render-blocked-reason'
                        }
                        onClick={() =>
                            post(
                                `/projects/${projectId}/runs/${runId}/render`,
                                'render',
                            )
                        }
                        className="bg-[#294c47] text-white hover:bg-[#203e3a]"
                    >
                        {pendingAction === 'render' ? (
                            <LoaderCircle
                                className="animate-spin"
                                aria-hidden="true"
                            />
                        ) : (
                            <Film aria-hidden="true" />
                        )}
                        {renders.length > 0
                            ? 'Build another local render'
                            : 'Build local simulation render'}
                    </Button>
                </div>
            </div>

            {!complete ? (
                <p
                    id="render-blocked-reason"
                    className="mt-3 text-xs text-amber-900"
                >
                    Select one valid Sequence handoff before requesting a local
                    render.
                </p>
            ) : null}

            {latest ? (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-white/70 p-3 text-xs">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-semibold">
                            Latest render · {status.replaceAll('_', ' ')}
                        </p>
                        <code className="text-[10px] text-emerald-800">
                            {latest.id}
                        </code>
                    </div>
                    {latest.parse_error ? (
                        <p className="mt-2 text-red-700">
                            {latest.parse_error}
                        </p>
                    ) : null}
                    {completed ? (
                        <div className="mt-3 space-y-3">
                            {latest.media_url ? (
                                <video
                                    src={latest.media_url}
                                    controls
                                    playsInline
                                    preload="metadata"
                                    aria-label="Verified final local render"
                                    className="max-h-[560px] w-full rounded-md bg-black"
                                />
                            ) : null}
                            {latest.audio_url ? (
                                <div className="rounded-md bg-emerald-100/70 p-2">
                                    <p className="mb-1 font-semibold">
                                        Local timing-reference audio
                                    </p>
                                    <audio
                                        src={latest.audio_url}
                                        controls
                                        preload="metadata"
                                        aria-label="Local timing-reference audio"
                                        className="w-full"
                                    />
                                    <p className="mt-1">
                                        Quiet reference tone only; no
                                        synthesized voice.
                                    </p>
                                </div>
                            ) : null}
                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                <div className="rounded-md bg-emerald-100/70 p-2">
                                    <Film
                                        className="mb-1 size-3.5"
                                        aria-hidden="true"
                                    />
                                    <strong>{sceneCount} exact scenes</strong>
                                    <p>{duration?.toFixed(2) ?? '—'} seconds</p>
                                </div>
                                <div className="rounded-md bg-emerald-100/70 p-2">
                                    <Captions
                                        className="mb-1 size-3.5"
                                        aria-hidden="true"
                                    />
                                    <strong>Caption track</strong>
                                    <p>
                                        {text(result.captions_path) ??
                                            'missing'}
                                    </p>
                                </div>
                                <div className="rounded-md bg-emerald-100/70 p-2">
                                    <Volume2
                                        className="mb-1 size-3.5"
                                        aria-hidden="true"
                                    />
                                    <strong>Timing audio only</strong>
                                    <p>No synthesized speech</p>
                                </div>
                                <div className="rounded-md bg-emerald-100/70 p-2">
                                    <ShieldCheck
                                        className="mb-1 size-3.5"
                                        aria-hidden="true"
                                    />
                                    <strong>SHA-256</strong>
                                    <p className="font-mono">
                                        {finalHash?.slice(0, 16) ?? 'missing'}…
                                    </p>
                                </div>
                                {finalPath ? (
                                    <p className="break-all sm:col-span-2 xl:col-span-4">
                                        <strong>Final:</strong>{' '}
                                        <code>{finalPath}</code>
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    ) : result?.error ? (
                        <p className="mt-2 text-red-700">
                            {text(result.error)}
                        </p>
                    ) : (
                        <p className="mt-2 text-emerald-800">
                            The immutable request is preserved while this job
                            waits for or performs local assembly.
                        </p>
                    )}
                </div>
            ) : (
                <p className="mt-3 text-xs text-emerald-800">
                    No render request exists yet. Selecting Sequence completes
                    the creative chain; rendering is a separate explicit step.
                </p>
            )}

            {completed && latest ? (
                <details className="mt-4 rounded-lg border border-emerald-200 bg-white/70 p-3">
                    <summary className="cursor-pointer text-xs font-semibold">
                        Record observed performance
                    </summary>
                    <form
                        className="mt-3 space-y-3"
                        onSubmit={submitPerformance}
                    >
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="text-[11px] font-medium lg:col-span-2">
                                Platform or campaign
                                <input
                                    value={platform}
                                    onChange={(event) =>
                                        setPlatform(event.target.value)
                                    }
                                    maxLength={80}
                                    required
                                    className="mt-1 w-full rounded-md border border-emerald-200 bg-white px-2 py-1.5 text-xs"
                                />
                            </label>
                            {Object.entries(metrics).map(([key, value]) => (
                                <label
                                    key={key}
                                    className="text-[11px] font-medium"
                                >
                                    {key.replaceAll('_', ' ')}
                                    <input
                                        type="number"
                                        min="0"
                                        step={
                                            key.endsWith('_usd') ? '0.01' : '1'
                                        }
                                        value={value}
                                        onChange={(event) =>
                                            setMetrics((current) => ({
                                                ...current,
                                                [key]: event.target.value,
                                            }))
                                        }
                                        className="mt-1 w-full rounded-md border border-emerald-200 bg-white px-2 py-1.5 text-xs"
                                    />
                                </label>
                            ))}
                        </div>
                        <label className="block text-[11px] font-medium">
                            Qualitative observation
                            <textarea
                                value={observations}
                                onChange={(event) =>
                                    setObservations(event.target.value)
                                }
                                maxLength={5000}
                                rows={3}
                                className="mt-1 w-full rounded-md border border-emerald-200 bg-white px-2 py-1.5 text-xs"
                            />
                        </label>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={
                                    pendingAction !== null ||
                                    platform.trim() === '' ||
                                    !hasMetric
                                }
                            >
                                {pendingAction === 'performance' ? (
                                    <LoaderCircle
                                        className="animate-spin"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <BarChart3 aria-hidden="true" />
                                )}
                                Store immutable evidence
                            </Button>
                            {performanceCount > 0 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={pendingAction !== null}
                                    onClick={() =>
                                        post(
                                            `/projects/${projectId}/learning`,
                                            'learning',
                                            {
                                                primary_metric:
                                                    'completion_rate',
                                            },
                                        )
                                    }
                                >
                                    {pendingAction === 'learning' ? (
                                        <LoaderCircle
                                            className="animate-spin"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <BarChart3 aria-hidden="true" />
                                    )}
                                    Build evidence ranking
                                </Button>
                            ) : null}
                            <span className="text-[10px] text-emerald-800">
                                {performanceCount} observations ·{' '}
                                {learningCount} source-hashed rankings
                            </span>
                        </div>
                    </form>
                </details>
            ) : null}
        </section>
    );
}
