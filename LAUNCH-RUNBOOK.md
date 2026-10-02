# SM Manager — launch runbook

Updated 2026-10-02 for `hemantsatishjadhav06-ai/calander-6`, starting from
commit `98f2758`. This audit covers the working tree, automated tests, local
browser flows, and deployment configuration. It does not certify a running
production deployment. No production configuration, accounts, or posts were
changed during this audit.

The local quality gate, test suites, and production asset builds pass. Public
launch additionally needs durable storage, recovery, working email, and real
provider smoke tests. The default release configuration assumes free access
(`SELF_HOSTED=true`). Subscription billing needs a separate configured Stripe
release.

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
lock and a database constraint. Website publication is unavailable until the
real Netlify site/repository binding and a supported publication adapter are
verified. Approval alone never deploys an article.

OAuth connection intents now bind the initiating session, user and workspace;
switching brands during authorization cannot attach credentials to another
workspace. Bluesky account connections verify the DID's canonical PDS before
accepting credentials.

Read-only checks on 2026-10-02 found HTTP 200 at the existing manager's `/up`
and `/login`, and both brand websites. GitHub's successful Railway deployment
record still refers to `98f2758`; these new changes were not deployed during
this work. Neopolis's live homepage matches the repository
`hemantsatishjadhav06-ai/hemantsatishjadhav06-ai.github.io`, which contains
static blog pages. More Space's likely source is
`hemantsatishjadhav06-ai/morespace-website`; its actual Netlify binding remains
unverified. Neopolis's single-file builder omits blogs, so an adapter must
preserve the complete site tree.

Management credentials remain unavailable. Secure cloud environment
requirements were saved for `RAILWAY_TOKEN` (production project token,
`Project-Access-Token` header at `backboard.railway.com`) and
`NETLIFY_AUTH_TOKEN` (Bearer token at `api.netlify.com`). Enter values in
environment settings, then verify backups, stable keys, persistent storage,
current deployment settings and exact site bindings before release. The
user must complete provider verification/sign-in where required. More Space's
X account still needs creation. No new live account connections, messages,
posts or blogs were published.

## Target and verified scope

The previous runbook identified this Railway deployment. Public health and
GitHub's existing successful deployment record were rechecked on 2026-10-02.
Its current settings,
credentials, volumes, backups, quotas, and worker processes were not accessible
for verification in this environment. Treat these as deployment references,
not current-state assertions.

| Item | Reference |
| --- | --- |
| Existing app | https://sm-manager-production-33df.up.railway.app |
| Repository | `hemantsatishjadhav06-ai/calander-6` |
| Railway project | `5b228b66-e948-4243-bf3d-a3b7cdc9a518` |
| Railway service | `sm-manager` · `4718225a-e77f-422c-8b38-8ae82b69e936` |
| Environment | `production` · `b5642ba0-de22-4c2f-b9b0-295354d33114` |

Local checks use PHP 8.5, Laravel 13, Bun 1.4.2, SQLite, and isolated test
accounts. Live Google, X, LinkedIn, Meta, Threads, Bluesky, mail, Stripe, and S3
requests were not used to prove provider behavior; connector tests use HTTP
fixtures. PostgreSQL deployment behavior still needs staging validation.

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

Validation on 2026-10-02 covers the integrated audit, approval, brand and blog
changes. The repository uses Pest, Bun, oxlint, oxfmt, Pint, Larastan, and Rector.

- Full `composer ci:check`: passed after integration,
  including frontend lint/format/types, Rector, all 955 PHP files checked by Pint,
  Larastan, and Pest.
- PHP suite: 2,278 tests passed with 9,266 assertions.
- Frontend suite: 1,010 tests passed across 152 files.
- Full Larastan analysis: no errors.
- Frontend types, lint, and formatting: passed.
- Client and SSR production asset builds: passed.
- Composer and Bun vulnerability audits: no known advisories in the updated
  dependency graphs at audit time. This does not predict future advisories.
