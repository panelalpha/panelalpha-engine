---
name: deploy
description: Deploy an application to a PanelAlpha engine using its MCP tools - create the project, get the files in (git, archive URL, upload, or written file by file), deploy, verify it answers - and debug a deploy that fails or a site that does not come up. Use when asked to deploy, redeploy, publish, or fix an app on a PanelAlpha engine.
---

# Deploying an application on a PanelAlpha engine over MCP

Every step here is an MCP tool call against the engine (`mcp__panelalpha__*`
or the server the user connected). No shell on the host is needed, and no
root access: an account's files, containers and logs are all reachable
through the tools. Verified on a fresh install with a PHP app, an Express
app and a static site; the timings below come from that run.

A **project** is one hosting account: a container of its own, `~/project`
holding the application, one main domain, and the FTP/SFTP/MySQL resources
under it. The engine **detects** what the files are (29 recipes: static,
php, laravel, express, nextjs, django, rails, dockerfile, compose, ...) and
builds, starts and proxies the app accordingly. You rarely tell it what the
app is; you tell it where the files are.

## 1. Before creating anything

- **Do not pick a domain.** Leave `domain` out of `project_create` and the
  engine chooses the best public name it can and attaches the tunnel in the
  same call: a free label under `panelalpha.online` — a wildcard zone in front
  of the WithoutDNS proxy, so it resolves worldwide with a trusted certificate
  — falling back to `<name>.<cert_domain>` (resolves, self-signed) and finally
  `<name>.local` (resolves nowhere). Pass `domain` only when the user has one
  of their own, and `tunnel: "none"` with it. `project_create` returns **202**
  with a task (`id`, `status`); poll `task_get` until `completed` /
  `failed` / `cancelled`. While it runs, `task_log_list` with `since` (unix
  or ISO) returns new lines; the file log on `deploy_log_get` remains for
  timings. Then read the project with `project_get` for
  `details.domain` rather than inferring any of it:

  | field | what to do with it |
  |---|---|
  | `source` | `panelalpha_online` · `panelalpha_direct` · `sites_base_domain` · `requested` · `local` — say which one the user got |
  | `publicly_resolvable` | `null` means it depends on DNS the engine does not serve. Never report it as reachable on a `null`; step 4.3 is the check |
  | `tls_terminated_at` | `proxy` means the trusted certificate is the proxy's and `details.ssl` is *not* what a visitor sees. `engine` means `details.ssl` is the truth |
  | `fallback_reason` | non-null means a better name was not available. Tell the user, in one line |

  A `local` source means nobody can open the site. Say so before deploying,
  and ask whether the user has a domain to point here.
- `project_verify_name` and a pre-flight `project_list_summary` are not worth
  the round trip: `project_create` runs the same checks before it creates
  anything, reports **every** problem it found at once, and names the field
  and a stable code for each. Just create it, and read `problems` if it
  refuses:

  ```json
  {"errors":{"username":["User already exists."], "template":["Template directory does not exist."]},
   "problems":[{"field":"username","code":"name_taken","message":"User already exists."},
               {"field":"template","code":"template_not_found","message":"…"}]}
  ```

  Fix all of them before retrying — a second call will not find new ones.
  Codes before anything is created: `username_required`, `name_taken`,
  `name_unavailable`, `domain_taken`, `hostname_taken` (a
  `panelalpha.online` label you named is gone), `allocation_failed` (the
  proxy would not register it), `template_not_found`,
  `template_conflicts_with_git`, `invalid_value`. A failure during the deploy
  itself comes back under the field `deploy` with the codes in section 5A,
  plus `stage` and `deploy_log_offset`.
