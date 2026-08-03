# VideoAuto API-free foundation handoff

**Date:** 2026-07-28  
**Current milestone:** Filesystem contracts, recoverable automation, deterministic local media/rendering, and evidence capture  
**External calls used to prove this milestone:** None

## Outcome

The API-free foundation is implemented. VideoAuto can run the same six creative phases manually or through automatic simulation, use a script-defined shot plan, create verifiable local PNG/MP4 fixtures, pause for explicit human decisions, recover from incomplete checkpoints, assemble a deterministic local video, and attach manually observed performance evidence.

This is not yet paid-provider production. The current evaluator proves structural eligibility rather than creative quality; image/video assets are deterministic local fixtures; the render audio is a timing-reference tone; and paid image, scene/video, voice, and caption adapters are deliberately disconnected from the creative workflow.

## Current boundaries

- Creative truth remains reconstructible from files under `creative-projects/`.
- Automatic mode is always `simulation`, with a hard real-cost boundary of `$0.00`.
- The media fixture renderer uses local GD and FFmpeg; probing uses local file inspection and ffprobe.
- Final assembly uses local FFmpeg only.
- Tests force simulation and reject stray Laravel HTTP requests.
- Creative HTTP routes reject non-loopback direct peers. A locally terminating proxy or tunnel can appear as loopback, so deployment must also bind to loopback and must not publish a public relay. Authentication does not yet represent creative-project ownership.
- Existing manual OpenRouter text generation remains a separate, explicit opt-in path; it is not used by automatic mode, media jobs, or rendering.

## Implemented architecture

```text
CreativeController
├── CreativeWorkspaceRepository        # compatibility facade and workspace projection
│   ├── FilesystemCreativeStore         # low-level files, feedback, selections, source decisions
│   ├── CreativeArtifactValidator       # schema and phase contracts
│   ├── CreativeArtifactEligibilityVerifier
│   ├── EffectiveSelectionResolver
│   ├── ShotPlanResolver
│   └── CreativeGenerationService
│       ├── PhaseGeneratorRegistry
│       ├── SimulationPhaseGenerator
│       ├── OpenRouterPhaseGenerator    # manual configured text mode only
│       ├── GenerationAttemptStore
│       ├── FeedbackApplicationStore    # reserved snapshots, terminal result, applied history
│       └── SimulatedMediaBatchMaterializer
│           └── SimulatedMediaJobService
│               ├── MediaJobStore
│               ├── DeterministicMediaRenderer
│               └── MediaProbeService
├── CreativeRunOrchestrator
│   ├── AutomationPolicy
│   ├── AutomationStateStore
│   ├── AutomationRunProjector
│   ├── AutomationRecordValidator
│   └── SimulationArtifactEvaluator
├── CreativeIntegrityAuditor
│   └── CreativeOperationalIntegrityAuditor
├── CreativeTraceabilityReader
├── DeterministicRenderService
│   ├── RenderInputResolver
│   └── RenderStore
└── PerformanceRecordStore
```

## Durable filesystem records

```text
{run}/
├── {numbered-phase}/
│   ├── outputs/{artifact-id}.md
│   ├── attempts/{attempt-id}/{request.md,result.md}
│   ├── evaluations/{evaluation-id}.md
│   ├── assets/{artifact-id}.{png,mp4}
│   └── media-jobs/{job-id}/
│       ├── request.md
│       ├── state.md
│       ├── result.md
│       ├── events/*.md
│       └── staging/
├── selections/{phase}/*.md
├── decisions/ideas-source/*.md
├── automation/{policy.md,state.md,events/*.md}
├── feedback/
│   ├── current/{cycle.md,phase-{phase}.md}
│   ├── applications/{attempt-id}/{request.md,result.md}
│   └── history/*.md
├── renders/{render-id}/
│   ├── request.md
│   ├── state.md
│   ├── manifest.md
│   ├── final.mp4
│   ├── captions.srt
│   ├── timing-reference.wav
│   └── voice-script.md
└── performance/records/*.md

{project}/learning/*.md
```

Mutable state files are atomic checkpoints, not sole truth. Immutable requests, results, events, artifacts, evaluations, selections, hashes, and source decisions carry the recoverable evidence.

## Artifact and selection contract

New schema-v1 artifacts record exact source IDs, generation attempt, generator, prompt version, settings, usage, cost, status, simulation flag, and phase-specific metadata. A selection is allowed only when:

1. the phase artifact validates;
2. its slot and source lineage match the current expected inputs;
3. dynamic shot metadata exactly matches the selected script where applicable;
4. its attempt request and committed result exist;
5. the attempt describes one complete candidate batch and includes the artifact;
6. the artifact Markdown SHA-256 still matches the attempt result;
7. a `ready` media sidecar resolves to one contained binary matching declared bytes and SHA-256.

