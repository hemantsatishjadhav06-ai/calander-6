# SM Manager — launch runbook

Everything still standing between this instance and a real launch, in priority
order. Each task says **why it matters**, **what to do**, and **how to prove it
worked**.

Written 2026-09-26 against commit `a6d1297`. Verify the "current state" claims
before acting on them — they were true when this was written.

| | |
| --- | --- |
| Live app | https://sm-manager-production-33df.up.railway.app |
| Repo | `hemantsatishjadhav06-ai/calander-6`, deploys from `main` |
| Railway project | `5b228b66-e948-4243-bf3d-a3b7cdc9a518` |
| Railway service | `sm-manager` · `4718225a-e77f-422c-8b38-8ae82b69e936` |
| Environment | `production` · `b5642ba0-de22-4c2f-b9b0-295354d33114` |

## Who can do what

Tasks are tagged:

- **[agent]** — an agent with Railway access can finish it end to end.
- **[human]** — needs a person in a browser (creating an OAuth app, an AWS
  account, a billing decision). An agent can do everything *after* the
  credential exists.

## Setting a Railway variable

Every task below that says "set X" means this. Either:

- **Dashboard** → project → `sm-manager` service → **Variables** → New Variable.
- **Agent, via the Railway MCP** — `set-variables` with the project, service and
  environment IDs from the table above.

Setting a variable redeploys the service automatically (~3 min). Wait for it
before verifying.

---

## 1. Mail — [human] gets the key, [agent] sets it

**Why.** Nothing is set, so `MAIL_MAILER` falls back to `log`: every password
reset, workspace invite and failure notification is written to a log file and
delivered to nobody.

It does **not** currently break sign-up. `config/auth.php:23` reads
`'enabled' => ! in_array(env('MAIL_MAILER', 'log'), ['array', 'log'], true)`, so
email verification disables itself while mail is unconfigured, and
`User::hasVerifiedEmail()` returns `true`. **Setting a real mailer switches
verification back on** — which is correct, but means new sign-ups will start
needing a working inbox from that moment.

**What to do.** Pick one provider and set its variables.

Resend (simplest — get a key at resend.com, verify your sending domain):

```
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=no-reply@yourdomain.com
MAIL_FROM_NAME=SM Manager
```

Or SMTP (Gmail needs an *app password*, not your account password):

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=<16-char app password>
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=you@gmail.com
MAIL_FROM_NAME=SM Manager
```

`MAIL_FROM_ADDRESS` defaults to `hello@example.com` — set it or mail will be
rejected by most receivers.

**How to verify.** Register a new account with an address you can read. The
verification email should arrive within a minute. Then delete that account from
Settings → Profile.

If it does not arrive, read the deploy logs for the send attempt — Railway's log
timestamps run several hours ahead of the container clock, so widen the time
window rather than assuming nothing was logged.

---

## 2. Google sign-in — [human] creates the app, [agent] sets it

**Why.** The code is already complete and tested (24 tests in
`tests/Feature/Auth/SocialiteLoginTest.php` cover Google, X and LinkedIn
registration, account linking, and the registrations-closed refusal). Only
credentials are missing, so `/login` shows no Google button.

Google sign-ups skip email verification entirely, because Google reports the
address as already verified — so this works even before task 1 is done.

**What to do.**

1. Go to https://console.cloud.google.com/apis/credentials → **Create
   credentials** → **OAuth client ID** → **Web application**.
2. Under **Authorized redirect URIs** add exactly:

   ```
   https://sm-manager-production-33df.up.railway.app/auth/google/callback
   ```

   This exact path matters. `SocialiteController::driver()` pins the callback to
   the route and **ignores `GOOGLE_REDIRECT_URI`**, so setting that variable
   will not change where Google is told to return to.
3. On the **OAuth consent screen**, fill in:
   - App home page → `https://sm-manager-production-33df.up.railway.app`
   - Privacy policy → `.../privacy`
   - Terms of service → `.../terms`

   These three pages are already live — that is what unblocks the review.
