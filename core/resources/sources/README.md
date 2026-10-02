# PanelAlpha per-repository directories

A directory here is hosting instructions for one upstream repository, found by
the URL a project was cloned from: `<host>/<owner>/<repo>/`, the same shape a
repository ships as its own `.panelalpha/`. When a project is created with a
`git_repo` URL the engine looks the directory up and applies it.

```
github.com/wordpress/wordpress/
  README.md                                what this app needs and why
  panelalpha.yaml                          required: description, extends, manifest keys
  hooks/precheck.sh                        before the clone
  hooks/prepare.sh                         after the clone, before the build
  overrides/entrypoint.sh                  replaces the generated entrypoint
  overrides/app.sh                         user management and SSO
  overrides/docker-compose.yml             replaces the project's compose file
  overrides/docker-compose.override.yml    layers over it
  files/<path>                             copied into the project at <path>
```

| File | When it runs | Purpose |
|---|---|---|
| `panelalpha.yaml` | — | **Required.** A platform manifest with extra keys — the same vocabulary as `core/resources/apps/<id>/panelalpha.yaml`: a `description`, `extends:` (the shipped recipe this repository is an instance of) and any manifest key overriding it, `env:`, `requires:`, staged `commands:` |
| `hooks/precheck.sh` | Before `git clone` | Validate prerequisites (e.g. disk space) |
| `hooks/prepare.sh` | After clone, before `docker compose up` | Generate config, set credentials, prepare a database. Responsible for creating a compose file if neither the repo nor `overrides/` ships one |
| `overrides/docker-compose.yml` | After clone | Replaces the repo's own compose file, stashing anything that would shadow it. The repo's committed `docker-compose.override.yml` is not layered over it; one the prepare hook or `files/` writes is. Written before the prepare hook, so the hook can rely on it |
| `overrides/docker-compose.override.yml` | After clone | Layers over the repo's own compose file. Prefer this — the upstream file is never modified |
| `overrides/entrypoint.sh` | Container boot | Replaces the generated entrypoint outright: no `install`, no `upgrade`, no `start`, no serve command |
| `overrides/app.sh` | On demand | Called by the engine for app management (`info` / `install` / `users:list` / `users:add` / `users:delete` / `users:reset-password` / `users:sso`). Should print `MISSING_SNIPPET` to stderr and exit 1 if a required file is missing so the engine can reinstall it and retry |
| `files/<path>` | After clone | Copied verbatim to `<path>` inside `~/project/`. Parent directories are created. Re-installed on demand if `overrides/app.sh` signals `MISSING_SNIPPET` |

A repository that ships its own `.panelalpha/` directory wins over anything
here — an upstream that describes its own hosting knows more than a page
written about it from outside.

The account's `~/.panelalpha` (where hooks keep what must survive a redeploy)
is 0700 and also holds the engine's own files (`app-credentials.env`, tunnel
tokens). Bind only the subdirectory or file a service needs
(`../.panelalpha/<app>:/pa-data/<app>`), never the whole directory, and create
that subdirectory in `hooks/prepare.sh` so it is the account's: a bind source
that does not exist yet is created by Docker as root.

---

## Integration guide

### app.sh contract

The engine calls `overrides/app.sh <command> [args...]` and expects:

- **stdout**: a single JSON value (object or array) on success
- **stderr**: a JSON object `{ "error": "message" }` on failure, exit code 1
- **exit code 0** on success

Commands the engine may issue:

| Command | Args | Expected stdout |
|---|---|---|
| `info` | — | JSON array of supported command names, e.g. `["users:list","users:add","users:sso","install"]` |
| `roles:list` | — | JSON array of role names the app supports, e.g. `["admin","editor"]` |
| `install` | `<url> <title> <admin_user> <admin_email> <admin_password>` | `{ "id": "<adminUserId>" }` |
| `users:list` | — | JSON array of `{ "id", "username", "email", "role" }` objects (`email` may be `""` if the app has no email field) |
| `users:add` | `<login> <email> <password> <role>` | `{ "id": "<newUserId>" }` |
| `users:delete` | `<userId>` | `{ "success": true }` |
| `users:reset-password` | `<userId> <newPassword>` | `{ "success": true }` |
| `users:sso` | `<userId>` | one of the SSO patterns below |

Only advertise commands in `info` that the app actually supports. The engine will not call a command that is not listed.

#### SSO response patterns

Three patterns are supported. Choose the one that best fits the app's auth model:

**Pattern A — app returns a complete URL**
```json
{ "url": "https://app.example.com/some/login?token=abc" }
```
Use when the app has a built-in SSO/login endpoint that accepts a token in the URL (e.g. WordPress `?panelalpha_sso=` handled by an mu-plugin). The engine passes the URL through to the browser unchanged.

