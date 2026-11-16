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

## Optional Study read-aloud

Choose an installed local voice in collection creation/edit or the collection’s personal Voice settings. Study and Test have only a Read aloud switch; Flashcards places it beside Add/Edit note and reads the visible card face. All switches start off. Choose a voice matching your vocabulary’s language, then enable it; the interface language does not determine pronunciation. Questions and selected answers are spoken using the browser's Speech Synthesis API; written answers are read on Check. Changing question, switching off or leaving the page cancels prior speech.

Only voices explicitly reported by the browser as `localService: true` are offered, with no network voice fallback and no server speech endpoint. If the API or local voices are unavailable the control explains why and stays disabled. Device voices can load asynchronously; availability and pronunciation depend on the browser/OS. Real-device audio and installed-app behavior still need acceptance testing. Read-aloud starts disabled whenever any practice module is opened. Flipping a flashcard cancels the previous speech and reads the newly visible face.

## Study repetition and next-session priority

New Study sessions prioritize words not yet studied or answered incorrectly on the learner's latest first attempt in this collection. Known words fill only the remaining requested places; this does not affect Test/Flashcards. Guest priority lasts only while the current page stays open, and member histories remain separate.

A wrong first Study answer schedules a repeat behind pending questions. A correct first repeat ends repetition; an incorrect first repeat gets one final repeat. New sessions (rules version 3) allow at most two extra attempts, even if the last is wrong. Existing version 2 sessions retain their original validation until completed. The first-attempt score is preserved; repeated answers are recorded separately and cannot inflate it. Correcting a word during repeats does not lower its priority for the next Study until its first attempt in a later session is correct. Self-assessed equivalent written definitions keep their existing behavior.

“End study without saving” lets the learner leave an unfinished round without claiming completion. No session is saved as completed until the required queue ends. This release adds an attempt/rules-version migration; existing completed history and already-started legacy sessions retain their old format. Back up the database, deploy the migration and rebuild assets together. Reload old open Study pages after upgrading to use the new rules.

The last explicitly selected local voice is remembered in this browser’s localStorage per user (or guest) and collection. It does not sync across devices. Speech always starts switched off. A missing or non-local saved voice is not used; choose an available local voice instead. Blocked browser storage does not prevent Study.

Study settings allow one or both question directions and answer styles. Selected combinations are mixed in shuffled cycles without duplicating words or changing the requested word count. Each switch group must retain a choice. With local speech enabled and a voice selected, the question itself is a keyboard-accessible replay button. Collection progress keeps the mode result cards and shows aggregate completed-session counts instead of the recent-session list.

Flashcards support horizontal touch swipes after revealing the answer: left = Still learning, right = I know this. Buttons and keyboard activation remain available; vertical scrolling is not intercepted.

New Test sessions use mixed settings, local speech, circle answer controls and the same two-extra-attempt cap as Study, while correctness indicators and review stay hidden until completion. Scores use first attempts only. Test remains objectively graded (no self-assessment), uses its configured order rather than Study history prioritization, and is a practice tool: repeated questions can indirectly reveal earlier errors, and client-side questions are not a secure-exam mechanism. Existing v1 Test sessions retain old server validation; reload existing tabs before starting new sessions after deployment.

Mode summary cards use each currently attached word’s latest recorded first attempt in a completed session for that learner and mode. Correct counts replace older wrong results; automatic retries do not overwrite the first attempt. The percentage denominator is all currently attached collection words, including unseen words. Empty collections display a dash; nonempty collections without correct recorded attempts display 0%. Flashcards are self-reported known; Study may include accepted equivalent definitions. Legacy answer rows have attempt_no=1; records with deleted/null word IDs or absent answer rows are excluded rather than guessed. Historical session totals remain available separately.

## Private notes while using flashcards
Signed-in learners can Add/Edit note without leaving Flashcards. The editor fetches the latest value, saves by authenticated collection access, reports success/failure, and preserves the draft after a failed save. Guest/public-link sessions never expose private notes; join/open the collection in your account first.

Owner notes keep using the existing word-pool note. Member notes are stored per user + word, so the same word in multiple accessible collections has one personal note. Different word records with identical text remain separate. Notes are included only in the caller's private views and CSV; public views omit them.

Deploy migration `2026_11_14_000002_create_user_word_notes` after a DB backup. It copies the newest legacy member note per user/word (updated_at, then highest ID), respecting intentional empty clears. Every legacy collection_notes row is kept as a conflict archive. New edits use the global note table. Before rolling this migration back, export/back up the new table: rollback removes new global edits and the archived rows are not updated automatically.