4. Set on Railway:

   ```
   SOCIALITE_ENABLED=true
   SOCIALITE_PROVIDERS=google
   GOOGLE_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=...
   ```

**How to verify.**

```bash
curl -s https://sm-manager-production-33df.up.railway.app/login \
  | grep -o '"providers":\[[^]]*\]' | head -1
```

Should print `"providers":[{"provider":"google","label":"Google"}]` (it prints
`[]` today). Then click **Continue with Google** and complete a real sign-in.

---

## 3. Uploaded media is being lost — [human] creates the bucket, [agent] sets it

**Why this is worse than it looks.** `FILESYSTEM_DISK` is unset, so it defaults
to `local` (`config/filesystems.php:16`). The `sm-manager` service has **no
volume mounted**. Railway containers are replaced on every deploy, so every
image and video a user uploads is **destroyed on the next deploy**, and posts
referencing them break.

Nothing warns anyone this is happening.

**What to do.** Point it at object storage — Railway's own bucket, Cloudflare R2
or AWS S3 all work:

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
AWS_ENDPOINT=...          # R2 / non-AWS only
AWS_USE_PATH_STYLE_ENDPOINT=true   # R2 / MinIO only
```

The app already handles this properly: `app/Support/FileStorage.php` switches to
native presigned direct-to-storage uploads when the disk is object storage, so
the app never proxies the bytes.

A Railway volume mounted at `storage/app` is a cheaper alternative, but it does
not survive a region move and cannot be served by a CDN.

**How to verify.** Upload an image to a post, redeploy the service, then reload
the post. The image must still render. Do this on the demo workspace, not a real
one.

---

## 4. X and LinkedIn sign-in — [human] — optional

**Why.** Same code path as Google, already tested. `X_CLIENT_ID` and
`X_CLIENT_SECRET` are **already set** on Railway for connected-account posting.

**Careful:** login and posting share one Socialite config but use *different*
callback paths. The X app almost certainly only has the posting callback
registered today, so enabling login without adding the second URI gives a
`redirect_uri mismatch` error.

**What to do.** In the X developer portal add `/auth/x/callback` alongside the
existing `/accounts/callback/x`. For LinkedIn create an app, add
`/auth/linkedin/callback`, and set `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET`.
Then:

```
SOCIALITE_PROVIDERS=google,x,linkedin
```

**How to verify.** Same `curl` as task 2 — all three should appear in the
`providers` array — then complete a real sign-in with each.

---

## 5. Company name on the legal pages — [human] decides, [agent] sets it

**Why.** `INSTANCE_COMPANY_NAME` and `INSTANCE_JURISDICTION` are deliberately
unset, so `/privacy` and `/terms` say "the operator of this instance". That is
accurate but vague, and naming the legal entity answerable for user data is not
a decision to make on someone's behalf.

`INSTANCE_CONTACT_EMAIL` is already set to `neopolisinfrallp3@gmail.com`.

**What to do.** Set whichever apply:

```
INSTANCE_COMPANY_NAME=<registered entity name>
INSTANCE_JURISDICTION=<e.g. India>
INSTANCE_POSTAL_ADDRESS=<optional, appears in the contact block>
INSTANCE_LEGAL_EFFECTIVE_DATE=2026-09-25
```

**Have a lawyer read `/privacy` and `/terms` before launch.** They were written
from the actual schema and outbound calls — accurate, but not legal advice.

**How to verify.** `curl -s .../privacy | grep -o '<your company name>'`.

---

## 6. Database backups — [human]

**Why.** The Postgres service has a 5 GB volume. Whether backups are scheduled
could not be confirmed from the API — Railway does not expose backup settings
there, so **check the dashboard rather than assuming either way**. If there are
none, there is no recovery from a bad migration or an accidental delete.

**What to do.** Enable scheduled backups on the Postgres service in the Railway
dashboard, or add a scheduled `pg_dump` to off-site storage. Then **restore one
into a scratch database** — an untested backup is not a backup.

---

## 7. Error monitoring — [agent], once a DSN exists — optional

`sentry/sentry-laravel` is installed and wired but no DSN is set, so nothing is
reported. Create a project at sentry.io and set:

```
SENTRY_LARAVEL_DSN=https://...ingest.sentry.io/...
```

**Verify:** trigger a deliberate error and confirm it lands in Sentry.

---

## 8. A real domain — [human] buys it, [agent] wires it

**Why.** The service has no custom domain (`customDomains: []`); everything is
served from `sm-manager-production-33df.up.railway.app`. That costs you on three
fronts at once: it reads as unfinished to anyone evaluating the product, email
from a `railway.app` sender lands in spam far more often, and every OAuth app
you register has to be re-registered later when the URL changes.

Doing this **before** tasks 1, 2 and 4 saves redoing them.

**What to do.** Buy the domain, add it under the service's **Settings →
Networking → Custom Domain**, and point the CNAME Railway gives you. Then set:

```
APP_URL=https://yourdomain.com
```

and update the redirect URIs in every OAuth app you have registered.

**How to verify.** `curl -sI https://yourdomain.com/up` returns 200 over a valid
certificate, and `curl -s https://yourdomain.com/privacy | grep canonical`
shows the new host.

