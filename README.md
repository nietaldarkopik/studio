# Ternyata Begini Studio

Production workspace foundation for the Ternyata Begini video studio.

## Run locally

```bash
npm install
npm run dev
```

Start the Laravel API separately from `../api`:

```powershell
Set-Location ..\api
php artisan serve --host=127.0.0.1 --port=8001
```

Open `http://localhost:3000`. Set `STUDIO_API_URL`, `CLIPWIZE_BASE`, and `BLENDER_PATH` in `.env.local` as needed; `.env.example` contains non-secret defaults. Never prefix credentials or tokens with `NEXT_PUBLIC_`.

Create the initial Studio account from `../api` using `php artisan studio:user-create`. Password input is hidden in the terminal; public signup is disabled. See [the API setup guide](../api/README.md) for database and migration setup.

## Current scope

- Dark creative workspace shell, navigation, responsive layout, and dashboard.
- Laravel API login with a server-set HttpOnly cookie; the Sanctum bearer token is never returned to browser JavaScript.
- MySQL-ready migrations for users, workspaces, memberships, channels, projects, scripts, scenes, personal access tokens, and audit logs.
- Authenticated workspace/project CRUD plus project-scoped script and storyboard editing, role checks, idempotent draft migration, and audit events.
- Scripts persist their format, narration, word count, status, and content version. Scenes persist their order, narration excerpt, visual description, camera direction, duration, and draft status.
- Script drafts can be generated through the configured 9Router model. Generated text stays unsaved for human review; the Laravel API reads the key server-side from `../9router-key.txt` by default.
- Existing preview drafts are copied to the workspace after login and removed from browser storage only after every import succeeds.
- In local development only, `/api/integrations` checks ClipWize through the documented `clipwize.py` CLI (`ping` and `me`) and checks the configured Blender executable using only `--version`.
- The script editor shows the 95–160 word production target and flags that research still needs two independent sources; AI output is a draft, not source verification or fact-checking.
- Blender detection does not pair the Local Agent, open a project, or start a render. AI generation, structured prompts, previews, media management, TTS, workflow execution, QC, and publishing are not active yet.

Apply pending API migrations from `../api` with `php artisan migrate`. Local SQLite and the Laravel feature suite are verified; a live MySQL/MariaDB connection is not.

Run frontend checks with `npm run lint` and `npm run build`. Run API tests from `../api` with `php artisan test`.

To authenticate ClipWize, run its documented login command yourself in a terminal. It prompts for the password without echoing it and stores a local token for the CLI. Do not put that password or token in the app, browser storage, or `.env.local`.

See [ARCHITECTURE.md](./ARCHITECTURE.md) for service boundaries, database model, and remaining phase work.