CSV import matches only your own pool using trimmed, case-insensitive term text (accents preserved). A unique match updates the definition while retaining ID, spelling, notes and collection links. Multiple existing matches or conflicting definitions for one term inside the CSV are skipped with warnings; identical CSV repetitions collapse. Unchanged words may still be attached to the chosen collection. Preview lists actions and counts. Commit revalidates matching IDs/terms/definitions and aborts the entire batch if relevant pool contents changed; preview again. Concurrent note edits are retained. CSV does not import personal notes.

Shared voice settings change only the current browser's current user (or guest) preference. They never send a collection update, edit its words, or change the owner's settings. Guest and signed-in keys are separate: signing in does not adopt a guest voice preference. Guest public links allow practice without persisting notes/results; authenticated members can save only their own notes/results through authorized routes, never owner content.

## Sharing management
Owners can switch link sharing on/off, replace the UUID link, select guest or signed-in access, and manage joined accounts. Existing collections keep guest access through migration `2026_11_14_000003_add_collection_sharing_controls`.

Replacing a link invalidates its old URL and pending joins but keeps current members and their private history. Turning sharing off revokes current memberships; turning it back on requires joining again. Removing a member allows them to rejoin using an active link. Blocking an account detaches it and denies public and member access, including notes, export, practice and result completion, even after link replacement. Unblocking does not restore membership automatically. Private history/notes are retained, not transferred to the owner.

Guest mode cannot identify signed-out visitors, so a blocked person can still view while signed out. Use signed-in access when account blocking must apply. No access-control change can retract content already downloaded by a visitor. Joined/blocked lists expose names only to the owner; guest traffic is not tracked as an account list. Back up the database before migration; rolling it back removes blocking and access-mode controls.

### Member management and remembered practice setup

Collection owners open **Manage members** from sharing settings. Joined and blocked accounts have independent 15-row pages and a name-only search. Emails, notes and learning history are not included. The management route remains owner-only; removing, blocking and unblocking retain the access rules described above.

Study and Test remember question direction(s), answer style(s), order and question count in this browser's local storage, separately for each account (or guest), collection and mode. Public-link sessions use the current link token as their collection scope. Guest choices are not inherited after login. Invalid/obsolete values fall back to safe defaults, and question count is capped to currently available words. Clearing or denying browser storage resets persistence but does not prevent practice. Read Aloud always starts off; voice preferences remain separately device-local. This update adds no database migration.

### Administrator access and member progress consent

After this update, existing and new accounts (including Google sign-in) must explicitly accept the administrator-access disclosure before using authenticated pages or APIs. Previously saved data is retained; it is not treated as consent. The disclosure can be revisited and withdrawn, which signs the person out and hides their data from future administrator views until they accept again. Guests can continue using guest-enabled shared links without storing results.

Administrators use separate, sanitized management pages. They can search accounts, suspend/reactivate non-administrator accounts, inspect collection metadata and vocabulary, edit collection titles/descriptions, and disable sharing. There is no administrator private-note or export endpoint, no web role assignment, and no permanent deletion action. Administrator collection list/detail/change access is recorded in `admin_audit_logs` with actor, target identifiers, action and time, not word/note content. Accounts that have not accepted the current disclosure are excluded from administrator views. Suspension blocks authenticated access; it cannot identify someone browsing as a signed-out guest.

Collection membership requires a separate, unchecked versioned consent to show **past and future results for that collection** to its owner and allow the owner to find the member by name or email fragments. Existing members must make this choice on their next private collection access. Owners see aggregate progress only for currently joined, unblocked, unsuspended members who accepted both disclosures. Personal notes, raw answers and other collections' results are never included. Members can revisit the consent page and leave to stop future owner visibility; history and notes are retained privately and are not deleted. Rejoining with consent can expose the retained results again. Withdrawing access cannot retract information somebody already saw. Pending guest-join consent expires after 30 minutes and is cleared by the explicit decline action, including when returning from login.

These controls apply to application routes, not to people with direct database/server access. Private notes remain stored in the database, not end-to-end encrypted. Audit records are retained without an automatic expiry; protect and back up the database and restrict server access. Rolling back these migrations loses the new consent, administrator-role and audit fields; take a database backup before migration or rollback.

Deployment: back up the database, pull the code, run `php artisan migrate --force`, rebuild frontend assets, and clear any stale Laravel caches. No account is automatically made an administrator. To deliberately appoint the first administrator, an authorized operator runs the following **interactively on the server**, naming an existing active account and confirming the displayed email:

```sh
php artisan vocamilo:first-admin owner@example.com
```

Replace the placeholder with the intended account. The command rejects non-interactive execution and refuses if an administrator already exists. It does not bypass the access disclosure. The assistant has not run this command against a deployed database or assigned any real account a role.

### Management search

Member Progress searches names or email fragments only for active members who explicitly accepted version 2 of the collection sharing disclosure. Email addresses are not rendered, but a match can let the owner infer an address; this is disclosed before consent. Earlier progress-only consent is insufficient: existing members must reconsent before appearing in progress results, including counts and pagination. Old open forms and pending login intents cannot silently upgrade consent. Administrator account search supports partial names or email addresses, and administrator collection search matches titles. Searches trim outer whitespace and treat `%`, `_`, `!` and backslash as literal characters rather than user-controlled SQL wildcards. Search submission resets pagination; Clear removes the filter. Existing privacy/consent filters still apply, so an account that has not accepted the disclosure does not appear merely because its name or email matches.

Matching uses `LOWER` on both the database field and bound search term. ASCII case-insensitive behavior is tested even with case-sensitive SQLite LIKE. Unicode case/accent matching remains dependent on the database's Unicode support/collation (stock SQLite LOWER is ASCII-only); the deployment's actual MySQL/MariaDB collation has not been inspected or changed.

### Named word pools and one-time legacy assignment

The `/words` page lists pools only. Open a pool to manage its words at `/word-pools/{id}`; old `/words?pool_id=...` links redirect to that pool. Create a named pool with its vocabulary language (for example `pt-PT` or `en-US`). A word can belong to multiple pools without being copied: its definition and owner's note remain one record. Removing it from a pool, or deleting a pool, does not delete the word, collection links, shared memberships, personal notes or study history. A different meaning can be added as a separate word even when the spelling is identical.

CSV preview requires an owned target pool. Matching is confined to that pool; ambiguous duplicate terms are skipped rather than guessed. A unique matching record updates its definition everywhere that same word is reused. Two columns are sufficient. Preview binds the target pool and membership/definition snapshot; changes before confirmation require another preview. Exports are scoped to the selected pool and neutralize spreadsheet formulas.

Migration `2026_11_14_000007_create_word_pools` only adds pool tables and the nullable collection voice-language field. It does not automatically assign existing words or alter sharing links, memberships, consent, notes or results. Existing collection contents continue to work. The pool list may initially be empty until a pool is created and old words are assigned.

For the explicitly chosen existing account only, first create its desired pool in the application, then use its exact email and pool ID (visible in `/word-pools/POOL_ID`). Back up the database before migration or applying the one-time assignment. Run the preview:

```bash
php artisan vocamilo:assign-legacy-words 'EXACT_ACCOUNT_EMAIL' POOL_ID
```

Check the displayed account, pool and word count. Only then apply:

```bash
php artisan vocamilo:assign-legacy-words 'EXACT_ACCOUNT_EMAIL' POOL_ID --apply
```

The command refuses a pool belonging to another account, attaches only that account's unpooled words, and is safe to repeat without duplication. It never copies or deletes words and does not modify any collection membership or share token. No production account is selected automatically.

Collection voice language is a suggestion preference, not a translation. The personal device voice picker ranks available local voices by exact language/dialect and then language family. It never replaces a saved choice or enables speech automatically. If no matching voice is exposed, the picker says so; Android/browser voice variants are not guaranteed. Mixed-language collections can choose a different default language and personal voice.

Joined users can leave a collection after confirmation. This removes membership and the owner's access to their shared progress, but retains private notes and historical results. An active public link may still allow guest viewing. Rejoining requires fresh current consent; retained history can then be visible again under that consent.

Deploy with a database backup and a short maintenance window: `php artisan down`, `git pull --ff-only`, `php artisan migrate --force`, `npm run build`, `php artisan optimize:clear`, then `php artisan up`. Perform the explicit one-account assignment separately after creating the target pool. No background worker is required.

Collection CSV downloads are owner-only. Joined users, guests and administrators who do not own the collection cannot export it. The word-pool detail page retains its CSV export action, scoped to that pool’s owner.

### Writing direction (LTR / RTL)

Pool creation and editing offer a writing-direction choice. New pools can suggest RTL for languages such as Arabic, Hebrew, Persian and Urdu; explicit choices and existing pool settings are never silently replaced. Migration `2026_11_14_000008_add_text_direction` adds `text_direction` to pools and words, defaulting all existing records to `ltr` without changing words, sharing links, memberships, consent or results.