- **Inspect before you deploy** when the source is a repository:
  `source_inspect` with `source: "github.com/owner/repo"` (optional `branch`,
  `git_token` for private repos). This one earns its call — it answers what
  `project_create` cannot: `application.strategy` / `label` / `runtime`,
  `deployable` with the reason when false, `ports`, `services` (databases the
  app implies), `environment` (variable **names** only) and `stages` (the
  commands per stage). Read `services` and `environment`: an app that wants
  `DATABASE_URL` will start and then fail unless you create a database and
  pass the value in `env_vars`. Read `metadata.packages[].scripts` too,
  against `application.stages`: **a script the project declares that no stage
  runs is work nobody will do for you.** `seed` / `db:seed` / `seed:run` is
  the common one — the engine runs migrations (the `install` and `upgrade`
  stages exist for exactly that) and deliberately never seeds, because sample
  rows in a customer's database are a data decision the operator owns.
  `compose` and `dockerfile` projects run their own entrypoint, so the engine
  cannot see what that already did — there the script list is a hint to check,
  not proof of anything.
- Project names are short lowercase (`paphp`, `shop`) and become the Linux
  user and the container name. Every tool takes the project as `name`; the
  API's own responses still call that value `username` (the `name` field in a
  project record is an unused label, usually null).

## 2. Get the files in — pick one route

| Source | Route | Notes |
|---|---|---|
| Git repository | `project_create` with `git_repo` (+ `git_branch`, `git_token`), then poll `task_get` / `task_log_list` | create returns 202; the job does clone + detect + build + start |
| Archive at a URL (release asset, `archive/refs/heads/main.zip`) | `project_create` (`template: "dind"`, no `git_repo`) → poll task → `file_upload` with `file_url` → `project_deploy_archive` | the engine downloads it; nothing passes through the conversation |
| Archive you hold as bytes (small) | same, but `file_upload` with `file_name` + `file_contents` (base64) | fine for a few hundred KB; beyond that use a URL or FTP |
| A handful of files you are writing yourself | `project_create` (`template: "dind"`) → poll task → `file_write` each file under `/project/` → `project_rebuild` (no `zip_path`) | `file_upload` with `file_encoding: "text"` is equivalent |
| Files on the user's machine, large | `ftp_account_create` or `sftp_account_create` → user uploads into `/project/` → `project_deploy_archive` | the account home `/` is root-owned (SFTP chroot); uploads at `/` fail with Permission denied — always `/project/` |

Paths in file tools are relative to the account home: `/project/index.html`
is `~/project/index.html`. `zip_path` in `project_deploy_archive` and
`project_rebuild` is the same: `/project/app.zip`. A `.zip` or `.tar.gz`
wrapped in one top-level directory (what `zip -r app.zip app/` makes) is
unwrapped automatically.

Example, an archive from GitHub (poll `task_get` after create until `completed`):

```json
project_create         {"name":"shop","email":"owner@example.com","template":"dind"}
task_get               {"id":123}
task_log_list          {"id":123,"since":0}
file_upload            {"name":"shop","path":"/project","file_url":"https://github.com/acme/shop/archive/refs/heads/main.zip"}
project_deploy_archive {"name":"shop","zip_path":"/project/main.zip"}
```

Example, a static site written by hand (poll until the placeholder account is ready):

```json
project_create   {"name":"site","email":"owner@example.com","template":"dind"}
task_get         {"id":123}
file_write       {"name":"site","path":"/project/index.html","contents":"<!doctype html>..."}
file_write       {"name":"site","path":"/project/style.css","contents":"body{...}"}
project_rebuild  {"name":"site"}
```

Databases: `mysql_database_create` + `mysql_user_create` +
`mysql_privileges_set` first, then pass `DB_HOST`/`DB_DATABASE`/... (or
`DATABASE_URL`) in `env_vars` on the deploy call.