**Pattern B — app returns cookie credentials**
```json
{ "cookie": "session", "value": "abc123", "redirect": "/dashboard" }
```
Use when the app uses a server-side session cookie that can be written directly. The engine stores the cookie/value pair, issues a short-lived single-use token, and redeems it via `/panelalpha-sso` on the user's domain — which sets the cookie and redirects to `redirect`. The app never needs to know about the SSO handshake.

**Pattern C — app returns a path, engine prepends domain**
```json
{ "path": "/sso?token=abc&url=%2Fdashboard" }
```
Use when the app has a built-in SSO page but the CLI helper cannot know the public domain (because it runs inside the container). The engine prepends `scheme://user-domain` to `path`. `path` must start with `/` and must be fully formed including all query parameters.

---

### Choosing an app management strategy

The goal is to keep `overrides/app.sh` as simple as possible. Always prefer the option that requires the least custom code. Evaluate in this order:

#### 1. Built-in CLI commands (preferred)

Check whether the app ships CLI tools that already cover the needed operations (user listing, creation, deletion, password reset). If they exist and work without a running server, use them directly from `overrides/app.sh` via `docker compose exec`.

When to use: app has useful CLI commands that cover most or all required operations.

#### 2. Custom CLI commands

If the app has a CLI but it doesn't expose what you need, check whether it supports adding custom commands or scripts (e.g. a `commands/` directory, a plugin entrypoint). Prefer this over calling the web API.

When to use: app CLI is extensible without modifying core source.

#### 3. REST API with unauthenticated bootstrap

If the app has a REST API, use it — APIs are versioned and stable. The challenge is authentication without stored credentials. Look for a way to skip or self-bootstrap auth from the CLI side:
- Derive a valid token from a secret already present in the container (env var, config file, or database) — replicating the app's own token logic in the CLI helper
- Some apps expose an unauthenticated setup/owner endpoint on first boot that can be used to obtain initial credentials

Avoid storing admin credentials in env vars or `.env` files — if the user removes them, operations break. Instead derive auth from secrets the app itself auto-generates (e.g. encryption keys, config files written to the data volume).

**Example: Umami** — CLI helper reads `APP_SECRET` from the container env, replicates Umami's AES-256-GCM token logic in ~40 lines of Node.js, and calls the official `/api/*` endpoints. No credentials stored anywhere.

**Example: n8n** — CLI helper reads the auto-generated encryption key from `/home/node/.n8n/config` (written by n8n on first boot into the data volume), derives the JWT signing secret the same way n8n's own `JwtService` does, reads the owner user from SQLite, and mints a valid `n8n-auth` cookie. No env vars needed.

When to use: app has a REST API; auth can be derived from secrets the app auto-generates; no useful CLI commands exist.

#### 4. Plugin or extension system

If the app is interpreted (PHP, Python, Ruby) and has a plugin or hook mechanism, drop in a custom file that registers the endpoints or CLI commands you need. This avoids replicating any internal auth logic — the plugin runs inside the app's own execution context.

**Example: WordPress** — a must-use plugin auto-loaded from `wp-content/mu-plugins/` registers a custom SSO handler and also can be used as a cli tool.

When to use: app is interpreted; has a plugin/hook system; REST API auth cannot be bootstrapped without stored credentials.

#### 5. Direct database access (last resort)

Read or write the database directly only when no other option is viable. This is the most fragile approach — schema changes on upgrades can silently break the integration.

**Example: listmonk** — compiled Go binary with no plugin system. The API requires valid credentials and there is no bootstrap endpoint. `overrides/app.sh` connects to PostgreSQL via `psql` and reads/writes the `users` and `sessions` tables directly.

When to use: compiled/opaque app; no REST API bootstrap path; no plugin system; DB schema is simple and stable. Always pin expected column names in a comment so schema drift is caught during testing.

---

### Modifying docker-compose.yml

Prefer **`./docker-compose.override.yml`** (a file snippet) over patching the upstream `docker-compose.yml` with `sed`.

Docker Compose automatically merges `docker-compose.override.yml` from the project directory. This means:
- The upstream file is never modified — git pulls still work cleanly
- The override is isolated and easy to reason about
- `overrides/app.sh` can check for `MISSING_SNIPPET` and trigger reinstall

The override file requires Docker Compose to run from the project directory (not with an explicit `-f` path). The engine handles this via `--project-directory ~/project`.

Only provide a full replacement `overrides/docker-compose.yml` when the app's upstream compose is fundamentally unsuitable (e.g. it is a development-only file with hardcoded bind mounts to source code, like n8n or Cal.diy).

#### The site's address

