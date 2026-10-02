# SM Manager — launch runbook

Updated 2026-10-02 for `hemantsatishjadhav06-ai/calander-6`. PR #12 is merged
to main at `d64232c1da4e22d329671eaa5737c182a86fd655`, containing the tested
`aff7418` runtime. Railway release `d9a09d24` succeeded with 48 migrations,
the original `APP_KEY` preserved and writable persistent storage owned by
UID 9999. Normal live sign-in, both owner workspaces and private browser
acceptance have passed. The same-image release `f41a5e60` enabled the worker,
scheduler and SSR. A private storage fixture and both Passport keys survived
the redeploy with matching hashes; the fixture was then removed. Live SSR and
HTTP health checks passed, with all application processes running as UID 9999.

| Area | Verified status on 2026-10-02 | Remaining acceptance |
| --- | --- | --- |
| SM Manager | Railway release and background/SSR redeploy succeeded; normal login and repeatable owner setup produced two brand workspaces, 14 private social drafts and two private blogs. Live desktop/mobile private acceptance passed. | Connect the actual social accounts and review the private drafts. |
| Runtime | Live service has 48 migrations, unchanged application/Passport keys and UID 9999 processes. Private storage survived a redeploy; web and SSR health checks passed. Inbox, direct messages, engagement and metrics are enabled. | Provider-specific live acceptance follows official consent. |
| Recovery | PostgreSQL and app-volume daily/weekly/monthly backups are enabled. Both post-setup manual backups exist; the private `pg_dump` 18 backup was restored in isolation, and storage/keys survived redeployment. | Maintain scheduled backups and repeat restore drills before risky changes. |
| Website publication | Exact owner/site flags and token are configured; live private approval, edit invalidation and stale-version publish denial passed without dispatching a job. | Publish only content approved by the owner and explicitly submitted for publication. |
| More Space website | Assets, public calculator, contact/WhatsApp fallback and four verified project renderings are live in deploy `6abfd4e4869e05df63998574`. Production desktop/mobile checks passed. | Replace the unavailable Supabase backend. |
| Social providers | Confirmed brand identities are recorded. An existing X account belongs to a different workspace and is preserved. | Configure the Meta app, obtain official owner consent in each workspace, and create More Space's X account. |
| Email | Production currently uses the log mailer. | Configure a delivery service and prove reset, verification and invitation delivery. |

The live release uses free self-hosted access (`SELF_HOSTED=true`).
Subscription billing requires a separately configured and tested Stripe
release. Private testing is live; public launch still needs
the remaining provider, email and website-backend checks.

## Brand setup and content review

The authenticated **Brand setup** screen prepares separate Neopolis and More
Space workspaces with the confirmed website, Instagram and Facebook details.
It requires the current workspace owner and respects the instance's workspace
creation setting. Setup is repeatable and preserves existing brand metadata
and articles. It creates seven private social drafts and one private blog
draft per brand, based on the public websites, with proposed posting times at
10:00 Asia/Kolkata over the next seven days. These dates do not activate the
scheduler. Social drafts initially have no destinations: connect and select
the correct real accounts, add required media, and request review.

Both prepared workspaces require owner approval. Approval binds the content,
destination identity, media, format and intended publication time. Edits and
reverts, owner membership/ownership changes and policy changes clear previous
approval. Web, API, MCP, scheduler, retries and publishing jobs enforce the
same gate. Protected automatic reposting is blocked pending separate review.
Public share links hide unapproved revisions, including old links. Active
publishing prevents authority changes until the publication attempt finishes.

Blog authoring, SEO fields, private preview, review, approval and rejection
are implemented. Preview responses require workspace access and use no-store
and noindex. Blog text is escaped; preview does not fetch remote images.
Slugs are unique within each workspace, with validation inside the workspace
lock and a database constraint. Approval alone keeps the article private.
The owner must separately choose **Publish approved version** to queue a
website deployment.

The Netlify publisher checks the approved revision, owner email, site ID and
website URL before creating a full-site draft deployment. It preserves the
current file manifest, existing blogs and hidden configuration, verifies the
immutable article preview and complete deployed manifest, then checks the
approval and live-site state again before promotion. Shared site locks and
persisted recovery state handle retries and interrupted deployment attempts.
Unsupported functions, edge functions or compiled configuration fail closed.
Netlify does not expose an atomic conditional promotion; a separate operator
forcing a deploy can race the final promotion. Reconcile that remote state
before retrying an ambiguous outcome.

