# VideoAuto creative production workspace

VideoAuto is a local Laravel + React workspace for turning a broad advertisement idea into inspectable creative files and, in simulation, a deterministic local video.

The product supports two ways of working:

- **Sequential mode:** a person generates alternatives, inspects files, records feedback, selects exact outputs, and hands those artifacts to the next phase.
- **Automatic simulation:** a resumable orchestrator runs the same phases, applies structural rubrics, respects attempt and simulation-unit limits, and pauses for explicit human decisions without contacting a provider.

Both modes use the same filesystem artifacts, attempt manifests, selection records, and lineage rules. Automation is not a hidden second pipeline.

## Production chain

| Phase                 | Input                                      | Output                                                                              | Required decision             |
| --------------------- | ------------------------------------------ | ----------------------------------------------------------------------------------- | ----------------------------- |
| 00 · Ideas (optional) | Broad project idea                         | 3 advertising directions                                                            | Select one or explicitly skip |
| 01 · Concept          | Selected idea or `project.md`              | 3 creative concepts                                                                 | Select one                    |
| 02 · Brief & script   | Selected concept                           | 3 briefs/scripts with typed shot plans                                              | Select one                    |
| 03 · Images           | Selected script                            | 2 deterministic local PNG alternatives per script-defined shot                      | Select one per shot           |
| 04 · Scenes           | Selected keyframes                         | 1 deterministic local MP4 per shot                                                  | Select one per shot           |
| 05 · Sequence         | Selected scenes                            | 3 edit/assembly plans                                                               | Select one                    |
| Local delivery        | Selected sequence and exact scene binaries | FFmpeg MP4, SRT captions, timing-reference WAV, voice timing document, and manifest | Explicitly request a render   |

Generating again creates a new immutable attempt. Selecting an artifact creates a separate append-only pointer; it never replaces the artifact or an earlier selection. The selected Brief & Script defines the stable shot IDs, ordering, timing, purpose, and visual descriptions used by every media phase.

## Filesystem truth

Creative state lives under `creative-projects/`, not in relational creative tables:

```text
creative-projects/
└── project-name--unique-id/
    ├── project.md
    ├── learning/
    └── runs/date-time--unique-id/
        ├── run.md
        ├── 00-ideas/ ... 05-sequence/
        │   ├── outputs/
        │   ├── attempts/{attempt-id}/{request.md,result.md}
        │   ├── evaluations/
        │   ├── assets/                 # local PNG/MP4 simulation binaries
        │   └── media-jobs/{job-id}/    # request, state, result, events, staging
        ├── selections/{phase}/
        ├── decisions/ideas-source/
        ├── automation/{policy.md,state.md,events/}
        ├── feedback/{current,applications,history}/
        ├── renders/{render-id}/
        └── performance/records/
```

Markdown front matter is the machine-readable contract; the body is the human-readable explanation. Schema-v1 artifacts must point to a complete committed attempt, pass phase validation, match current lineage, and—when media is declared `ready`—resolve to a contained binary whose byte count and SHA-256 match its sidecar. Invalid, stale, incomplete, or corrupted artifacts remain inspectable but cannot advance the run.

## What works now