One word record cannot simultaneously belong to LTR and RTL pools. Reuse and pool direction changes validate the entire affected set before applying any update. Import, word creation, reuse, pool editing and legacy assignment serialize on the owning account row; a conflicting change fails without partial assignment. Unpooled words can adopt the target pool direction, while removing the last pool or deleting a pool retains each word's current direction for its existing collections. CSV previews are invalidated by direction changes.

Pool direction is the fallback presentation direction. Each word, definition and choice is independently bidi-isolated and detects its own text direction, so an Arabic term with an English definition does not reverse the application controls. EN/PT interface navigation remains unchanged. Flashcards and both practice modes carry per-word and per-option directions; saved sessions snapshot those values without altering answer matching, scoring or repeat logic. Historical sessions without direction metadata fall back to LTR with automatic text detection.

Deploy with the standard database backup and maintenance steps, including `php artisan migrate --force` before using the updated application. This change does not require rerunning legacy assignment.

### Remember Google sign-in

The login page's unchecked-by-default **Keep me signed in** choice applies to both password and Google sign-in. Registration has a separate Google-only choice. Google starts through a CSRF-protected POST; the choice is stored server-side, bound to that OAuth state's ten-minute flow, and consumed once. Callback/query parameters cannot enable remembering. Legacy GET links still work without remembering. Existing-account password confirmation preserves the trusted choice; cancellation, expiry and failures clear it. Successful authentication regenerates the session.

Checked Google sign-in uses Laravel's normal encrypted remember cookie and server-side remember token. The installed framework's default cookie duration is **576,000 minutes (400 days)**, not permanent; browsers can shorten retention or clear storage. Unchecked sign-in does not issue a persistent remember cookie and removes a stale one; normal session settings still apply (`SESSION_LIFETIME` defaults to 120 minutes, `SESSION_EXPIRE_ON_CLOSE` defaults to false). Therefore unchecked does not itself promise immediate logout on closing a browser. Explicit logout clears the session and cookie and rotates the remember token so the old cookie cannot restore authentication. It does not promise to terminate every other already-active session.

Use HTTPS and `SESSION_SECURE_COOKIE=true` in production. Cookies are HttpOnly and use configured SameSite settings (default lax). Remember only on a trusted device. An installed PWA relies on the browser/platform cookie store, with no credential stored in localStorage or an offline cache; separate browser/PWA storage, private mode or cleared cookies can require signing in again. Real Google OAuth and browser/PWA restart behavior still need deployment/device verification; automated tests mock Google's HTTP responses and exercise real state/PKCE, CSRF, recaller restoration and logout invalidation.

This update needs no migration or Google Cloud setting changes. Deploy with `git pull --ff-only`, `npm run build`, then `php artisan optimize:clear` using the existing deployment environment. Existing installations that skipped earlier migrations must apply those separately.

### Study learning stages (rules version 4)

New Study sessions revisit a correctly answered word after the remaining queue whenever possible. Each word stops after two correct answers or three total presentations, whichever comes first. A one-word round necessarily repeats immediately. When Written answer is selected, repeats prefer that style; multiple-choice-only Study rounds retain multiple choice, even when only one distinct answer is available. Such a question offers little recall evidence, so use a larger collection for meaningful distractors. Test and older open sessions keep their original rules, including their insufficient-options fallback.

Two correct answers in a completed Study round establish **Learning**. A later, separately started and completed round can establish **Reinforced** when the earlier qualification was already present at its start and both answers are correct. Any wrong answer prevents reinforcement in that round: two correct answers in total can restore Learning within the three-presentation limit; otherwise the word Needs practice. These are practice indicators, not a guarantee of long-term recall. The first-attempt percentage remains separate; extra answers never inflate it.

New sessions reserve roughly 20% of requested words for reinforced review (rounded, at least one when requesting two or more), preferring the least recently reviewed. A one-word request reserves a review every fifth completed version-4 round; reinforced words also fill spare capacity when there are too few unreinforced words. The remainder prioritizes words that need practice, then qualified Learning words. Progress is private to the user and collection. Only completed version-4 sessions establish these stages; older answers are not guessed into qualifications. Unfinished rounds do not update progress. Concurrent rounds retain their question snapshots; an older baseline cannot overwrite a newer completed progress update. Saving a completed round again is idempotent. Historical first-attempt results remain intact.