Publication requires `BLOG_PUBLISHING_ENABLED=true`,
`BLOG_PUBLISHING_OWNER_EMAIL=neopolisinfrallp3@gmail.com` and the securely
configured `NETLIFY_AUTH_TOKEN`. The allowlist is in `config/blogs.php`:

| Brand | Website / Netlify site ID | Confirmed social identities |
| --- | --- | --- |
| Neopolis | https://neopolisinfra.com · `47e0a5cc-d9d9-428b-a36b-beea806bff6f` | Instagram `@neopolis_infra`; Facebook Page `61595008380228`; X `@neopolisinfra` |
| More Space | https://morespace.netlify.app · `964e086b-1cf2-47f7-8b78-16909d268319` | Instagram `@morespace.ai`; Facebook Page `585141221346435`; X account still to be created |

The requested owner and sole draft approver is
`neopolisinfrallp3@gmail.com`. Normal authenticated HTTP setup was run twice
and proved idempotent. The prepared owner workspaces are:

| Brand | Owner workspace ID | Prepared private content |
| --- | --- | --- |
| Neopolis | `01a0fd53-1047-72b3-b25e-c21075b93a8b` | Seven social drafts and one blog |
| More Space | `01a0fd53-10f5-710c-9472-d548dfe8ddca` | Seven social drafts and one blog |

Both workspaces currently have zero connected accounts. All 14 social drafts
remain drafts; both blogs are idle, unapproved and have no published URL.
Keep setup links, passwords, cookies and provider secrets outside this file.

OAuth connection intents now bind the initiating session, user and workspace;
switching brands during authorization cannot attach credentials to another
workspace. Bluesky account connections verify the DID's canonical PDS before
accepting credentials.

Both sites are verified manual/static Netlify deployments. A GitHub merge
does not establish an automatic Netlify release. Neopolis's current full tree
contains existing blogs and project pages; preserve the complete manifest
rather than using its single-file homepage builder. More Space's source is
`hemantsatishjadhav06-ai/morespace-website`. Website PR #3 is merged and its
asset/calculator release is live. PR #4 is merged at `995cbd9`; its complete
enquiry fallback is live in Netlify deploy `6abfd1b9f285aa14735cc970`.
PR #5 is merged at `20f6b1f`; its four verified project renderings are live in
`6abfd4e4869e05df63998574`, with all 32 manifest entries and the immutable
preview and production desktop/mobile views checked. Renderings retain their rendering labels and
verified project attribution.

More Space's configured backend hostname,
`aszxypvnndlzzdmzwkrr.supabase.co`, returns NXDOMAIN through both Google and
Cloudflare DNS-over-HTTPS and fails DNS lookup from Railway. This is a missing
backend, not proof of a browser allowlist problem. Database-driven inventory,
saved enquiries and backend-dependent chat need a current project or a
replacement backend. Static website content, the cost calculator and
WhatsApp navigation remain usable. The PR #4 fallback preserves the complete
encoded enquiry and offers a persistent WhatsApp link when saving or opening
a popup fails; opening WhatsApp does not itself send the enquiry.

The supplied provider credentials now work through authenticated official
APIs. This Railway account token uses `Authorization: Bearer` at
`backboard.railway.com/graphql/v2`; Netlify uses Bearer authentication at
`api.netlify.com`. Tokens are handled outside repository files and logs.
The user must still complete real provider verification and consent where
required. No new social connections, messages, posts or blog articles have
been publicly published during these checks.

## Target and verified scope

Authenticated Railway inspection on 2026-10-02 verified the production
project, service, environment, running instance, variables and PostgreSQL
volume. The new release has persistent app storage at
`/var/www/html/storage`, with all 48 migrations applied. Git auto-deploy was temporarily disabled to
control the authorized rollout; restore its prior enabled state after the
tested release is stable.