`asset_status` is a closed enum: `ready` or `absent_simulation`. The latter is retained only for deliberate legacy-style simulation records and cannot claim a binary probe. New Images and Scenes simulation generates `ready` binaries.

Legacy files are not rewritten. They remain inspectable and may carry warnings, while invalid or ambiguous legacy media is blocked.

## Dynamic shots and Ideas decisions

Brief & Script artifacts define a canonical `shot_plan` with 1–12 stable shot IDs, contiguous positive timing starting at zero, total duration no greater than 15 seconds, order, purpose, and visual description. Images use those IDs as slots, Scenes require the selected Image from the same slot, and Sequence snapshots the ordered selected Scene IDs and shot plan.

Ideas is optional through an explicit append-only source decision:

- selecting an Idea records `mode: selected_idea`, the artifact and selection IDs, actor, and reason;
- skipping records `mode: project_idea`, the project ID, actor, and reason;
- a later valid decision supersedes the earlier decision without deleting it.

The UI labels skipped Ideas and shows the effective Concepts source, decision history, shot timing, purpose, visual intent, and stale extra artifacts.

## Automation behavior

The default policy remains two attempts per phase, twelve total attempts, sixty simulation units, an 80/100 threshold, and human gates at Brief & Script and Sequence. Overrides are validated before the immutable policy is written. Automatic mode cannot change its driver or real-cost ceiling.

`SimulationArtifactEvaluator` identifies its rubric as `structural_eligibility`. Each phase adds named checks appropriate to its contract—for example idea tension/product connection, concept hook/mechanism, a canonical timed shot plan, Image shot snapshot/variant direction, Scene motion/timing/source snapshot, or Sequence scene manifest/editor handoff. Scores and dimensions are deterministic and inspectable; they do not claim to measure persuasion or aesthetics. Equal scores use the immutable, one-based per-batch `candidate_order` before artifact ID, so random IDs cannot change a recommendation.

When an evaluation fails, the immutable diagnosis, fatal issues, prior evaluation ID, and retry-context hash become part of the next attempt request. A human retry also links the exact gate-decision event and decision-feedback hash; generated candidates record the hash of the complete feedback they applied. This makes retry behavior observable instead of repeating an unexplained attempt.

At an awaiting-review gate, the operator must choose one of:

- `approve`: accept the exact evaluator recommendations;
- `manual_override`: continue with the currently effective selections after human review;
- `retry_with_feedback`: append the supplied phase feedback and spend another bounded attempt;
- `cancel`: preserve all evidence and terminalize the run.

The decision event stores the exact selection map and is idempotent. Generic `resume` never approves or overrides an awaiting-review gate; only the explicit decision endpoint may cross it.

## Replay and recovery

`automation/state.md` is a performance cache. `AutomationRunProjector` rebuilds the run from the immutable policy, ordered events, generation attempts/results, evaluations, and effective selections. It reconstructs:

- current phase and substep;
- per-phase and total attempt counts;
- simulation units spent;
- active attempt and latest evaluation;
- retry request and diagnosis context;
- configured/pending gate and exact selections;
- cancelled, failed, or complete terminal state.

Resume reconciles a missing, unreadable, or valid-but-inconsistent checkpoint with that projection and continues cancelled or failed work, but it refuses to decide an `awaiting_review` gate. A policy-only interrupted bootstrap is recovered without resetting limits. A request lacking a result is terminalized as a valid `interrupted` attempt before a new attempt is created. A stored complete state is reopened if current filesystem selection/lineage truth is no longer complete.

Start, run, step, resume, decide, and cancel are serialized by a single run-level lock, so a compound transition cannot overwrite a competing cancellation or gate decision.

## Local media jobs

Images and Scenes simulation now materializes deterministic binaries. A batch reserves every immutable job request before processing the first one. Shared metadata records generation attempt/batch identity, position, size, artifact ID, media type, destination, timeout, and renderer.

Supported states are `pending`, `running`, `completed`, `failed`, `cancelled`, and `timed_out`. Every job has an atomic state, immutable terminal result, and append-only events. Work happens in an attempt-specific staging directory. Successful files are decoded/probed, then promoted to the final path through a non-overwriting hard link and probed again; the result records bytes, SHA-256, MIME, codec, dimensions, duration, and audio stream when present.

Cancellation is honored before rendering and after probing. Expired running jobs can be recovered in a batch: a valid already-promoted binary is reconciled to `completed`; otherwise the job becomes `timed_out`. Files are retained for diagnosis.