Guest stages exist only in the current page's memory and advance only after a whole round finishes; they are not saved or transferred to an account. Refreshing or leaving the page clears them. No migration is required for this update: versioned session question metadata and recorded answers carry the learning state. Deploy with `git pull --ff-only`, `npm run build`, and `php artisan optimize:clear`. Previously skipped migrations are still required separately.

### Collection word pages

Collection word lists show 15 words per page, newest collection additions first with a stable word-ID tie-breaker. First, Previous, Next and Last controls replace Load more; Study, Test and Flashcards still use the complete collection. The page is carried in `words_page`, independently of the owner's pool-selection filters. Removing a word from a collection preserves the current page, clamped to the new last page if necessary; it does not delete the original pool word. Empty collections remain on page 1. Owner, joined-member and public lists retain their existing access and private-note rules.

This pagination update requires no migration. Pull the code, run `npm run build`, and clear Laravel caches with `php artisan optimize:clear`.

### Optional CSV personal notes

Imports accept an optional third column containing the importing account's personal word note. Two-column files still work. A nonblank mapped note creates or updates that owner's note; a missing or blank note leaves an existing note unchanged. Notes are not translated and do not replace another member's separate private note. They follow the existing word identity across the owner's pools and collections, without becoming visible in public/shared word data.

Preview identifies note-only updates as changes. Conflicting nonblank notes for repeated copies of a term are skipped with a warning rather than choosing one silently; blank copies and one unique nonblank note can be combined. UTF-8 and the existing 5,000-character note limit apply. A note edited after preview invalidates the preview, just like other word changes. The preview version changes, so files previewed before this release must be uploaded again; this does not modify saved words. No database migration is required.

### Written-answer letter boxes

Study and Test written answers use grapheme-aware boxes with deterministic hinted letters (about 40%, including three hints for seven-letter answers; short answers never reveal every letter). Spaces and punctuation are preserved. Typing continues through editable boxes without refocusing each one; fixed positions are skipped. Backspace removes the current entry or moves back to the previous editable position when empty. Paste and IME composition are handled without splitting combining marks. Completing the boxes does not automatically submit an answer.

The reconstructed answer is still checked against the session's saved expected answer. First-attempt scoring, Study retry rules and historical session compatibility are unchanged. Matching remains Unicode NFKC plus invariant lowercase and whitespace normalization; accents, Arabic vowel marks and punctuation remain significant. Client matching no longer depends on the device's lowercase locale. Private notes are not incorporated into the answer template.

Hints and answer length provide additional assistance, so these practice Test scores are not directly comparable to unaided historical written tests. Test already includes expected answers in the browser's practice snapshot and is not a secure examination; the letter boxes do not make it one. This is an input presentation change, not stronger proof of mastery. No migration is required: pull, rebuild frontend assets with `npm run build`, then run `php artisan optimize:clear`.

The template normalizes compatibility forms with NFKC before splitting graphemes, allowing standard keyboard forms of ligatures and Arabic presentation glyphs. Incrementally typed vowel/combining marks remain attached to the preceding entered grapheme. Normal-length answers wrap; answers above 240 graphemes, answers without editable letters/digits, or browsers without `Intl.Segmenter` use an explained plain-text fallback. Arabic boxes show individual graphemes rather than joined handwriting. Live IME pre-edit text is displayed in the boxes before composition commits, without scoring or advancing prematurely. The duplicate visible answer line is omitted while screen-reader feedback is retained. Mobile layouts fit eight cells per row, with longer answers wrapping and a minimum 44px cell height. Actual Android keyboard and screen-reader behavior has not been device-tested. Automated input/composition tests pass, but the isolated browser could not launch because of the environment's socket restrictions.

### Rearrange letters, live practice feedback and Test skips (rules version 5)

New Study and Test sessions can combine Multiple choice, Written answer and Rearrange letters. Rearrangement uses individually identified grapheme tiles, so repeated letters are separate choices; spaces and punctuation remain fixed. Tap or keyboard-activate an available tile to place it, and activate a placed tile to return it. Content direction remains independent of the interface language.

Study gives position feedback for committed written/rearranged letters. Very long text (over 240 graphemes), missing grapheme segmentation, or text without usable letter tiles falls back to a plain written input with an explicit notice: live position feedback is unavailable there and grading happens on submission. A committed mistake is remembered for that presentation even after correction: the final correction cannot turn its first-attempt score into a correct answer. IME draft text is not a committed mistake. Normal bounded Study repetition and separate learning-stage rules continue. Test does not show position correctness while answering. Its Skip action ends that word's attempts; a skipped first attempt stays in the score denominator and is reported separately from incorrect first attempts. Skipping a later retry does not replace the original first-attempt result.