| Item | Reference |
| --- | --- |
| Live app | https://sm-manager-production-33df.up.railway.app |
| Repository | `hemantsatishjadhav06-ai/calander-6` |
| Railway project | `5b228b66-e948-4243-bf3d-a3b7cdc9a518` |
| Railway service | `sm-manager` · `4718225a-e77f-422c-8b38-8ae82b69e936` |
| Environment | `production` · `b5642ba0-de22-4c2f-b9b0-295354d33114` |
| Accepted release | `f41a5e60-4eb1-483b-be6e-a3ae34494367` · merged commit `d64232c1da4e22d329671eaa5737c182a86fd655` |
| Rollback baseline | `8801cdcf-9b7f-402e-9486-35481ad49611` · commit `98f2758`; requires publication isolation |
| App storage volume | `70f2b415-eb89-41e7-8510-26fd792ae307` · `/var/www/html/storage` |
| App volume instance | `aed337af-683a-44f3-96c2-c00c8fb41950` · daily/weekly/monthly backup schedules enabled |
| PostgreSQL service | `83de65fc-66de-41f5-b247-91388b9888cf` · PostgreSQL 18 |
| Pre-release native database backup | `3311d5a9-0696-4d69-a9b8-af2aa5231db8` |
| Post-setup native database backup | `2448497f-e974-420e-8524-2037f5481c00` · 2026-10-02 16:07 UTC |
| Post-setup app-volume backup | `eb282c25-8f4b-4185-9217-ee1b39bdd7f9` · 2026-10-02 16:07 UTC |

Local checks use PHP 8.5, Laravel 13, Bun 1.4.2, SQLite and isolated accounts.
The actual PostgreSQL 18 backup also passed isolated migration and queue
checks. Real authenticated Railway/Netlify API contracts and normal live SM
Manager login were exercised. Google, X, LinkedIn, Meta, Threads, Bluesky,
mail, Stripe and S3 publication behavior still needs provider-specific live
acceptance; fixture tests do not establish consent, quotas or delivery.

## Audit findings addressed

| Priority | Finding and resulting behavior |
| --- | --- |
| High | Revoked workspace membership no longer permits access through a stale current workspace. Membership validation runs before route binding; foreign media stays hidden. |
| High | Social login rechecks verified provider email when recovering from a concurrent account creation, closing the account-linking race. |
| High | Bluesky service URLs are checked at every use, reject private/reserved addresses, pin DNS, disable redirects, and bind OAuth metadata to the expected issuer. Untrusted response bodies are omitted from logs. |
| High | MCP authorization binds the exact authorization code to the approved workspace. Refresh retains that binding, revoked members lose access, and repeat connections must select a workspace. |
| High | Publication jobs serialize per target. Deleted posts stay deleted while remote cleanup completes; duplicate jobs and status rollups cannot resurrect them. Schedule and queue updates reject concurrent publishing claims. |
| Medium | Media downloads have transfer limits and temporary-file buffering. Videos are streamed to storage; failed replacements retain the original, and image edits invalidate cached conversions. |
| Medium | Publishing waits for successful autosave of the latest edits, including edits made during a pending request. Conflicts, offline saves, and follow-up save failures prevent submission. |
| Medium | Notification mutations serialize and roll back on failure. Calendar actions report failures; filtering, counts, onboarding, appearance, and preview controls tolerate denied browser storage. |
| Medium | Build context excludes secrets, cached configuration, OAuth keys, databases, and uploaded media. Production Compose builds this fork, disables debug, checks `/up`, permits long publication jobs to finish on shutdown, and makes Supervisor configuration readable by the non-root runtime user. |
| Medium | Dependencies with known advisories were updated. CI now checks JavaScript advisories, frozen lockfiles, frontend types, and frontend tests with a pinned Bun version. |

Publication, scheduling, and retries now enforce the same state rules in the
web UI, API, and MCP. Failed/partial publications use the retry endpoints;
published, publishing, and deleted posts cannot be silently republished.
Deleted public shares are hidden, while active shares can display media to a
viewer from another workspace.

The scheduler commits its post claim and database-queue fanout in one database
transaction. Keep `QUEUE_CONNECTION=database` and the jobs table on the same
connection for the initial release. Redis/SQS publication fanout does not have
that same crash-atomic guarantee; a durable outbox is needed before claiming
that guarantee for external queues. Remote provider requests themselves cannot
be made exactly once by local locking alone: test ambiguous provider failures
and reconcile remote outcomes before manually retrying them.

## Verification record