`env_vars` are stored on the project and applied on every deploy after it, to
the `.env` **and** to the container's own environment, where they outrank what
the platform generates — that is how you correct a platform default such as
`APP_ENV`, which the `php` platform ships as Laravel's `production` and Symfony
refuses to boot on. `PA_*`, `HOST`, `HOSTNAME` and `PORT` stay the engine's and
are ignored. A deploy's `env_vars` are merged onto the ones already stored, so
send only what changes; an empty value removes that key, and `null` clears them
all. A project app config's `env:` block sits between the two — it beats the
platform, the account beats it. `stages` are per-deploy and are not stored.

### Installing WordPress (and anything else that installs itself)

`app_install` is not the route. It needs a project the engine recognises as a
managed application, and one created from a `dind` template has no such
app config -- it answers `App management is not supported for this application`.
Install WordPress the way an operator would: put the files in, make it a
database, then drive its own installer.

```json
file_upload            {"name":"shop","path":"/project","file_name":"wp.zip",
                        "file_url":"https://github.com/WordPress/WordPress/archive/refs/tags/6.7.1.zip"}
mysql_database_create  {"name":"shop","dbname":"wp"}
mysql_user_create      {"name":"shop","dbuser":"wp","password":"..."}
mysql_privileges_set   {"name":"shop","dbuser":"shop_wp","dbname":"shop_wp","privileges":"ALL PRIVILEGES"}
project_deploy_archive {"name":"shop","zip_path":"/project/wp.zip"}
wp_cli_run             {"name":"shop","args":["core","config","--path=/home/shop/project",
                        "--dbname=shop_wp","--dbuser=shop_wp","--dbpass=...",
                        "--dbhost=database-users.shared-hosting.palocal","--skip-check"]}
wp_cli_run             {"name":"shop","args":["core","install","--path=/home/shop/project",
                        "--url=https://shop-4f2a.panelalpha.online","--title=...",
                        "--admin_user=...","--admin_password=...","--admin_email=..."]}
```

Three things that will otherwise cost you a while:

- **`wordpress.org` answers the engine's downloader with 403.** Use the
  official mirror on GitHub, as above.
- **`wp_cli_run` runs in the *account* container, not the application's.**
  So `--path` is the account path -- `/home/<project>/project` -- and never
  `/app`, which is where the same files are mounted inside the app container.
  Without `--path` it runs at `/` and reports "This does not seem to be a
  WordPress installation".
- **`--url` is what WordPress records as `siteurl` and `home`**, and it
  generates every link and redirect from it. Give it the domain the project
  came back with, exactly -- and confirm afterwards with `wp_cli_run`
  `["option","get","siteurl"]`.
  Get it wrong and the site canonicalises visitors into a redirect loop
  rather than serving them.

The database host is `database-users.shared-hosting.palocal` on port 3306;
`mysql_server_info` returns it. Database and user names are prefixed with the
project name, which is why the privileges call above says `shop_wp` where the
create calls said `wp`.

## 3. Deploy calls, timeouts and polling

`project_create` with `git_repo`, `project_deploy_archive` and
`project_rebuild` all block until the application answers its health check
and return the project with `details.deployment_status`, `deploy_strategy`,
`deploy_label`, `deploy_port`, `deploy_image` and `health_healthy`. Expect,
with prewarmed base images: static ~5 s, Express ~10-20 s, PHP ~20 s, a
Laravel or Next.js build 1.5-6 min, a first deploy of a runtime whose base
image is not on the host longer still.

**The MCP client's timeout is shorter than a slow deploy.** A timeout error is
the client giving up, not the deploy failing; the deploy keeps running.
Never retry the create — it would collide with the running deploy on the
same name. Instead:

1. `project_list_summary` — the project exists and its `status`.
2. `task_get` / `task_log_list` with `since` — task status and new log lines.
   Or `deploy_log_get` with `offset: 100000` — an absurd offset returns
   `status` (`running` / `success` / `partial` / `failed` / `cancelled`),
   `stage`, `error`, `started_at`/`finished_at` and `timings` (per-stage
   seconds and the `timeline` of steps) with an empty `lines` array. Poll
   this every 20-30 s until it is no longer `running` — `partial` is
   terminal too: the container came up and nothing answered on the detected
   port, so treat it as failed and go to section 5.