- Manual project/run navigation, six-phase generation, inspection, feedback, regeneration, and append-only selection.
- Explicit Ideas source decisions: selecting an Idea and skipping Ideas are both append-only records that later decisions can supersede.
- Typed dynamic shot plans rather than a fixed five-shot template. Schema-v1 Brief & Script artifacts must carry a canonical 1–12-shot plan that agrees with the human-readable timed script; legacy Markdown derivation is reserved for truly unversioned artifacts.
- Validated schema-v1 artifacts, committed generation batches, exact source IDs, and immutable artifact hashes. Selection requires a valid request and committed result whose phase shape, counts, ordered members, lineage, and SHA-256 map match every file in the batch.
- Simulation-only automatic mode with structural, phase-specific rubrics; diagnosis-aware retries that record a deterministic phase-specific refinement action and its hashes; configurable attempt/unit limits; explicit approve, manual-override, retry-with-feedback, and cancel decisions; and run-level transition locking. Every decision carries the exact durable gate-event token, so a delayed request cannot decide a newer gate.
- Recovery projected from policy, events, attempts, evaluations, and selections. A lost or inconsistent `state.md` checkpoint is repaired without resetting counters or gates, a replayed reuse of a gated phase still pauses for review, and a previously complete run is reopened if current filesystem truth is no longer complete. An `attempt_started` reservation whose process died before writing its attempt request is reconstructed as a valid interrupted request/result pair; any reserved feedback is marked `not_applied` without clearing the inbox. If an automatic selection file committed before its event, recovery backfills the event only when that exact effective record names the expected evaluation. Reconciliation also finishes feedback-application and media-job side effects left between durable generation records.
- Deterministic local image/video jobs with `pending`, `running`, `completed`, `failed`, `cancelled`, and `timed_out` states. Jobs reserve immutable requests, claim `pending` work under a per-job lock, stage locally, probe with GD/ffprobe, hash binaries, promote them without overwrite, and retain terminal evidence for recovery and integrity checks. Duplicate worker delivery cannot claim the same job twice. Final promotion and completion share the cancellation lock, so either cancellation wins before publication or one consistent completed result wins. A ready Scene probe must also match its shot duration within the explicit 0.125-second fixture tolerance.
- A standalone queued-media batch coordinator now proves all-members-known-before-dispatch, automatic parent reconciliation, append-only batch events, batch-private binaries, all-or-nothing sidecar publication, failed-attempt asset evidence, and result-fenced recovery. The normal Images/Scenes generation path remains synchronous until generation, HTTP, UI progress, and automation gain an explicit `waiting_for_media` outcome. A terminal failed, cancelled, or timed-out child cannot yet be independently retried or replaced inside that batch.
- Deterministic FFmpeg delivery from the exact selected scenes: final MP4, SRT and embedded captions, a quiet timing-reference tone, a voice timing document, and a source-hashed final manifest. Render requests pin both artifact IDs and append-only selection-record IDs, then reject changed effective selections or scene bytes before assembly. All delivery files stay staged until preflight and are promoted under the final cancellation lock; a failed multi-file promotion rolls back only links created from that staging set. It does **not** synthesize speech.
- Manual performance observations tied to a verified render hash, plus deterministic project-level rankings that preserve source hashes and explicitly avoid causal claims.
- In-app traceability for artifact front matter, generation attempts, evaluations, selections/reasons, Ideas source decisions, media jobs/events, automation events, feedback applications/history, plus Sequence delivery state and integrity findings.
- Feedback application records that lock both phase/run inbox sources while constructing and hashing the exact generator payload, preserve later concurrent notes, and archive a successful snapshot with attempt, artifact, and SHA-256 implementation references. Manual archive remains truthfully `archived_unapplied`.
- A read-only integrity audit covering malformed or stale artifacts/selections, missing or changed binaries, incomplete attempts, canonical automation replay/checkpoint divergence, media-job state/results/events, render manifests/outputs, and reverse orphan checks such as applied feedback history with no applied terminal result.
- Creative routes require a direct loopback peer plus a `localhost`/loopback `Host`; explicit ports must match `CREATIVE_LOCAL_PORT` (default `8000`), and cross-origin writes are rejected from `Origin`/`Referer` evidence. Forwarded headers are ignored. A same-host reverse proxy or tunnel can still appear local, so deployment must bind to loopback and must not publish the app through a public relay. Remote collaboration remains unsupported until ownership and authorization are designed.

## Deliberate boundaries

The complete automatic, media, render, and learning path described above is API-free. Automatic mode always injects the simulator, local media jobs never invoke fal.ai or ElevenLabs, and the deterministic renderer invokes only local FFmpeg/ffprobe. Simulation cost is recorded as `$0.00`.

The repository still contains provider adapter code and the existing explicitly configured manual OpenRouter text path. fal.ai and ElevenLabs are not connected to creative media artifacts, and no paid provider participates in automatic mode or rendering. Paid image, scene/video, voice, and caption adapters are a future integration step after their job, cost, download, and failure contracts are mapped onto the proven local lifecycle.

Manual feedback archiving is intentionally conservative: it remains labelled `archived_unapplied`. Feedback supplied to generation follows a separate lifecycle and is labelled `applied` only after a complete batch commits with exact implementation references; failed attempts leave the inbox pending.

## Run safely

Requirements for the full local simulation include PHP with GD, Node.js, FFmpeg, and ffprobe.

```bash
CREATIVE_ACCESS_MODE=local CREATIVE_LOCAL_PORT=8000 CREATIVE_GENERATION_DRIVER=simulation composer run dev
```

Open [http://localhost:8000](http://localhost:8000). Creative routes accept direct IPv4/IPv6 loopback connections only, validate the local `Host`, and reject cross-origin changes.

## Verify without APIs

```bash
CREATIVE_GENERATION_DRIVER=simulation php artisan test --compact
php artisan creative:integrity
composer types:check
npm run types:check
npm run lint:check
npm run build
```

The test harness calls `Http::preventStrayRequests()`, and PHPUnit forces the simulation driver. Do not use live provider checks or smoke commands when proving the API-free pipeline.

See [Creative Production System](docs/CREATIVE_PRODUCTION_SYSTEM.md), [API-free audit](docs/API_FREE_AUDIT.md), [local-access decision](docs/ADR_LOCAL_ACCESS.md), and [automation foundation handoff](docs/AUTOMATION_FOUNDATION_HANDOFF.md). The older [Laravel Technical Handoff](VideoAuto_Laravel_Technical_Handoff.md) is retained as historical research rather than the current source of truth.