Validation on 2026-10-02 covers the final audit, approval, brand setup, real
Netlify publisher and deployment runtime. The repository uses Pest, Bun,
oxlint, oxfmt, Pint, Larastan and Rector.

- The full integrated `composer ci:check` passed with 2,311 PHP tests and
  9,454 assertions, Pint checking 962 files, Larastan, Rector and frontend
  lint/format/types. Later focused publisher recovery cases also passed.
- All GitHub CI checks passed for final runtime commit `aff7418`, including
  tests, quality/static analysis, dependency audit and security scans.
- The final frontend suite passed 1,020 tests across 152 files.
- The final blog publisher suite passed 61 tests and 561 assertions, including
  actual Netlify response contracts, approval checks and interrupted recovery.
- Final deployment regression checks passed 15 tests and 49 assertions;
  shell syntax, Pint and Rector checks passed.
- An isolated restore of the real PostgreSQL 18 backup migrated from 43 to 48
  migrations. All 44 original data-table row counts and original-column
  checksums were preserved. Focused PostgreSQL approval, scheduling and
  publisher checks passed 245 tests and 1,450 assertions. Native database
  queue claim/release/delete checks passed without running publication jobs.
- The isolated browser application passed desktop/mobile registration, brand
  setup, workspace switching and private blog review/edit/rejection flows.
  Its starter data contained 14 private posts, two blogs, zero publication
  jobs and zero published posts. The new live owner separately passed normal
  HTTP sign-in. Live production acceptance subsequently passed 47 desktop/
  mobile page visits with no horizontal overflow or uncaught JavaScript errors.
  Approval kept a blog private; editing invalidated approval and restoring
  its previous body did not restore approval. Stale-version publication
  returned HTTP 422 without a queued job; cross-tenant access returned 404
  and anonymous access redirected to sign-in. Before background activation,
  the one legacy queued job and 12 legacy failed jobs were unchanged; the
  new owner's content remained 14 drafts and two idle, unapproved blogs with
  no published URLs or newly connected accounts.
- More Space's first immutable preview and live release passed desktop/mobile
  home and calculator checks, asset checks, reload, six exact math fixtures,
  invalid-input/CSV formula cases and one-page A4 print. The separate PR #4
  candidate and its live production release passed desktop/mobile mocked
  failure and success checks without submitting real enquiries or navigating
  WhatsApp. PR #5's immutable rendering preview passed the full 32-file
  manifest check. Its production release passed four desktop/mobile home
  and project views, original image hashes, source labels and keyboard access.

The full Linux AMD64 Dockerfile recipe for `aff7418` built and booted with
production dependencies, client/SSR assets and signed PostgreSQL client 18.6
packages. Its actual entrypoint prepared a fresh root-owned storage volume,
preserved private file modes, dropped privileges and started Octane, the queue
worker, scheduler and Bun SSR as UID 9999 with `NoNewPrivs=1`. Console FIFO
ownership was verified without changing its `0600` mode. All 48 migrations,
production caches, HTTP `/up`, SSR `/health` and server-rendered `/login`
passed. The image defaults to `www-data`; Railway's root-start wrapper is
used only to prepare the mounted volume before application initialization.

The final image manifest is
`sha256:6ee78c4bcd2e5265bbdac164af71583bbf08de9dd0661055d7578b6a9e1dca0f`.
All 1,567 tracked files and 1,038 critical runtime files matched the source;
38 OCI blobs were verified. No synthetic fixtures, credentials, proxy CA or
uploaded media were included. Registry rate limits were handled with verified
official Bun OCI content and a temporary build-step CA secret; TLS, package
signatures and checksums remained enabled. The candidate was not published
as a registry artifact by this validation. The subsequent Railway rollout
passed live migration, key-preservation, volume-ownership and private-browser
checks. The same-image redeploy passed background/SSR activation, web health,
private storage and application/Passport key continuity. All new content
remained private after activation. External providers still need real consent
and approved publication acceptance.

The previous Rector gate proposed 159 files of modernization changes. These
were reviewed and applied in scoped application/test batches. One unsafe suggestion was
excluded narrowly: converting the deliberately rebound `scopes()` closure in
`MetaDirectMessageOptInTest.php` resolves the method on the wrong object. That
closure and its behavioral tests are retained.

## Required deployment configuration