3. Only when you need the actual file-log output, call `deploy_log_get`
   again with `offset: 0` (then `next_offset` to continue): `lines` are
   `{at, level, step}` entries, and composer/npm output is one entry per
   line, so it is long.

`deploy_cancel` kills a deploy that is clearly stuck (a stage not advancing
for many minutes with no output).

## 4. Verify — the response is not enough

1. `details.deployment_status` is `success` and `health_healthy` is true.
   `partial` means the container is up but nothing answered on the
   detected port — treat it as failed and go to section 5.
2. `app_health_check` probes the published port from inside the account
   container: it tells you whether the *application* answers, independent
   of DNS, TLS and the proxy.
3. Fetch `http://<domain>/` and `https://<domain>/` (WebFetch, or `curl`
   through `ssh_run` from inside the container will not do — it must be from
   outside). 200 with the app's own content is the proof.
4. `details.ssl` says what certificate the *engine* serves for that name —
   read it rather than assuming, and check `details.domain.tls_terminated_at`
   first: on `proxy` (a `panelalpha.online` name) the certificate a visitor
   sees is the proxy's trusted one and `details.ssl` is not about them at all.
   On `engine` it is the truth. `status` is one word:
   `trusted`, `self_signed`, `expired`, `not_yet_valid`, `domain_mismatch`,
   `missing` or `unreadable`, with `issuer`, `expires_at` and
   `days_remaining` beside it. **A stock engine signs the certificate for a
   project domain itself**, so `status` is `self_signed`, an HTTPS fetch needs
   `curl -k`, and a browser will warn. Never tell the user SSL was issued for
   the domain unless `status` says `trusted`. `ssl_cert_list` reports the same
   fields per domain.

   Real certificates on an *engine-served* name are an operator setting, not
   something a deploy can turn on: with `ssl_issuer` on `acme`, a project is
   issued one over HTTP-01 as it is created, as long as its name resolves to
   this host. **A name on a shared zone needs a second setting.**
   `system_info.sites_certificates` reports both: `shared_zone` true means the
   sites sit on a wildcard zone the whole fleet issues under
   (`panelalpha.direct`, `nip.io`, `sslip.io`), and `shared_zone_issuance`
   says whether this engine will spend that shared 50-a-week allowance on
   them. With it false — the default — such a project keeps a self-signed
   certificate whatever `ssl_issuer` says, and the engine log gives the full
   reason. That is the operator's decision: report it, do not flip it.
   If a site you just deployed is `self_signed` and the user expected
   otherwise, read the reason out of the engine's log rather than guessing —
   that setting and a name that does not resolve here yet are the two usual
   ones, and `ssl:project-cert:request <domain>` is the retry once fixed.
5. **If it stores data, check it has some.** An app that answers with an
   empty list looks identical to a broken one from the outside. Fetch a list
   endpoint (`/api/<things>`), and if it is `[]` while a health route says
   the database is connected, the schema is there and the rows are not.
   That is the moment to go back to the `seed` script from step 1 and **ask
   the user** whether to run it. Never run it unasked: `knex seed:run` and
   friends are not idempotent, and this kind of seed usually inserts demo
   rows, not reference data the app needs.
6. Check the content is the application and not the welcome page or a
   directory listing: `deploy_strategy` `static` on a repo you expected to be
   PHP means detection saw no `composer.json`; `fallback`/`Unknown` means it
   recognised nothing.
7. `project_inspect` shows the `deployment` snapshot next to what the files
   are now, and `drift` where they disagree (`strategy`, `runtime`, `port`,
   `commit`) — the fastest way to see "deployed as X, files now say Y".

Report to the user: the URL, what was detected (label, runtime, port), the
certificate that URL is served with, how long it took, and anything
`warnings` or `drift` said. Every one of those is a field on the response —
report what it says, never what a deploy is expected to have done.