- Browser: an isolated application using production assets passed 15 desktop
  visits and nine mobile routes at 390 px. Registration, both brand mappings,
  private starter drafts, workspace switching, and blog approval/edit/save/
  rejection passed with no uncaught JavaScript errors, same-origin HTTP errors
  or horizontal overflow. The database contained 14 private planned posts,
  two blogs, zero connected accounts, zero queued jobs and zero published posts.
  Optional DiceBear requests were blocked; follow-up component tests verify
  initials fallback for failed avatar loads. The earlier audit also verified
  denied browser storage and draft autosave/reopening.
- Seven production configuration tests passed, covering initialization,
  environment, SMTP,
  graceful shutdown, and non-root Supervisor permissions; shell syntax, Compose
  configuration, and a real Docker build-context exclusion probe passed.

The 2026-10-01 audit baseline's full Linux AMD64 Dockerfile recipe built
successfully, including
production dependencies, runtime extensions, Wayfinder, client/SSR assets,
and package discovery. Validation used a disposable overlayfs builder,
verified local official Bun OCI content, a cloud proxy host mapping, and the
host's trusted CA as a temporary per-build-step secret. Certificate and
checksum verification stayed enabled; the tracked Dockerfile was not changed
for these environment adjustments. The original VFS daemon exceeded its disk
capacity, and registry requests hit rate limits before those workarounds.
The candidate image was exported for validation and removed after checks.
It was never published or deployed.
Its actual entrypoint applied all 44 SQLite migrations, cached configuration,
routes, events, and views, and started supervised Octane, the queue worker,
scheduler, and Bun SSR. HTTP `/up` and SSR `/health` passed; `/login` contained
server-rendered controls. Runtime bcmath, cURL, EXIF, GD with WebP, SQLite,
PostgreSQL PDO, Redis, ffmpeg, and ffprobe checks passed. The boot check used a
separate disposable stage with synthetic configuration and no provider calls;
those fixtures are absent from the exported application image.
The newer approval, brand and blog code passed the 2026-10-02 checks above,
including client/SSR builds, but requires a new production image build.
Production platform configuration and live integrations still require the
release acceptance checks below; the baseline image does not prove them.

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
For disk storage use `FILESYSTEM_DISK=public` with persistent storage and the
storage symlink. Verify an image and video still render after a redeploy.

Enable scheduled database backups with retention and restore one into a
scratch database. Record the recovery steps and who can execute them. Test the
new migrations on the actual database engine before releasing to production.

### Outbound email

A log/array mailer delivers no password resets, invites, or notifications.
Configuring a delivery mailer also enables email verification. Configure the
provider and an authorized sender, then prove verification, password reset,
and a workspace invitation arrive in real inboxes.

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

1. Run all local gates with the frozen lockfiles, then build the production
   image on a builder with sufficient disk and registry access.
2. Test that image against persistent staging storage and the production
   database engine. Verify `/up`, frontend assets, media conversion, scheduler,
   worker, and SSR if enabled. Keep one active scheduler per deployment.
3. Back up production and restore a backup into a scratch database. Preserve
   the current image, configuration, `APP_KEY`, and Passport keys for rollback.
4. Pause publication endpoints, producers, workers and scheduler while replacing
   pre-approval code. Apply `php artisan migrate --force --no-interaction`
   before routing traffic to the new release. The four additive migrations add
   the nullable, unique MCP `authorization_code_hash`, workspace/post approval
   fields, `brand_profiles`, and `blog_drafts`. Existing token bindings remain
   usable; in-progress pre-upgrade consent codes may need new consent.
5. Deploy the tested image and restart Octane, queue workers, and scheduler so
   they load the new code. Allow up to 16 minutes for active video publication
   jobs to finish; verify the hosting platform supports that grace period.
6. Confirm approval enforcement before resuming publication. Confirm HTTPS
   `/up`, public pages, signup/sign-in, workspace isolation,
   actual mail delivery, media persistence, MCP consent/refresh, and one real
   scheduled publication on every advertised platform. Observe workers and
   scheduler logs, queue backlog, failures, and provider quotas.
7. Open public registration and announce the service only after these checks
   pass. A merge may auto-deploy on the existing platform: verify its release
   trigger before merging.

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
