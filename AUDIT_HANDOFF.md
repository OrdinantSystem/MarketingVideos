# VideoAuto — current API-free handoff

**Updated:** 2026-07-28  
**Source of truth:** `README.md`, `docs/CREATIVE_PRODUCTION_SYSTEM.md`, and the current code/tests  
**Detailed audit:** `docs/API_FREE_AUDIT.md`

## Current state

The API-free foundation is implemented. VideoAuto is now a filesystem-first creative production workspace rather than only a phase-navigation prototype.

Manual and automatic simulation use the same immutable artifacts and append-only selections. A schema-v1 Brief & Script supplies a typed, dynamic 1–12-shot plan that must match its timed Markdown; only genuinely unversioned files use legacy body derivation. Sequence metadata is derived from the exact ordered selected Scene snapshots rather than trusted from a generator. Simulation creates real deterministic local PNG and MP4 fixtures, and an explicit delivery step can assemble the selected scenes into a local FFmpeg render with captions, timing audio, and a source-hashed manifest.

Automatic mode is deliberately structural rather than creatively intelligent. It has phase-specific contract rubrics, diagnosis-aware retries, attempt and simulation-unit limits, explicit human gate decisions, cancellation, run-level locking, and recovery projected from immutable events, attempts, evaluations, and selections. A retry records a deterministic phase-specific refinement action plus diagnosis/context hashes; this is meaningful structural evidence, not a claim of better creative quality. An event-only attempt reservation is reconstructed as a valid interrupted request/result pair, and any feedback reserved in that crash window is marked `not_applied` without clearing the inbox. A missing automatic selection event is backfilled only from the exact effective selection record tied to the expected evaluation. Each decision is bound to the durable source-event `gate_token`; missing or stale tokens are rejected, retry-feedback notes are token/hash idempotent, and replayed `phase_reused` events still preserve configured gates. Generic Resume cannot approve a review gate.

## Trust and traceability now present

- Schema-v1 artifacts must belong to a complete committed attempt before selection.
- Schema-v1 selection checks the exact request/result driver, phase, slot and source lineage, expected phase batch shape, ordered members, complete SHA-256 map, and current bytes of every committed member.
- `asset_status` is closed to `ready` and the legacy `absent_simulation` state; ready assets require contained paths, byte counts, SHA-256, and probe metadata.
- A ready Scene probe must match its shot window within the explicit 0.125-second simulation-frame tolerance.
- Ideas selection and skipping are explicit append-only source decisions.
- Feedback supplied to generation is snapshotted and assembled into the exact generator payload while the phase and whole-run inbox locks are held. A committed batch records applied history with attempt/artifact references; a failed attempt leaves the inbox pending. Recovery reconciles a terminal attempt whose feedback result was interrupted. Manual archive remains `archived_unapplied`.
- Media and render workers atomically claim only `pending` work, so duplicate queue delivery exits without processing twice. Final publication and terminal completion share the cancellation lock: cancellation winning first prevents promotion; a completed promotion wins consistently. Render outputs remain staged through preflight, and partial promotion rolls back only links originating from that staging set. Automation recovery closes unfinished media jobs owned by unsuccessful attempts before retrying.
- Render requests pin the exact Sequence and Scene selection-record IDs, artifact IDs, and binary hashes; a later reselection invalidates the request even when it points to the same artifact.
- The workspace exposes front matter, attempt manifests, evaluation diagnoses, selection reasons, source decisions, feedback applications, media-job events, automation events, integrity issues, and local render state.
- The read-only integrity checker covers malformed/stale artifacts and selections, incomplete attempts, feedback applications, media jobs/assets, and render outputs. It uses the production automation projector for canonical replay/checkpoint checks and walks applied feedback history back to its terminal application result to find reverse orphans.
- Creative routes reject non-loopback direct peers. A locally terminating proxy or tunnel can appear as loopback, so deployment must also bind to loopback and must not publish a public relay. Authentication exists elsewhere in Laravel but does not yet model creative-project ownership.

## Honest limitations

- Automatic evaluation proves structure and lineage, not persuasion, taste, brand fit, factual accuracy, or visual quality.
- Integrated Images/Scenes simulation still runs synchronously. A standalone filesystem-backed queued batch coordinator now proves complete reservation, automatic worker reconciliation, batch-private binaries, all-or-nothing sidecar publication, terminal recovery, and integrity auditing; normal generation, HTTP/UI progress, feedback finalization, and automation still need an explicit `waiting_for_media` integration before paid callbacks. A terminal failed, cancelled, or timed-out child cannot yet be independently retried or replaced inside its batch.
- Local images and videos are deterministic fixtures, not provider media.
- Render captions use shot purpose, and audio is a quiet timing-reference tone. There is no synthesized speech, music, semantic captioning, or production mix.
- Performance records are manually entered evidence. Rankings are source-hashed and explicitly non-causal; they do not automatically change prompts.
- The compatibility repository still assembles the workspace read model and some high-level navigation/selection behavior, although generation, validation, storage, automation, media, rendering, and evidence now have separate services.

## Provider-integration order

1. Integrate the existing standalone asynchronous batch coordinator with generation, feedback, HTTP/UI, automation, and idempotent provider callbacks; do not create a second coordinator.
2. Connect one paid image worker behind the existing media-job request/result/events contract.
3. Validate every download as untrusted; record provider request ID, exact model/settings, latency, usage, cost/currency, retry, safety, and normalized failure data.
4. Prove the full provider lifecycle with fake HTTP before authorizing a real request.
5. Connect Scene/video generation through the same per-shot job contract and add bounded, immutable terminal-child retry/replacement semantics.
6. Add independently traceable voice, semantic captions, music, and audio QA.
7. Extend performance imports and explicit evidence retrieval without silently rewriting creative guidance.

Do not put provider calls in controllers, the orchestrator, or the renderer. Keep simulation as the default and regression oracle.

## Hard safety boundary for local proof

- Do not call OpenRouter, fal.ai, ElevenLabs, or another external service.
- Do not run live provider checks or any creative smoke command.
- Do not inspect, print, copy, or modify `.env` secrets.
- Preserve the dirty worktree and existing `creative-projects/` history.
- Force `CREATIVE_GENERATION_DRIVER=simulation` for tests and browser work.

```bash
CREATIVE_ACCESS_MODE=local CREATIVE_GENERATION_DRIVER=simulation composer run dev
CREATIVE_GENERATION_DRIVER=simulation php artisan test --compact
php artisan creative:integrity
composer types:check
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

The test harness rejects stray Laravel HTTP requests. The integrity command is read-only; findings in historical projects should be reported, not “fixed” by rewriting their files.