This remains a personal practice tool, not a secure exam. Expected answers already exist in the browser snapshot; letter banks and hints inherently disclose information. The server uses its saved expected answers for grading and validates the submitted protocol, but cannot independently prove a client-reported live input history. Older sessions retain their original rules. Guest practice stays local and unsaved; membership and personal-note access controls are unchanged.

Deploy the new additive answer/session flags migration along with the code, rebuild assets, and clear caches. Reload open practice tabs before starting a new round. Physical Android/IME and screen-reader testing remains unverified in this isolated environment.

### Study automatic checking and Skip for now (rules version 6)

New Study written/rearranged questions automatically check an exact completed answer after input commits, then wait for Continue. A remembered placement error still makes that presentation incorrect, even if the completed answer is now exact. Composition drafts never trigger checking. Manual Check remains for older sessions and Test; Test correctness remains hidden until its result screen.

Study's **Skip for now** replaces Check for these input styles. The first skip puts the word after the remaining questions; a second skip ends that word for the round as not yet learned. Skips are counted separately from the maximum three answered presentations, with the word stopping as soon as either limit (or two correct answers) is reached; at most four presentations can actually occur. Two correct answers may establish Learning, but a skip or mistake prevents Reinforced in that round. First-attempt scores never change when a skipped or incorrect first presentation is corrected later. With only one word, a repeat can appear immediately. Existing Test skip behavior is unchanged.

This uses the existing `000009` feedback columns and adds no migration. Deploy frontend and backend together, rebuild assets, clear caches and reload open practice tabs. Physical Android/IME verification is still outstanding; automated composition, queue and server protocol tests cover the new behavior.

### Study first-placement accuracy (rules version 7)

New Study written/rearranged answers must still be completed and corrected to the exact expected answer. Success is then based on at least **80% of editable positions being correct on their first committed entry**. Given letters, spaces and punctuation are excluded from the denominator. Four editable positions with one initial mistake is 75% and fails; five with one initial mistake is 80% and succeeds. Editing or removing a filled position does not reset its first-entry history. IME draft text is not an entry, and combining-letter continuations are handled before recording a completed grapheme.

The server validates the layout, editable indices and first-entry values against its saved question and computes the threshold using integer arithmetic. The browser still reports the input history, so this is a practice measure, not tamper-proof evidence. Written plain-text fallbacks cannot provide position history and retain conservative exact-answer grading with an explicit explanation. Test grading and older Study versions are unchanged. Study's two-skip rule, first-presentation statistics and separate Learning/Reinforced progression remain in force; a tolerated placement mistake counts as successful only under the new 80% rule.

No migration is added by this scoring version. Deploy both code and rebuilt assets together and reload open practice tabs. Actual Android keyboard and screen-reader verification remains outstanding.

### Live composition feedback

Study letter boxes now show provisional correct/incorrect placement colors and icons during IME composition, not only after composition ends or focus changes. The preview is explicitly distinguished in accessible labels. Previously the text preview updated but a `!draft` display guard hid all placement feedback during long compositions. This display-only correction does not commit draft input, record first-entry accuracy, or auto-check the answer early. Test remains neutral until its results. Gboard-like long composition sequences are covered by automated event tests; an actual Android device has not been verified.

### Completing a Study answer during long IME composition

For current Study letter scoring, a fully correct composition preview can display the result and Continue before the keyboard ends composition. It remains reversible: changing the draft withdraws/recalculates the candidate and cancelling it discards staged input history. First-letter entries are tracked in a fork of the committed history, so mistakes within a long composition are preserved if that composition is accepted, without permanently scoring cancelled drafts. Continue explicitly finalizes the current candidate once before advancing. Test and historical Study sessions do not expose early completion.

This is a frontend-only change with no migration. Automated long-composition, cancellation and stale-event tests substitute for, but do not establish, physical Android/Gboard verification.


### Study keyboard dismissal on completion

For current Study letter scoring, a complete exact answer is finalized before the answer input is blurred to request mobile-keyboard dismissal. This includes long composition drafts: their staged first-entry history is accepted once before blur can trigger composition-end/cancellation events. The result and Continue remain subject to the 80% first-placement rule; completing the corrected word does not erase earlier mistakes or automatically advance. Wrong or incomplete input and Test do not trigger this keyboard dismissal. Browser/OS keyboard behavior still requires physical-device validation.
