# Vocamilo

A multi-user vocabulary workspace built with Laravel 12, Inertia 2 and Vue 3.

## First version

- Register/sign in; each member owns a private vocabulary pool.
- UTF-8 CSV preview, zero-based column mapping, duplicate detection and import into a collection.
- Personal notes alongside original terms and definitions.
- Collections reuse words without copying them.
- Flashcards, guided study and tests with configurable question direction, answer type and order.
- Anyone holding a random UUID share link can study without signing in. Notes are never included in guest payloads. Disabling sharing revokes the link; enabling it again generates a new link.
- Responsive interface and separate progress in each study session.

There are no teacher/student roles or school integration in this version. Shared collections are unlisted, not searchable public listings. A UUID is a bearer link: anyone receiving it can access the shared material. Revocation blocks future requests, but cannot erase material someone already viewed/copied.

## Requirements

PHP 8.2+, Composer 2, Node 20.19+ or 22.12+, npm, SQLite (development) or a Laravel-supported database. PHP needs PDO, SQLite for tests, mbstring, XML, cURL and standard Laravel extensions.

## Local setup

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
php artisan serve
```

Visit the URL printed by Artisan and register a new account. No seeded passwords or real user data are included. For frontend development run `npm run dev` in another terminal.

## Tests

```sh
php artisan test
npm test
npm run build
```

Tests use an isolated in-memory SQLite database. Never point the test configuration at production. CI repeats PHP tests, frontend tests and the production asset build.

## CSV format

```csv
term,definition
abundant,"Existing in large quantities"
resilient,"Able to recover after difficulty"
```

Choose whether the first row is a header and the term/definition column numbers. Comma-separated UTF-8 is supported, including a UTF-8 BOM and quoted multiline fields. Maximum upload: 2 MB / 2,000 records. Terms are capped at 500 characters; definitions and notes at 5,000. Rows with missing required values or invalid UTF-8 cause validation feedback rather than partial import. Preview lasts 30 minutes. Duplicate term/definition pairs are skipped. Different definitions for the same term remain separate entries. CSV files are not retained as public assets.

## Deployment and privacy

- Set `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL` and secure session cookies.
- Keep `.env`, `APP_KEY`, database files, logs and uploaded/private data outside source control and public web access. Serve only Laravel's `public/` directory.
- Use a persistent database/session store; do not share production data with development tests.
- Back up both database and application configuration securely and verify restoration before relying on backups.
- Application routes enforce owner scope independently of UUID unpredictability. Guest responses exclude personal notes and owner information.
- Authentication/import routes are rate limited. Configure trusted proxies and HTTPS for your actual deployment.
- Private/guest pages return no-store and noindex headers. Search-engine directives are advisory, not access controls.

## Current boundaries

Study results are for practice, not proctored assessment. Written answers use deterministic normalization rather than AI semantic grading; definition practice may require self-assessment. Refreshing a session may reset progress. Cross-device history, spaced repetition scheduling, password-reset email, email verification, account export/deletion UI, media uploads, public discovery, collaborative editing and Cepidia OIDC integration are future work. The application has not been deployed to a production host.