## 5. Debugging a deploy that fails, or a site that does not answer

Work down this ladder; each rung is one or two calls.

**A. What did the engine say?** `deploy_log_get` (`offset: 100000`). The
`error` field is already a diagnosis when the engine recognised the failure.
**Match on the slug, not the sentence.** A failing `project_create` /
`project_deploy_archive` / `project_rebuild` answers `422` with a `problems`
array, and each entry's `code` is that same slug — so branch on the code and
show the user the `message`:

```json
{"errors":{"deploy":["The repository could not be read. …"]},
 "problems":[{"field":"deploy","code":"repo-auth-failed",
              "message":"The repository could not be read. …",
              "stage":"cloning","deploy_log_offset":0}]}
```

| `code` | Meaning | Do |
|---|---|---|
| `php-version-mismatch` | `composer.json` requires a PHP the resolved image lacks | fix the constraint in `composer.json`, or name the image in a `panelalpha.yaml` (`image: php:8.3-apache-bookworm`); `php_version_list` says what the host has |
| `php-extension-missing` | extension not in the image | check `php_version_list`; add a manifest naming an image that has it, or drop the requirement |
| `composer-unresolvable` | unresolvable lock | fix the project's `composer.json`/lock |
| `node-engine-mismatch`, `go-toolchain-too-old` | `engines.node` / `go.mod` vs the resolved image | set `.nvmrc`/`engines.node` to something available, or `image:` in a manifest |
| `disk-full`, `out-of-memory` | build too large for the account limits | raise `disk_space_limit`/`memory_limit` with `project_update`, rebuild |
| `registry-rate-limited`, `base-image-unavailable` | registry side | wait and `project_rebuild`; check the image name in the Dockerfile |
| `layer-digest-mismatch` | a layer arrived corrupted from the host's registry mirror | host-side; `project_rebuild` usually succeeds |
| `env-validation-failed` | app-level env validation | read `environment` from `project_inspect`, pass real values in `env_vars`, rebuild |
| `database-auth-failed` | credentials in `.env` differ from the database | `mysql_user_change_password` or fix `env_vars`; rebuild |
| `missing-build-script`, `dependency-conflict`, `dependency-not-found`, `missing-package-at-runtime`, `bun-lockfile-*` | the project's own package files | fix the files (`file_write`) and rebuild; or replace the `build` stage via `stages` |
| `repo-auth-failed`, `repo-not-found` | auth or URL | check the URL, pass `git_token`, never put a token in the URL |
| `build-step-failed`, `install-error-line`, `prepare-script-failed` | a command in a stage failed | read from `deploy_log_offset` with a real `offset`; the command's own output is there |
| `app_did_not_start` | the container came up and nothing answered | section B — `container_service_logs` |
| `deploy_cancelled` | somebody called `deploy_cancel` | nothing to fix |

`deploy_failed` is the code when nothing matched — the engine did not
recognise the failure, so the raw output in the log is the only diagnosis
there is. Read from `deploy_log_offset`.

The table is the common rules, not all of them. Treat a code you do not
recognise the way you would `build-step-failed`: the `message` is already a
sentence for the user, and the log has the output behind it.

**B. The container.** `container_list` (is `app` running or restarting?),
`container_service_logs` with `service: "app"`, `lines: 200` — the
application's stdout/stderr, where a Node stack trace or a PHP fatal shows.
A container that restart-loops with `start: serve` and nothing else is
usually an app that binds `127.0.0.1` instead of `0.0.0.0`, or listens on a
port other than the detected one.

A compose app that restart-loops a few times with `ECONNREFUSED` against its
own database and then settles is a different thing and is **not** a failure:
the repo wired `depends_on:` as `service_started` rather than
`service_healthy`, so the app raced the database and the restart policy
fixed it. Read to the end of the logs before diagnosing — the migrations
that finally ran are the proof. Only chase it if the restarts never stop.

