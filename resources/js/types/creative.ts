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
    selection_mode: 'single' | 'script_shots';
    required_slots: string[];
    directory: string;
    artifact_count: number;
    selected_count: number;
    required_count: number;
    complete: boolean;
    active: boolean;
    available: boolean;
    selection_issue_count: number;
    status: 'ready' | 'blocked' | 'stale' | 'complete' | 'skipped';
}

export interface CreativeSelection {
    id: string;
    type: 'selection';
    phase: string;
    slot: string;
    artifact_id: string;
    artifact_path?: string;
    selected_by: 'human' | 'automation';
    evaluation_id: string | null;
    reason: string | null;
    created_at: string;
    sequence?: number;
}

export interface ArtifactValidation {
    valid: boolean;
    errors: string[];
    warnings: string[];
    schema_version: number | null;
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
    asset_status: string | null;
    asset_url: string | null;
    selected: boolean;
    selection_invalid: boolean;
    selection: CreativeSelection | null;
    invalid_selection: CreativeSelection | null;
    validation: ArtifactValidation;
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
    history: TraceDocument[];
    history_count: number;
}

export interface ShotDetail {
    id: string;
    start_seconds: number;
    end_seconds: number;
    purpose: string;
    visual: string;
}

export interface ConceptSource {
    mode: 'selected_idea' | 'project_idea';
    source_id: string;
    source_title: string;
    explicit: boolean;
    skipped: boolean;
    reason: string | null;
    decision: Record<string, unknown> | null;
}

export interface FileTreeNode {
    name: string;
    path: string;
    type: 'directory' | 'markdown' | 'asset';
    children?: FileTreeNode[];
}

export interface GenerationState {
    configured_driver: string;
    effective_driver: 'simulation' | 'openrouter' | 'unavailable';
    label: string;
    external: boolean;
    cost: string;
    description: string;
}

export interface AutomationRunState {
    automation_id: string;
    status:
        | 'pending'
        | 'running'
        | 'awaiting_review'
        | 'failed'
        | 'cancelled'
        | 'complete';
    step: string;
    current_phase: string;
    attempts_by_phase: Record<string, number>;
    total_attempts: number;
    simulation_units_spent: number;
    active_attempt_id?: string | null;
    gate_phase: string | null;
    gate_reason: string | null;
    cancel_requested?: boolean;
    retry_requested?: boolean;
    last_error: string | null;
    latest_evaluation_id?: string | null;
}

export interface AutomationPolicy {
    driver: 'simulation';
    max_attempts_per_phase: number;
    max_total_attempts: number;
    max_simulation_units: number;
    max_cost_usd: 0;
    score_threshold: number;
    human_gates: string[];
}

export interface AutomationStatus {
    state?: AutomationRunState;
    policy?: AutomationPolicy;
    event_count?: number;
    latest_event?: Record<string, unknown> | null;
    gate?: AutomationGate | null;
    checkpoint_consistent?: boolean;
    recovery_available?: boolean;
    projection_issues?: string[];
    cancellation_mode?: 'checkpoint';
    evaluation_kind?: 'structural_eligibility';
    error?: string;
}

export interface AutomationGate {
    gate_token: string;
    phase: string;
    kind: 'review' | 'human_action';
    evaluation_id: string | null;
    selection_ids: Record<string, string>;
    recommendations: Record<string, string>;
    decisions: Array<
        'approve' | 'manual_override' | 'retry_with_feedback' | 'cancel'
    >;
}

export interface IntegrityIssue {
    severity: 'error' | 'warning' | 'info';
    code: string;
    project: string | null;
    run: string | null;
    phase: string | null;
    path: string | null;
    entity: string | null;
    message: string;
    remediation: string;
}

export interface IntegrityState {
    total: number;
    errors: number;
    warnings: number;
    info: number;
    issues: IntegrityIssue[];
}

export interface GenerationAttempt {
    id: string;
    path: string | null;
    started_at: string | null;
    finished_at: string | null;
    driver: string;
    status: string;
    artifact_ids: string[];
    simulation_units: number | null;
    cost_usd: number | null;
    request: Record<string, unknown>;
    result: Record<string, unknown> | null;
    parse_errors: string[];
}

export interface TraceDocument extends CreativeDocument {
    id: string;
    title: string;
    path: string;
    parse_error?: string;
}

export interface AutomationEvent extends Record<string, unknown> {
    id?: string;
    event?: string;
    sequence?: number;
    phase?: string;
    created_at?: string;
    path?: string | null;
    _body?: string;
    parse_error?: string;
}

export interface TraceabilityState {
    attempts: GenerationAttempt[];
    evaluations: TraceDocument[];
    selections: TraceDocument[];
    source_decisions: TraceDocument[];
    feedback_applications: FeedbackApplicationTrace[];
    media_jobs: MediaJobTrace[];
    automation_events: AutomationEvent[];
}

export interface FeedbackSourceSnapshot {
    scope: string;
    path: string | null;
    pending_sha256: string | null;
}

export interface FeedbackApplicationTrace {
    attempt_id: string;
    phase: string | null;
    status: string;
    path: string | null;
    created_at: string | null;
    finished_at: string | null;
    source_snapshots: FeedbackSourceSnapshot[];
    generation_feedback_sha256: string | null;
    implementation_references: Record<string, unknown>[];
    history_ids: string[];
    request: Record<string, unknown> | null;
    result: Record<string, unknown> | null;
    parse_error: string | null;
}

export interface MediaJobTrace {
    id: string;
    path: string | null;
    created_at: string | null;
    request: Record<string, unknown> | null;
    state: Record<string, unknown> | null;
    result: Record<string, unknown> | null;
    events: AutomationEvent[];
    parse_errors: string[];
}