Use `.env.example.prod` as the template, with real values in the platform's
secret settings. Do not copy local `.env` into the image. Keep `APP_KEY` and the
Passport keypair stable across deploys.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-domain.example
SESSION_SECURE_COOKIE=true
ALLOW_DEFAULT_USER_SEED=false
SELF_HOSTED=true
QUEUE_CONNECTION=database
BLOG_PUBLISHING_ENABLED=true
BLOG_PUBLISHING_OWNER_EMAIL=neopolisinfrallp3@gmail.com
```

Set `OCTANE_HTTPS=true` behind a TLS terminating proxy. Set `TRUSTED_PROXIES` to
the actual proxy addresses; use `*` only when the application is reachable
exclusively through that proxy. Confirm redirects and OAuth callback URLs use
the intended HTTPS host. Enable `INSTANCE_REGISTRATIONS_ENABLED=true` only
when public signup is intended.

### Persistent media, database, and keys

Use S3-compatible object storage or a persistent `storage` volume. A container
filesystem alone loses uploads and disk-generated Passport keys when replaced.
Production Compose provides named `storage` and SQLite volumes; the hosted
platform must supply equivalent persistence or managed services.

For object storage set `FILESYSTEM_DISK=s3`, bucket, region, credentials, and
endpoint as needed. Video direct uploads require bucket CORS to allow `PUT`
from `APP_URL` and expose `ETag`; expire `tmp/media/` objects after about a day.
For the inspected Railway deployment, preserve its current default local
disk and mount the entire `/var/www/html/storage` tree. That retains both
private and public data; do not switch the default disk to public merely to
make uploads persistent. Verify the storage symlink and that uploaded images,
videos and disk-generated Passport keys survive a redeploy.

Set Railway `RAILWAY_RUN_UID=0` for the first root-owned volume. The tested
entrypoint prepares storage/cache ownership and its own console descriptors,
then drops to `www-data` before migrations, caches and application processes.
Keep one replica and one active scheduler while using this shared disk.
Leave `APP_KEY` unchanged. Generate Passport keys once if absent and retain
them on the persistent volume; never force-overwrite an existing pair.

PostgreSQL already has a persistent volume. Native daily, weekly and monthly
backup schedules are enabled, and manual backup
`3311d5a9-0696-4d69-a9b8-af2aa5231db8` was verified. An owner-only custom-format
`pg_dump` 18 backup was downloaded with its SHA-256 verified and restored into
an isolated PostgreSQL 18 instance for the migration/data-preservation checks.
The final runtime includes signed PostgreSQL 18 client tools for future
backups. The new app volume also has daily, weekly and monthly backup schedules
enabled. Record the recovery operator and retention, complete the post-setup
checkpoint and preserve the private database backup securely.

### Outbound email

A log/array mailer delivers no password resets, invites, or notifications.
Configuring a delivery mailer also enables email verification. Configure the
provider and an authorized sender, then prove verification, password reset,
and a workspace invitation arrive in real inboxes. The inspected live service
currently uses `MAIL_MAILER=log`; normal login works, but inbox delivery has
not been proved.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-user
MAIL_PASSWORD=your-secret
MAIL_SCHEME=smtp
MAIL_FROM_ADDRESS=no-reply@your-real-domain.example
MAIL_FROM_NAME="SM Manager"
```

`smtp` supports STARTTLS when advertised. For implicit TLS use `smtps` and port
465; `MAIL_SCHEME=tls` is not a valid transport scheme. Resend is another option
with `MAIL_MAILER=resend` and `RESEND_API_KEY`.

### Providers, monitoring, and instance identity

Enable only providers whose credentials, callbacks, app review, scopes, and
quota are verified. Sign-in callbacks use `/auth/{provider}/callback`;
connected-account callbacks use `/accounts/callback/{provider}`. Meta uses
`/accounts/callback/meta`. Register both callbacks if using sign-in and posting
with the same provider app. Configure the final domain before registering
callbacks to avoid repeating provider setup.

Meta credentials and owner consent are still missing. Supply the Meta App ID
and configure its secret securely in Railway (`FACEBOOK_CLIENT_ID` and
`FACEBOOK_CLIENT_SECRET`); Facebook and Instagram use the unified Meta
callback above and Instagram accounts must be linked to the correct Pages.
Follow the official provider screens and obtain the permissions required for
each enabled feature. The existing X connection in another workspace is not
evidence that these new owner workspaces are connected; reconnect through the
owner's consent flow without moving another user's credentials. More Space
also needs an X account created before its consent flow can run.

