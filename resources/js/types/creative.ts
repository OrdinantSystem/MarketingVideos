export interface DocumentSection {
    title: string;
    content: string;
}

export interface CreativeDocument {
    id: string;
    title?: string;
    path?: string;
    body: string;
    sections: DocumentSection[];
    metadata: Record<string, unknown>;
}

export interface ProjectSummary {
    id: string;
    name: string;
    updated_at: string;
    latest_run: string;
    current_phase: string;
}

export interface RunSummary {
    id: string;
    created_at: string;
    label: string;
}

export interface CreativePhase {
    key: string;
    number: string;
    name: string;
    short: string;
    optional: boolean;
    output_label: string;
    action: string;
    directory: string;
    artifact_count: number;
    selected_count: number;
    required_count: number;
    complete: boolean;
    active: boolean;
    available: boolean;
}

export interface CreativeArtifact extends CreativeDocument {
    id: string;
    title: string;
    summary: string;
    type: string;
    slot: string;
    variant: string | null;
    created_at: string;
    path: string;
    filename: string;
    preview_color: string;
    selected: boolean;
}

export interface CreativeInput extends CreativeDocument {
    title: string;
    path: string;
    slot: string;
    summary?: string;
}

export interface FeedbackState {
    current: DocumentSection[];
    cycle: DocumentSection[];
    history_count: number;
}

export interface FileTreeNode {
    name: string;
    path: string;
    type: 'directory' | 'markdown' | 'asset';
    children?: FileTreeNode[];
}
