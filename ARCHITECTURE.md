# Architecture and Phase Status

## Phase 1 status

Implemented:

- Next.js App Router UI for workspace overview, navigation, projects, and responsive design.
- Laravel 13 API with Sanctum bearer tokens; the Next.js same-origin API layer keeps the token in an HttpOnly, SameSite cookie and strips it from responses.
- Login throttling, server-side origin checks for mutations, fixed upstream route allowlists, request size limits, and database-backed authorization.
- Terminal-only first-owner setup with hidden password prompts; public registration is disabled.
- MySQL-compatible migrations for users, workspaces, memberships, channels, projects, tokens, and audit logs.
- Workspace-scoped project list/create/delete, owner/editor/viewer checks, idempotent client UUIDs, and audit events.
- The dashboard loads authenticated workspace data. Existing browser drafts are imported idempotently and kept locally until all imports succeed.
- Server-side, read-only ClipWize checks through the official CLI (`ping`/`me`) and version detection for the configured Blender executable.
- Project selection opens a project-scoped production workspace. Projects can be created, opened from search and project lists, and deleted through the API.

## Phase 2 status

Implemented and covered by Laravel feature tests:

- A project has one persistent script with a supported format, Indonesian narration, whitespace-based word count, draft status, and a version incremented only when saved content changes.
- Projects have ordered, persistent scenes with editable title, narration excerpt, visual description, camera direction, duration, and draft status.
- Script and scene mutations are transactional, workspace-authorized, audited, and blocked for viewer members. Scene ordering validates that the submitted IDs belong to the project and include every scene exactly once.
- The Studio project workspace provides explicit script saves, word-count guidance, scene creation/editing/deletion, and persisted up/down ordering. It warns that research is unverified and that scene visual descriptions are not Blender prompts or previews.
- An explicit, rate-limited script-draft action calls the configured OpenAI-compatible 9Router endpoint. It returns text for human review without persisting it; explicit script save remains a separate action.
- Laravel reads the 9Router key from `../9router-key.txt` relative to the API directory by default, or from server-only environment configuration. The Next.js browser-facing layer never receives the key. The configured model is `Kombo`, confirmed in the live model list.
- Script and scene tables have project foreign keys and indexes. The latest migrations have been applied to the local SQLite database; MySQL/MariaDB is not yet verified.
- The frontend passes ESLint and a production Next.js build. Laravel tests cover persistence, authorization boundaries, validation, and the mocked 9Router request.

Not yet implemented in Phase 2:

- Structured Prompt Builder data and revisions, media library/upload and asset metadata, scene thumbnails/previews, drag-and-drop scene ordering, AI storyboard/prompt generation, Blender execution, rendering, or visual approval.
- An authenticated browser session was not available for manual end-to-end UI interaction during this verification; UI code is build-checked, while persistence and authorization are covered by API feature tests.

Not yet implemented:

- Live MySQL/MariaDB connection verification and production deployment configuration; the local database service was stopped, so backend tests use in-memory SQLite.
- Password reset/email verification, workspace invitations and role management, and an operational backup/restore process.
- Prompt revisions, media metadata, and full production execution records.
- AI-assisted research, source collection, and fact verification. Script drafts are unverified and must be checked against two independent sources.
- Workflow queue/engine, Redis, real-time event channel, or persistent task recovery.
- Windows Local Agent, authenticated workstation pairing, Blender MCP adapter, rendering, or output verification.
- ClipWize account listing, research/TTS calls, or upload/publishing.

The workspace and project foundation now uses the Laravel API, but the production pipeline and most dashboard metrics are not connected. A locally detected Blender binary is only evidence that the studio host can report a version; it does not mean a workstation agent is online or that renders can run.

## Auto production status

Implemented (backend `App\Services\Production\ProductionPipeline`, job `RunProduction`, table `production_runs`):

- Resumable ten-stage run: ideate → research → script (+fact-check) → storyboard → prompts → tts → render → finalize → qc → publish. Each stage state is persisted; a failed or `needs_review` run resumes at the stage that stopped.
- Render uses allowlisted Blender execution on the host PC (`resources/blender/render_video.py`, driven only by a generated `spec.json`), not the future Windows Local Agent. Output is verified by frame count; ffmpeg assembles, captions, watermarks, mixes narration and BGM.
- Publishing goes through ClipWize `clips/import` + `social-posts` with account-name check, daily cap, duplicate-title guard, and no automatic retry (no idempotency key). Default requires a human approval click; `PRODUCTION_AUTO_PUBLISH` enables fully automatic upload gated by automated QC.
- Research searches via 9Router `/v1/search` (Tavily/Exa providers configured there) unless `TAVILY_API_KEY` is set; Wikipedia-only is the last-resort fallback.
- Tested with mocked integrations (`ProductionPipelineTest`); live-verified individually: 9Router idea generation, ClipWize account listing and TTS, Blender 5.2 headless render, ffmpeg assembly. Live YouTube publish has not been exercised.

