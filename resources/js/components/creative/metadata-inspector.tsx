import type { ReactNode } from 'react';

interface MetadataInspectorProps {
    metadata: Record<string, unknown>;
    emptyMessage?: string;
}

const preferredKeys = [
    'id',
    'type',
    'artifact_type',
    'phase',
    'slot',
    'status',
    'generator',
    'generation_attempt_id',
    'media_job_id',
    'prompt_version',
    'source_artifact_ids',
    'selected_by',
    'evaluation_id',
    'reason',
    'created_at',
    'cost_usd',
    'usage',
    'settings',
];

function metadataEntries(metadata: Record<string, unknown>) {
    const rank = new Map(preferredKeys.map((key, index) => [key, index]));

    return Object.entries(metadata).toSorted(([left], [right]) => {
        const leftRank = rank.get(left) ?? preferredKeys.length;
        const rightRank = rank.get(right) ?? preferredKeys.length;

        return leftRank === rightRank
            ? left.localeCompare(right)
            : leftRank - rightRank;
    });
}

function renderValue(value: unknown): ReactNode {
    if (value === null || value === undefined || value === '') {
        return <span className="text-[#a0a5a2]">not recorded</span>;
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return (
        <pre className="creative-scrollbar max-h-56 overflow-auto whitespace-pre-wrap">
            {JSON.stringify(value, null, 2)}
        </pre>
    );
}

export function MetadataInspector({
    metadata,
    emptyMessage = 'No structured front matter was found.',
}: MetadataInspectorProps) {
    const entries = metadataEntries(metadata);

    if (entries.length === 0) {
        return <p className="text-xs text-[#8a918e]">{emptyMessage}</p>;
    }

    return (
        <dl className="divide-y divide-[#e5e3dc] rounded-lg border border-[#e1dfd8] bg-[#f8f7f3] font-mono text-[10px] leading-5">
            {entries.map(([key, value]) => (
                <div
                    key={key}
                    className="grid gap-1 px-3 py-2 sm:grid-cols-[150px_minmax(0,1fr)]"
                >
                    <dt className="font-semibold break-all text-[#69736f]">
                        {key}
                    </dt>
                    <dd className="min-w-0 break-words text-[#43514e]">
                        {renderValue(value)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
