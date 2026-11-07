# Ternyata Begini Studio API

Laravel API for Studio authentication, workspaces, YouTube channels, projects, and audit events.

## Local setup

Requirements: PHP 8.3+, Composer, and either SQLite for local development or MySQL/MariaDB.

```powershell
if (!(Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
# Configure DB_* in .env before migrating when using MySQL/MariaDB.
php artisan migrate
php artisan studio:user-create
php artisan serve --host=127.0.0.1 --port=8001
```

The generated local `.env` currently uses SQLite. The committed `.env.example` documents the MySQL settings for the intended database. For MySQL, start WAMP's database service, create the `blender_automation` database, configure `.env`, then run `php artisan migrate`.

The initial owner is created interactively. Password entry is hidden, requires at least 12 characters, and account registration is not exposed through a public API route. This command also creates the default workspace and `Ternyata Begini` YouTube channel.

Run backend tests with:

```powershell
php artisan test
```

The Next.js app reads `STUDIO_API_URL` server-side (default `http://127.0.0.1:8001`). Its same-origin route handler stores the Sanctum bearer token in an HttpOnly cookie, restricts forwarded paths, checks mutation origins, and never includes the token in browser responses.

The script-draft endpoint uses the OpenAI-compatible 9Router API. By default, Laravel reads the key from `../9router-key.txt` relative to this API directory. Override `NINE_ROUTER_API_KEY_FILE` with another API-relative path, or set `NINE_ROUTER_API_KEY` in the server environment. The key is only attached by Laravel to requests sent to `NINE_ROUTER_BASE_URL`; it is never sent to the browser or written to application logs. Configure `NINE_ROUTER_MODEL` to a model returned by `GET http://localhost:20128/v1/models`.

## Current API scope

- `POST /api/auth/login`, `POST /api/auth/logout`, and authenticated `GET /api/me`.
- Membership-scoped workspace and project reads/writes.
- Workspace roles: owner, editor, and read-only viewer.
- Idempotent project creation using an optional client UUID.
- Audit events for account creation, login/logout, and project creation/deletion.
- Login throttling and 30-day Sanctum tokens.
- Rate-limited, workspace-authorized AI script drafts through `POST /api/workspaces/{workspaceId}/projects/{projectId}/script/generate`. Generated text is returned for review and is not persisted until the user explicitly saves the script.

AI-assisted research and fact verification, workflow execution, Blender rendering, TTS, QC, and publishing are not implemented by this API yet.

## Laravel

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