**C. Inside the account.** `ssh_run` runs one command as the project user
inside its container, `cwd` defaulting to the home: `ls -la project`,
`cat project/package.json`, `curl -si http://127.0.0.1:3000/`, `cat
project/docker-compose.yml`, `ls project/.panelalpha`. Read config files
freely; never print `.env` values back to the user.

**C2. Inside the *app's* container** (compose and dockerfile projects). The
account container is not the app: the code runs one level in, so `ssh_run`
lands beside `docker-compose.yml`, not beside the app. Reach the app with
`docker compose exec -T <service> …` from `cwd: /home/<name>/project`, and
**pass `-w` for the working directory** — the image's own WORKDIR is
whatever its Dockerfile last set, which for a monorepo built in stages is
usually the wrong half of the tree. `npm run seed` failing with
`ENOENT … /app/package.json` means exactly that; `docker compose exec -T -w
/app/backend app npm run seed` is the fix. `container_list` names the
services.

**D. The app answers inside but the site gives 502/504.** The proxy points at
the wrong port or the container is not reachable: compare `details.app_port`
and `deploy_port` in `project_get`, check `proxy_rule_list`, and
`project_rebuild` (which re-renders the vhosts). `domain_log_list` /
`domain_log_download` give the proxy's own error log for the domain.

**E. The site answers but shows the wrong thing.** The welcome page: the
files never reached `~/project` (check with `file_exists`) or the archive was
uploaded but never deployed. A directory listing or the raw PHP source:
detection chose `static` — add the file that identifies the app
(`composer.json`, `package.json`) or a `.panelalpha/panelalpha.yaml` with
`platform:`.
The wrong docroot: PHP apps serving from `public/` need `docroot:` in a
manifest.

**F. Wrong detection or wrong commands.** Write `.panelalpha/panelalpha.yaml`
into `/project/` with `file_write` - `extends:` names the shipped recipe this
repository is an instance of and settles detection outright (see
`core/resources/sources/README.md`; AGENTS.md §11 catalogues the
failures and what each error text really means), or pass `stages` on the
deploy: a stage named there replaces that stage outright, `[]` runs
nothing, absent keeps the platform's defaults. `source_inspect` with
`stages` previews the effect without deploying.

**G. Restart or redeploy.** `container_project_action` `restart` for a hung
app; `project_rebuild` to redeploy from `~/project` (optionally with a new
`zip_path`, `env_vars`, `stages`); `container_service_action` for one
service. `project_delete` recreates from scratch and destroys data — only
with the user's explicit go-ahead.

## 6. Rules

- Never retry `project_create` after a client timeout; poll `task_get` on the
  returned task id instead. A 202 means the account exists and the deploy is
  queued — not that the app is up yet.
- Upload into `/project/`, never at the account root.
- Do not pass secrets in the repository URL; `git_token` is the field for it.
- Do not read `.env` values back to the user; the inspect tools withhold
  them on purpose, so should you.
- Do not seed, migrate down, or write rows into a project's database
  unless the user asked for it. Surface that a `seed` script exists and let
  them choose.
- Destructive tools (`project_delete`, `mysql_database_delete`,
  `project_suspend`, `csf_*`) need the user's explicit confirmation on a
  server that hosts anything real.
- Do not choose a domain. Omit `domain` and report what
  `details.domain` says the project got; pass one only when the user has one.
- Branch on `problems[].code`, never on the wording of a message. The codes
  are stable; the sentences are written for a person and get rewritten.
- When you finish, say what was detected, where it answers, which rung the
  domain came from and what certificate it answers with (`details.ssl.status`
  unless `tls_terminated_at` is `proxy` — and do not call a self-signed one
  "SSL issued"), how long it took, and what you changed in the account (env
  vars, manifests, stages).