For each advertised platform, connect a launch test account and exercise a
text post, image, video where supported, future schedule, token refresh, retry,
and remote deletion. Confirm one remote result and correct local status. Also
verify engagement/DM features only where the granted scopes and quotas support
them. Perform external publication only after the user approves that content
in the dashboard. Never infer working API credits or app approval from fixture tests.

Set monitoring DSNs if using Sentry (`SENTRY_LARAVEL_DSN` and
`SENTRY_FRONTEND_DSN`), verify an event, and set alerts for failed jobs and stale
scheduled posts. Confirm the operator name, contact, jurisdiction, address,
and policy date on `/privacy`, `/terms`, and `/data-deletion` match the real
service. The audit does not decide the legal entity or certify legal terms.

## Release sequence and acceptance checks

1. Confirm the passing final CI head and production-image proof. Preserve the
   current image, configuration, `APP_KEY`, database backup and any existing
   Passport keys. Git auto-deploy is temporarily disabled for this rollout.
2. Merge the tested candidate, verify the merged runtime tree, and prepare
   production variables without triggering an unintended old-code deploy.
   Keep `QUEUE_WORKER_ENABLED=false` and `SCHEDULER_ENABLED=false` for initial
   private acceptance. Preserve the current database and filesystem settings.
3. Create the durable app volume at `/var/www/html/storage` and deploy the exact
   tested commit. Apply `php artisan migrate --force --no-interaction` before
   routing traffic. The five additive migrations take the old 43-migration
   schema to 48: MCP authorization binding, social approval, brand profiles,
   private blog drafts and website-publication recovery. Existing token
   bindings remain usable; unfinished old consent codes may need new consent.
4. Verify the running release, 48 migrations, volume ownership, non-root web/
   background processes, HTTPS `/up`, frontend assets, SSR and normal sign-in.
   Prepare the new owner's two brand workspaces through the normal setup flow;
   verify confirmed mappings, 14 private posts, two private blogs, owner-only
   approval and no new publication jobs or public content.
5. Run live desktop/mobile private acceptance: workspace isolation, draft
   saves, review, approval followed by edit invalidation, unapproved publish
   denial and the configured website publisher's availability. Do not use a
   public post or blog as an unapproved smoke test.
6. Enable the worker and single scheduler after those checks, restart so they
   load the final code/configuration, and verify stable keys and media after
   redeployment. Configure app-volume backup schedules and take a post-setup
   recovery checkpoint. Permit active publication jobs to drain before later
   replacement; the tested Supervisor grace period supports long video jobs.
7. Restore the prior Git auto-deploy setting after the stable release and
   update this runbook's release/status rows with actual deployment evidence.
   Finish mail delivery, the More Space backend replacement and official
   account consent. After dashboard approval, exercise a real scheduled
   publication on each advertised provider and confirm the remote result,
   local status, refresh/retry/deletion, scopes and quotas.
8. Open public registration and announce the service only after public-launch
   checks pass. Record the actual instance registration policy; environment
   values alone may be overridden by saved instance settings.

Rollback uses a retained image and stable secrets while preserving persistent
volumes, additive schema and approval data. A pre-approval image ignores
`requires_post_approval`: keep publication endpoints, producers, workers and
scheduler paused during that rollback. Resume only with approval-aware code
or verified isolation that prevents every publication entry point. Drain jobs
before replacing workers, and reconcile remote posts before retrying jobs
whose provider outcome is uncertain.

## Commands

```bash
bun install --frozen-lockfile
composer install --no-interaction --prefer-dist
composer ci:check
bun run test
bun run build:ssr
composer audit --abandoned=report
bun audit

docker compose -f docker-compose.production.yaml config --quiet
docker build -t calander-6:release .
# Boot the built image with staging configuration and persistent storage.
# Verify /up, migrations, media, worker and scheduler before production use.
```

Use Composer's PHP 8.5 environment and Bun, not npm/pnpm. The local gate includes
Rector dry-run, Pint, Larastan, and Pest in addition to frontend checks. Do not
blanket-apply refactors or disable a failing gate to make a release appear
ready.
