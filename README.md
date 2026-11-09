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

## Auto production (ide → upload YouTube)

Menu **Auto Production** (atau `php artisan studio:autopilot`) menjalankan seluruh alur untuk satu video:
tema/judul dari AI (menghindari topik yang sudah dipakai) → riset ≥2 situs independen → naskah 95–160 kata + fact-check terhadap sumber → storyboard → prompt visual → narasi TTS ClipWize → render Blender 5.2 (headless, Eevee) → ffmpeg (caption, watermark, BGM −22 dB) → QC otomatis → upload YouTube Shorts lewat ClipWize.

Menjalankan:

```powershell
# terminal 1 — API
Set-Location api; php artisan serve --host=127.0.0.1 --port=8001
# terminal 2 — worker (render bisa berjam-jam, jangan beri timeout)
Set-Location api; php artisan queue:work --timeout=0
# terminal 3 (opsional) — produksi harian terjadwal bila PRODUCTION_DAILY_AT diisi
Set-Location api; php artisan schedule:work
```

Prasyarat satu kali: token ClipWize dibuat oleh Anda (`python clipwize.py login --email ...`, token tidak pernah dibaca/ditulis oleh aplikasi selain dibaca server saat memanggil API), dan 9Router hidup (riset memakai `/v1/search` 9Router; `TAVILY_API_KEY` opsional) di `localhost:20128`. Lihat `api/.env.example`.

Keamanan publikasi: upload bersifat publik dan tidak dapat dibatalkan, sehingga default-nya berhenti dan menunggu tombol **Setujui & upload**. Set `PRODUCTION_AUTO_PUBLISH=true` (atau centang opsi saat memulai / `--publish`) agar video langsung diunggah **hanya bila** semua QC otomatis lolos. Upload tidak pernah diulang otomatis; hasil yang tidak jelas ditandai *needs_review*. Ada batas upload per hari dan pemeriksaan judul duplikat. QC otomatis memeriksa resolusi, durasi, audio, kecerahan, panjang naskah, sumber, dan prompt, serta membuat contact sheet untuk tinjauan manual (`storage/app/production/run_N/contact.png`).

### Visual adegan

Renderer Blender menyusun adegan dari **pustaka objek 3D prosedural** (orang, kerumunan, sekolah, rumah, candi, pohon, gunung, Bumi, planet, roket, gunung berapi, kapal, pesawat, jembatan, grafik, jam, buku, dll.). AI memilih dan menata objek sesuai isi narasi, lengkap dengan animasi dan kata kunci di layar. Prompt visual dipakai AI untuk memilih objek; hasilnya bukan gambar fotorealistik.

Untuk subjek yang tidak ada di pustaka (mis. candi tertentu), isi `SKETCHFAB_API_TOKEN`: pipeline akan mencari model glTF berlisensi CC0/CC-BY di Sketchfab, mengunduhnya (ZIP diperiksa), dan memakainya di render. Atribusi CC-BY otomatis ditulis di deskripsi YouTube. Bila model tidak ditemukan atau token kosong, objek prosedural cadangan dipakai.

**MCP Blender (eksplorasi interaktif)**: `.mcp.json` di folder proyek mendaftarkan `mcp-for-blender` (telemetri mati, `BLENDER_MCP_SAFE_MODE=1`, hanya localhost). Addon dipasang ke Blender 5.2 (cadangan `blender_mcp.py.bak`). Cara pakai: buka Blender, tekan N > tab *MCP for Blender* > *Start MCP Server*; isi key Sketchfab di panel atau env `BLENDERMCP_SKETCHFAB_API_KEY`; restart sesi Claude Code dan setujui server `blender`. MCP ini untuk eksperimen di Blender GUI, bukan dipakai pipeline otomatis (yang berjalan headless dan memanggil API Sketchfab langsung).

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