## Target service boundaries

```text
Browser
  -> Next.js UI / same-origin server routes
  -> Laravel API (authentication, authorization, validation, audit)
       -> MySQL (durable domain state)
       -> Redis queues (workflow jobs, retries, recovery)
       -> WebSocket/event gateway (authorized progress updates)
       -> Integration adapters (ClipWize and server-side 9Router)
       -> Local Agent gateway
            -> Windows Local Agent on the user's workstation
                 -> allowlisted Blender operations and restricted project files
```

- The browser never receives a ClipWize bearer token or unrestricted shell access.
- ClipWize authentication must be initialized by the user through the CLI's hidden password prompt; the UI only receives a coarse authentication status.
- The Laravel API owns production records and workflow state. The UI is an editor and monitor, not an execution engine.
- 9Router requests originate in Laravel, use the configured model, and return drafts only. The API key is read server-side and must never appear in browser responses, logs, or generated content.
- External adapters must follow documented capabilities. The installed ClipWize skill documents the `scripts/clipwize.py` interface and the separate, real ClipWize API. It requires user-created authentication; TTS consumes credits and publishing is public and irreversible.
- Blender must run through an explicitly paired Local Agent on the user's Windows PC. Remote execution is a separately configured and approved mode, never an automatic fallback.
- A render is complete only after the agent reports a terminal result and the backend verifies the expected output file. Visual approval remains a distinct human review state.

## Core database model

Use integer primary keys, UTC timestamps, `user_id` ownership on tenant data, foreign keys, and indexes for owner/status/time queries. Store file metadata and restricted relative storage keys; do not store arbitrary workstation paths as executable instructions.

| Area | Core tables | Relationships / purpose |
|---|---|---|
| Tenancy | `users`, `workspaces`, `workspace_members`, `channels` | Users belong to workspaces by role; workspaces own channel configuration. |
| Production | `projects`, `production_executions`, `tasks` | A project has durable stage state and one or more uniquely identified executions; lock active executions per project. |
| Workflow | `workflow_templates`, `workflow_nodes`, `workflow_connections`, `workflow_node_runs` | Templates define a directed graph; node runs persist dependencies, attempts, approvals, outputs, and recovery state. |
| Creative | `research_sources`, `scripts`, `storyboards`, `scenes`, `prompts`, `scene_revisions` | Current MVP persists one versioned script and ordered draft scenes per project. AI-generated script drafts are not saved until reviewed and explicitly saved. Storyboard containers, source verification, structured prompts, and scene revision/review history remain future work. |
| Media | `media_assets`, `asset_usages`, `blender_jobs`, `render_outputs`, `audio_assets` | Assets are scoped to a workspace/project; render outputs reference their originating scene/job and verified storage key. |
| Delivery | `qc_reports`, `publishing_jobs`, `schedules` | QC findings gate publishing; publication state reflects actual integration responses and human approval. |
| Operations | `ai_agents`, `agent_runs`, `notifications`, `audit_logs` | Agent permission and activity history, delivery of alerts, and append-only security/audit events. |

Important indexes include `(workspace_id, created_at)`, `(project_id, status)`, `(execution_id, status)`, `(workflow_node_run_id, attempt)`, `(schedule_id, next_run_at)`, and unique execution IDs. Enforce duplicate-schedule prevention and active project locks transactionally. Queue jobs must use idempotent internal transitions; external publish requests cannot be blindly retried because ClipWize has no documented idempotency key.

## Delivery sequence

1. **Phase 1 follow-up:** connect and verify MySQL/MariaDB, then add recovery/verification and operational deployment safeguards to the working authentication and workspace foundation.
2. **Phase 2 follow-up:** add structured prompt persistence and revisions, restricted media metadata, and the remaining project-workspace capabilities.
3. **Phase 3:** implement a backend workflow engine with dependency scheduling, approvals, retries, and recovery before exposing a React Flow editor.
4. **Phase 4:** build the signed Windows Local Agent protocol and authorized Blender operations; verify actual preview files before approval.
5. **Phase 5–6:** add AI research, source verification, storyboard and prompt generation, then documented ClipWize TTS/QC/publishing adapters with explicit approval gates.
6. **Phase 7:** add scheduler, analytics, and notifications after persistent execution and recovery are tested.
