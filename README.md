# Vocamilo

A multi-user vocabulary workspace built with Laravel 12, Inertia 2 and Vue 3.

## First version

- Register/sign in with email/password or optional Google OAuth; each member owns a private vocabulary pool.
- UTF-8 CSV preview, zero-based column mapping, duplicate detection and import into a collection.
- Personal notes alongside original terms and definitions.
- Collections reuse words without copying them. Pool selection supports selecting all search results, Shift-click ranges and a two-tap range mode for touch screens. Both range endpoints are included; filtering keeps previous selections but resets the range anchor. Clear results affects only matching words; Clear all selections resets everything. Up to 500 words can be added per request; larger selections are rejected without silent truncation, so add them in smaller batches.
- Flashcards, guided study and tests with configurable question direction, answer type and order.
- Anyone holding a random UUID share link can study without signing in. Notes are never included in guest payloads. Disabling sharing revokes the link and existing memberships; enabling it again generates a new link without reactivating old memberships.
- Responsive interface, saved completed Flashcards/Study/Test results and a private collection summary with mode totals, latest results and recent sessions.

There are no teacher/student roles or school integration in this version. Shared collections are unlisted, not searchable public listings. A UUID is a bearer link: anyone receiving it can access the shared material. Revocation blocks future requests, but cannot erase material someone already viewed/copied.

## Requirements

PHP 8.4.1+ for the committed dependency lockfile, Composer 2, Node 20.19+ or 22.12+, npm, SQLite (development) or a Laravel-supported database. PHP needs PDO, SQLite for tests, mbstring, XML, cURL and standard Laravel extensions.

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

Study results are for practice, not proctored assessment. Written answers use deterministic normalization rather than AI semantic grading; definition practice may require self-assessment. Completed owner sessions are saved; incomplete sessions cannot be resumed. Spaced repetition scheduling, password-reset email, email verification, account export/deletion UI, media uploads, public discovery, collaborative editing and Cepidia OIDC integration are future work. The application has not been deployed to a production host.

## Google sign-in setup

Google sign-in is optional. Buttons appear only when all three settings below are present. Existing email/password login remains available.

1. In Google Cloud Console, configure the OAuth consent screen for Vocamilo and create an OAuth client of type **Web application**. This is a separate administrator setup; the repository does not provision it.
2. Add the exact authorized redirect URI, e.g. `https://your-domain.example/auth/google/callback`. For local testing use your exact localhost URL and port. Google requires redirect URI matching; use HTTPS for deployment.
3. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` in the server's private `.env`. Never paste the secret into source control, public issues or chat.
4. Run `composer install`, `php artisan migrate`, `npm ci`, `npm run build`, and refresh Laravel's configuration cache according to your deployment process. The migration adds a nullable unique Google identifier to users.
5. If the consent screen is in testing mode, configure permitted test users in Google Cloud. Verify login, registration, cancellation and linking on the intended HTTPS domain before enabling it for everyone.

The application requests only `openid`, `email`, and `profile`. It does not store Google access/refresh tokens or retrieve avatars. Sign-in uses Socialite's session-state checked authorization-code flow with PKCE. Returning users are identified by Google's immutable subject, not by changeable email addresses. Their local email is not silently changed.

New Google accounts require a verified email claim. Local `email_verified_at` is populated only for Gmail or Google Workspace-hosted identities, where Google is authoritative; third-party Google-account addresses remain locally unverified. If the email already belongs to a Vocamilo account, its existing password must be confirmed in a short-lived, session-bound linking screen. Email equality alone never links accounts. Conflicts and cancelled/expired attempts leave existing accounts unchanged.

Google-only registrations receive a random, unknown password hash rather than a reusable default password. They must continue using Google until a separate password-setting/recovery feature is implemented. Do not remove the `google_id` migration while Google-only accounts depend on it. Google configuration and live provider acceptance must be tested by the deployer; mocked automated tests are not proof of a real OAuth round trip.

References: [Laravel Socialite](https://laravel.com/docs/12.x/socialite), [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [Google email authority guidance](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

## Saved practice history

Authenticated collection owners start a server-backed session. Completion saves each answer and a question snapshot, with server-calculated scores. Flashcards are self-assessed known/still-learning marks, not test scores. Study allows an explicit self-assessment override for written definitions; Test does not. A completed session can be retried safely without duplicate history. Retry practice is a separate session. Maximum session size is 2,000 cards/questions.

The collection page shows private mode totals, latest results, best Test percentage, and ten recent completions. A deleted/edited word does not rewrite historical answers. Deleting a collection or account deletes its history. Private word notes are not copied to history. Started but unfinished sessions have a private question snapshot but no completed result and are excluded from the summary; resume and automated cleanup are not implemented.

Shared-link practice remains temporary, including for signed-in visitors using that link. Add the collection to your account to save future sessions as a member. Each member has private notes and practice history; no visitor results are saved to the collection owner and no owner history is included in public responses. A save error is shown with a retry button; do not close the results page before it reports saved.

Existing installations: back up the database, pull the update, run `php artisan migrate --force`, then `npm ci` and `npm run build`. No queue worker or new environment settings are required. Rolling back the history migration deletes saved practice records.

## Shared memberships, language and exports

A public UUID link still permits guest preview and practice. “Add to my collections” requires authentication and links the original collection, not a duplicate. Email login, registration and Google login resume an explicit join request after validating the link again. Owner edits propagate to members; only the owner may edit words, manage sharing or delete the collection. Members have separate private collection notes and practice results. Sharing revocation removes memberships; re-enabling does not revive access. A person may deliberately join again with the new link. Revocation cannot retract material already viewed or exported.

Collection pages load the newest 15 attached words and fetch more with a server cursor. Full practice and CSV exports still include all collection words. New attachments have an added-at timestamp; pre-upgrade attachments use a deterministic word-ID fallback because their original attachment dates were not recorded.

The header EN | PT switch uses European Portuguese for interface text, not user-authored vocabulary. Signed-in language preference is stored in `users.locale` for use across devices. Guests use a one-year cookie. New registrations inherit their current language. Existing accounts without a saved preference use the browser cookie until selecting a language.

Authenticated users can export their own pool or an accessible collection as UTF-8 CSV with `term,definition,note` columns. Collection exports contain only the requesting learner's notes. Spreadsheet-dangerous cells are prefixed with an apostrophe for safety, which may remain visible when imported into other software. Exports are private downloads, never public stored files. The sidebar Import item is removed; Import CSV remains available from the word pool.

Upgrade: back up the database, pull this version, run `php artisan migrate --force`, rebuild assets with `npm run build`, and clear compiled views/config according to your deployment process. This version adds collection memberships/private notes, attachment ordering and a nullable user locale. No worker or new credentials are needed. Rolling back these migrations removes memberships, private member notes and locale preferences.

## Install-only PWA

The web manifest and branded icons let supported browsers install Vocamilo as a standalone app over HTTPS. Use the sidebar “Install Vocamilo” button when an install prompt is available, or the browser's install menu. On iPhone/iPad use Safari → Share → Add to Home Screen. Browser support, engagement requirements and UI vary; a button cannot force installation. Real-device installation and Google sign-in in the installed app must be tested on the deployed HTTPS host.

This version intentionally registers **no service worker**, enables no offline mode or push notifications, and adds no Cache Storage/IndexedDB copies of private pages or answers. Normal login/session handling is unchanged. An internet connection is required. Static manifest and app icons contain no user information. See [MDN installability guidance](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable).