---

## 9. X API credits — [human]

Polling was parking X requests for 6 hours at a time because the account's API
quota was exhausted (that is what PR #2 fixed — it used to retry every 15
minutes forever). Top up the X developer account or X engagement and DM polling
stays dark.

**Verify:** after topping up, watch a reply-fetch run in the deploy logs and
confirm the outcome is no longer `quota_exhausted`.

---

## Things that are already done — do not redo these

- **Public pages.** `/`, `/privacy`, `/terms`, `/data-deletion` are live and
  linked from login and register.
- **Registration is open.** `INSTANCE_REGISTRATIONS_ENABLED=true`. Verified with
  a real sign-up that reached the dashboard; the test account was deleted.
- **Demo account.** `demo@example.com`, in its own workspace with four sample
  posts, not an instance owner (`/settings/instance` returns 403 for it). The
  password was given in chat — to rotate it, run `php artisan demo:create`,
  which prints a new one once.
- **Tenant isolation.** `WorkspaceMiddleware` now always binds a workspace, so a
  user with no current workspace can no longer read every tenant's rows (PR #4).
  Do **not** "tighten" `HasWorkspaceScope` to fail closed globally — queued jobs
  resolve scoped relations with no context and it would stop all publishing.
  There is a regression test guarding this.
- **Seeded admin backdoor.** `DefaultUserSeeder` refuses to run outside
  local/testing and is no longer in the deploy pre-command.
- **Scheduler, queue worker, Octane** all confirmed running under supervisord.

## Useful commands

```bash
# Is it up?
curl -s -o /dev/null -w '%{http_code}\n' https://sm-manager-production-33df.up.railway.app/up

# Which social providers are live?
curl -s https://sm-manager-production-33df.up.railway.app/login \
  | grep -o '"providers":\[[^]]*\]' | head -1

# Are the public pages up?
for p in / /privacy /terms /data-deletion; do
  curl -s -o /dev/null -w "$p -> %{http_code}\n" \
    "https://sm-manager-production-33df.up.railway.app$p"
done

# Local checks before any push
composer ci:check     # oxlint, oxfmt, tsc, Pint, Larastan, Pest
bun run build
```

## Notes for whoever picks this up

- **Tooling is not the defaults.** Pest not PHPUnit, bun not npm, oxlint not
  eslint, oxfmt not prettier. `CLAUDE.md` has the details.
- **Railway log timestamps run ahead of the container clock** — by roughly six
  hours when this was written. A narrow time filter will show an empty log and
  make a command that ran fine look like it never executed.
- **Railway's pre-deploy command is not a shell.** `a && b` does not chain; only
  the first command runs, silently. Use one command, or `sh -c '...'`.
- **The repo deploys from `main` on every merge.** There is no staging
  environment, so a merge is a production release.