Under the compose strategy every service that is not a database gets `PA_PUBLIC_URL` (`https://<domain>`) and `PA_PUBLIC_HOST` (`<domain>`) in its environment, unless it sets them itself. In the compose file the engine runs (the repository's own, or `overrides/docker-compose.yml`), `${PA_PUBLIC_URL}` and `${PA_PUBLIC_HOST}` inside an `environment:` value are replaced before the stack starts, so `ORIGIN: ${PA_PUBLIC_URL}` needs no entrypoint wrapper. A `docker-compose.override.yml` is layered by Compose itself and is not rewritten; read the variable from the container's environment there. An empty `ORIGIN`, `URL`, `PUBLIC_URL`, `BASE_URL`, `APP_URL`, `ASSET_URL` or `SITE_URL` is filled with the address as well.

Under the framework strategies (`laravel`, `php`, the Node/Python/Ruby recipes, a repository `Dockerfile`) the engine writes the compose file itself, and `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` in the manifest's `env:` are replaced the same way before it is written: `SESSION_DOMAIN: '${PA_PUBLIC_HOST}'` reaches the container as the site's host. `env:` is the service's `environment:`, so it also beats the `.env` the project ships (`env_file`).

---

### The PHP frontend build (`frontend_build`)

A PHP application gets a host Node pass after Composer: the engine installs `package.json`'s dependencies (lockfile, cache, a git-capable image when a dependency is a repository) and runs its `build` script. With no `build` script there is no pass. `frontend_build` changes that, `runtime: php` only:

| Value | Effect |
|---|---|
| absent | the rule above |
| `false` | no Node pass at all — the repository commits its compiled assets (Crater's `public/build`) and its lockfiles no longer install |
| `"<command>"` | the install as usual, then this command instead of the `build` script, even when `package.json` has no scripts. It runs on every deploy, including a `node_modules` cache hit that skipped the install — grocy's `yarn install` fills `public/packages` again after a re-clone. A yarn 1 command wants `--ignore-engines`, as the engine's own install has it |

Do not edit `package.json` from `hooks/prepare.sh` to get either effect.

---

### The application's login (`credentials`)

An application seeded with an admin login declares what it needs; the engine generates the values, keeps them on the project and tells the customer:

```yaml
credentials:
  login_path: /login                          # optional; login_url = public URL + this
  adopt_from: .panelalpha/sonarr/admin.env    # optional; relative to the account home
  fields:
    SONARR_ADMIN_USER: {kind: username, value: admin}
    SONARR_ADMIN_PASSWORD: {kind: password}
```

| Kind | Value |
|---|---|
| `username` | `value`, or `admin` |
| `email` | `value`, or `admin@example.com` |
| `password` | generated: 24 letters and digits with an upper, a lower and a digit; `symbol: true` adds one of `-_!`. `value` is refused |

A `username` or `email` value may contain `{random}` (8 hex characters), `{host}` (the account's domain) and `{email}` (the email the project was created with, or `admin-{random}@{host}` when it has none), filled once when the value is first stored. `value: "{email}"` makes the customer's own address the login.

On every deploy and rebuild, before `hooks/prepare.sh` runs, the engine writes `~/.panelalpha/app-credentials.env` (0600, the account's). A compose service reads it with `env_file: ../.panelalpha/app-credentials.env`, a hook with `. ~/.panelalpha/app-credentials.env`. Stored values are kept; a field with no stored value is taken from `app-credentials.env` itself or from `adopt_from` when either sets it (an account a recipe seeded before the engine owned the login keeps its password), and generated otherwise; a field the project's own `env_vars` set takes that value; a field no longer declared is dropped. `GET /projects/{name}/app-credentials` returns them and `GET /projects/{name}` says they exist. Passwords are masked in the deploy log and telemetry.

Seed only when the application has no user yet, so a password changed in the application stays changed; the API keeps returning the seeded one. Do not generate a password in `hooks/prepare.sh` any more.

---

### Secrets derived from the install path

Every account's checkout is mounted at `/app`, so any value an application
derives from its own location is **the same on every tenant**. On a normal host
two installations are two directories; here they are not.

Look for it before calling a recipe done: `realpath(`, `__DIR__`, `getcwd()` or
`$_SERVER['DOCUMENT_ROOT']` feeding a salt, key, token, session name, cache
namespace or lock name. Known cases:

- Kirby's content salt is `realpath()` of `content/`, and it keys the tokens
  guarding private media, so a token minted on one account is valid on another.
- Atheos names its session cookie `md5(BASE_PATH)`, which is `md5("/app")`
  everywhere.

When you find one, feed the app `PA_INSTANCE_SECRET` (or a hash of it per
purpose) through its own config. The engine sets it in the generated app
service: 64 hex characters, per account, stable across deploys and wipe
rebuilds. A repository's own compose services and Railpack builds do not get
it; there, generate a value per account (`openssl rand -hex 32`) and keep it in
`~/.panelalpha/`, not in `~/project`: a wipe rebuild clears `~/project`, and a
secret regenerated against surviving data is lost for good (engine#173).

---

## Repositories described here

- [n8n](github.com/n8n-io/n8n/) — workflow automation; provides a custom `docker-compose.yml` with a named volume for persistence, and includes an `overrides/app.sh` for app management (info/install/users:list/users:add/users:delete/users:reset-password/users:sso). **Strategy: direct SQLite** — all user management bypasses the REST API entirely and writes to the SQLite database using `node:sqlite` (built into Node.js v22+). `install` calls `POST /rest/owner/setup` with the real public `Host` header so n8n stores the correct instance URL. SSO uses Pattern B (cookie): derives the JWT signing secret from SQLite (auto-persisted by n8n on first boot) and mints a valid `n8n-auth` token by replicating n8n's own `JwtService` + `AuthService` hash logic.
- [Activepieces](github.com/activepieces/activepieces/) — open-source automation; uses the repo's own `docker-compose.yml` and runs `tools/deploy.sh` to generate `.env`; includes an `overrides/app.sh` for app management (info/install/users:list/users:add/users:delete/users:reset-password). **Strategy: REST API** — a Node.js CLI helper reads `AP_JWT_SECRET` from the container environment, queries PostgreSQL for the platform admin's `tokenVersion`, mints a short-lived HS256 JWT, and calls the official `/v1/` REST API. `users:add` uses the invitation flow (no SMTP required — invitation JWT is minted locally). Password reset goes directly to PostgreSQL (bcrypt, 10 rounds) because no admin REST endpoint exists for that operation. `overrides/docker-compose.override.yml` mounts the helper into the `app` container without modifying the upstream compose file
- [Chatwoot](github.com/chatwoot/chatwoot/) — customer support platform; copies `docker-compose.production.yaml`, generates secure credentials, and runs `db:chatwoot_prepare` before start; includes an `overrides/app.sh` for app management (info/install/users:list/users:add/users:delete/users:reset-password/users:sso). **Strategy: Rails runner** — all user operations run via `docker compose exec rails bundle exec rails runner`; dynamic values injected as `-e PA_KEY=value` env overrides. SSO uses Chatwoot's built-in `SsoAuthenticatable#generate_sso_link` (Pattern A — stores a short-lived token in Redis, returns a full login URL)
- [Cal.diy](github.com/calcom/cal.diy/) — open-source scheduling platform (formerly Cal.com); replaces the source-build compose with a prebuilt-image version, generates secrets, and relies on the app's built-in Prisma migration on first start; includes an `overrides/app.sh` for app management (info/install/roles:list/users:list/users:add/users:delete/users:reset-password/users:sso). **Strategy: direct DB** — a Node.js CLI helper uses `pg` inside the `calcom` container to run raw SQL against PostgreSQL (the `@prisma/client` stub in the monorepo image can't resolve its generated files at runtime); SSO uses Pattern B (cookie): derives the NextAuth v4 JWE session key from `NEXTAUTH_SECRET` via HKDF-SHA256 (salt = cookie name, mirroring next-auth v4.24 `encode()`) and mints a `next-auth.session-token` cookie
- [WordPress](github.com/wordpress/wordpress/) — content management system; provides a custom `docker-compose.yml` with MySQL 8 and named volumes, generates all secret keys and credentials once into `~/.panelalpha/wordpress/` so they survive redeploys, keeps `wp-content` on a volume, and includes an `overrides/app.sh` for app management (info/install/users:list/users:add/users:delete/users:reset-password/users:sso). **Strategy: plugin system** — a must-use plugin handles all API endpoints and SSO (Pattern A)
- [listmonk](github.com/knadh/listmonk/) — newsletter and mailing list manager; uses the repo's own `docker-compose.yml` with an `overrides/docker-compose.override.yml` that creates the Super Admin from generated credentials before listmonk serves, and includes an `overrides/app.sh` for app management (info/users:list/users:add/users:sso). **Strategy: direct DB** — listmonk is a compiled Go binary with no plugin system; user management and SSO (Pattern B) go through `psql` directly against the PostgreSQL container
- [Umami](github.com/umami-software/umami/) — privacy-focused web analytics; generates random secrets once into `~/.panelalpha/umami/` (copied to `.env` on every deploy, since the upstream compose reads them from there) and provides an `overrides/docker-compose.override.yml` that injects those secrets and mounts the CLI helper into the container (the upstream `docker-compose.yml` is never modified); includes an `overrides/app.sh` for app management (info/install/users:list/users:add/users:delete/users:reset-password/users:sso). **Strategy: REST API** — a Node.js CLI helper self-generates a stateless Bearer token from `APP_SECRET` and the admin UUID (via `pg`), then calls the official Umami REST API. SSO uses Pattern C (Umami's built-in `/sso` page; engine prepends the domain)