`ProcessSimulatedMediaJob` supports isolated queue dispatch. A separate filesystem-backed queued batch coordinator now reserves the full immutable membership before dispatch, uses `ProcessQueuedMediaBatchJob` to reconcile the parent after success/failure, keeps binaries batch-private, and commits sidecars only after every child completes. The current Images/Scenes generation path still executes synchronously; before paid callbacks, wire its generation attempt, feedback, HTTP/UI progress, automation resume/cancel, and final attempt commit through this coordinator with an explicit `waiting_for_media` state. A terminal failed, cancelled, or timed-out child cannot yet be independently retried or replaced inside its batch.

## Deterministic local delivery

After a valid Sequence is selected, an explicit render request captures:

- the Sequence artifact and selection IDs;
- ordered Scene artifact and selection IDs;
- each Scene binary SHA-256;
- the complete shot plan;
- local render settings and a `$0.00` cost.

Before work, the renderer re-resolves effective inputs and fails if they changed. FFmpeg concatenates the exact selected Scene MP4 files, shot purpose becomes SRT caption text, and a quiet generated tone supplies timing-reference audio. The final MP4 contains video, AAC audio, and an embedded `mov_text` caption stream. The manifest records hashes, sizes, probes, paths, renderer identity, and exact lineage. `voice-script.md` explicitly says no speech was synthesized.

Render states are `pending`, `running`, `completed`, `failed`, `cancelled`, and `timed_out`; the request and terminal manifest are immutable. `ProcessDeterministicRender` supports queue execution, with synchronous local execution as the default.

## Performance evidence

Performance records may be added only to a completed render whose final bytes still match its manifest. The store accepts bounded manual observations such as impressions, views, completions, clicks, conversions, spend, and revenue; validates part/whole relationships; and derives rates when possible.

Project-level learning summaries rank records by one chosen derived metric and preserve every source performance ID and Markdown SHA-256. They explicitly avoid causal claims and do not mutate prompts or creative files.

## Traceability and integrity UI

The workspace now exposes the evidence already stored:

- raw structured artifact front matter and validation/eligibility messages;
- selections, actor, reason, and linked evaluation;
- generation attempt request/result and batch status;
- evaluation rubric, score dimensions, recommendations, and diagnosis;
- Ideas source decisions;
- media job request/state/result/events;
- automation events, checkpoint health, counters, and gates;
- feedback inbox/history status;
- feedback application request/result, exact snapshot hashes, and attempt/artifact/history implementation references;
- render state/manifest identity and performance/learning counts;
- integrity issue severity, path, explanation, and remediation.

`php artisan creative:integrity {project?} {run?} --json --strict` is read-only. In addition to artifact/selection/attempt/automation checks, it audits media job identity/status/events/assets and render request/state/manifest/outputs, including contained paths, hashes, byte counts, and contradictory terminal evidence.

## Verification contract

The safe local verification commands are:

```bash
CREATIVE_GENERATION_DRIVER=simulation php artisan test --compact
php artisan creative:integrity
composer types:check
npm run types:check
npm run lint:check
npm run build
```

The feature/unit coverage includes complete automatic runs, checkpoint loss/inconsistency, gate decisions, retry diagnosis, dynamic shot counts, immutable batches, media success/failure/cancellation/timeout/recovery, binary mutation, queue dispatch, final render lineage/cancellation, performance hash rejection, UI traceability, and direct-peer loopback route enforcement. Provider tests use fakes, and unmatched HTTP is denied.

## Next-session handoff: paid adapters, not another foundation rewrite

The next session should preserve every contract above and work in this order:

1. **Integrate the existing standalone asynchronous batch coordinator** with generation attempts, feedback finalization, HTTP/UI progress, automation resume/cancel, and a durable `waiting_for_media` state. Extend it for idempotent provider callbacks rather than creating a second coordinator.
2. **Choose one paid media capability**—prefer Images first—and implement a provider worker behind the existing immutable media-job request/result lifecycle.
3. **Normalize provider evidence** at job/attempt level: provider request ID, model/version, settings, latency, usage, exact cost/currency, retries, response/download hashes, safety result, and error category.
4. **Keep downloads untrusted** until content type, decode/probe, dimensions/duration, size limits, safe containment, and SHA-256 checks pass.
5. **Use fake HTTP for the complete provider lifecycle** before any real key is enabled; retain simulation as the default and as the orchestration regression oracle.
6. **Then connect Scene/video generation**, adding bounded, immutable per-shot retry/replacement semantics while preserving the all-members-valid batch commit rule.
7. **Then connect voice and semantic captions**. Keep the timing tone and voice document as an honest local fallback; add loudness/duration/alignment validation.
8. **Finally enhance assembly and learning** with production audio/caption choices, platform imports, and explicit evidence retrieval. Never let performance rankings silently rewrite creative guidance.

Do not put provider calls directly into controllers, generators, or the renderer. Do not weaken direct-peer loopback enforcement, the local-relay deployment caveat, eligibility, immutable history, cost limits, or hash/probe checks merely to make an adapter easier to connect.
