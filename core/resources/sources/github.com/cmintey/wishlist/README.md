# Wishlist (github.com/cmintey/wishlist)

A sharable gift list for friends and family, AGPL-3.0. SvelteKit 2 on
`@sveltejs/adapter-node`, Svelte 5 and Skeleton over Tailwind 4, Prisma 7 over
SQLite, with a Caddy inside the image in front of the Node server. This branch
is `main`.

## What was wrong

Detection: `compose`, from the repository's own `docker-compose.yaml`. The
deploy finished successfully in **60.2s** and nothing answered — the proxy
returned **502**, `checks/_baseline/no-server-error` failed, verdict
`serving-error_page`.

The container was restart-looping on one empty value:

```
Error: Invalid ORIGIN: ''. ORIGIN must be a valid URL with http:// or https://
protocol. For example: 'http://localhost:3000' or 'https://my.site'
    at parse_origin (.../build/server/chunks/handler-COkUaS6J.js:1450:9)
  [cause]: TypeError: Invalid URL ... { code: 'ERR_INVALID_URL', input: '' }
Node.js v24.18.0
[ELIFECYCLE] Command failed with exit code 1.
```

`.env.example` ships `ORIGIN=` with nothing after it.
`ProjectEnvironment::apply()` copies `.env.example` to `.env` when the project
has no `.env`, changing only secret placeholders and `${VAR:-default}` keys —
so the empty line came through — and upstream's compose file loads that file
with `env_file: - .env`. ORIGIN therefore reached the container **set and
empty**, and adapter-node validates it. Unset is fine; empty is not, and empty
is what the repository ships. Verified both ways on the live account: a
`docker run` of the same image with no `env_file` starts and prints
`Listening on http://0.0.0.0:3000`.

Two things made it read as a 502 rather than as a crash. `entrypoint.sh` starts
Caddy **first and in the background**, and Caddy binds `:3280` and proxies to
`:3000` — so the port kept answering for the whole of the loop, with
`dial tcp :3000: connect: connection refused` in its log. And `docker compose
up -d` had long since returned, so the deploy was already green.

## Workstation stack, or real deployment?

Real deployment, and it was kept. The repository's `docker-compose.yaml` is one
service, no `build:`, the image upstream publishes at `ghcr.io/cmintey/wishlist`,
and it is character-for-character the file the README's "Getting Started" tells
every operator to write. There is no dev-only Postgres, no mailhog, no bind
mount of the source. So `overrides/docker-compose.yml` keeps its shape — same
image, same port, same two volumes — and changes only what a hosting account
has to change. The engine sets the repository's copy aside by name
(`Set aside docker-compose.yaml so docker compose uses the hosting file`):
`docker-compose.yml` outranks `docker-compose.yaml` in
`ComposeFileInspector::COMPOSE_FILE_CANDIDATES`, and
`AppConfigBootstrap::writeCompose()` stashes the shadowed one.

Building the checkout instead was considered and rejected. The `Dockerfile` is
real and production-shaped, but it is a three-stage build that installs
`build-essential` and compiles `sharp` and `better-sqlite3` from source, runs a
full `vite build`, and downloads a Caddy release — minutes and well over 2 GB,
per account, to arrive at the image that is already published.

The cost is a version difference: the image is the last *release* (the GHCR tag
list ends at `v0.66.0`) while the checkout is `main`. A migration merged after
the release is in `~/project` and not in the database — `list.hideOwner`, from
`20260624143856_add_list_hide_owner`, was the one visible in this test. Nothing
reads the checkout at runtime, so that is a version difference and not a broken
deploy.

`:latest` and not a pin, exactly as upstream's own compose file and README
leave it. There is nothing in the checkout to pin to: `package.json` says
`"version": "0.0.1"` — the releases are git tags, and the engine's clone fetches
none (`.git/refs/tags` is empty on a deployed account). `pnpm prisma migrate
deploy` on boot is upstream's supported upgrade path, which is what makes
`:latest` safe across a redeploy.

## What the engine could not infer

**ORIGIN.** It is the account's public address, which does not exist until the
domain does. For a compose project the only path that value has into an
application is `ComposePlaceholders::isLocalPublicUrl()` — a key matching
`PUBLIC_URL_KEY_PATTERN` (`/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i`,
`ComposePlaceholders.php:74`) whose value is a bare localhost, in a compose
`environment:` block. So the compose file ships `ORIGIN: http://localhost` and
the deploy log says *"Pointed the application address at its public URL:
ORIGIN"*. `hooks/prepare.sh` writes a `.env` with no ORIGIN line at all, so
nothing stale can shadow it — and a compose `environment:` value outranks
`env_file:` in any case.

**Where the data lives.** Upstream bind-mounts `./data` and `./uploads`, inside
the project directory. A redeploy clears and re-clones `~/project`
(engine#173): every list, item, claim and uploaded image would be destroyed by
the next deploy. Measured on the stock deploy — both directories were created
inside `~/project`, root-owned, with `prod.db` in one of them.

**Who owns the installation.** See *Security*.

## Where the data lives

`~/.panelalpha/wishlist`, 0700 and created by `hooks/prepare.sh` before the
mounts are made, so Docker never gets to create them as root:

| Path | What |
|---|---|
| `data/prod.db` | every list, item, claim, user and setting |
| `data/.panelalpha-installed` | the marker that makes the install step run once |
| `uploads/` | user-uploaded item images, served by Caddy at `/api/assets/*` |
| `credentials` | the generated admin account, 0600 |

`~` is root-owned 0755 and nothing can be created there; `~/.panelalpha` is
created with the account and belongs to it, which is why the data directory is
a child of that one. Relative paths in the compose file resolve against
`--project-directory`, which is `~/project`, so `../.panelalpha/wishlist/...`
is the account's home.

SQLite and no database sidecar: `prisma/schema.prisma` has
`provider = "sqlite"` and nothing else, and `entrypoint.sh` hard-codes
`DATABASE_URL="file:/usr/src/app/data/prod.db"`. There is no other shape to
choose.

**Verified across a redeploy** (`POST /projects/{u}/rebuild`): `~/project` is
re-cloned and `~/.panelalpha/wishlist` is untouched. The same password still
signs in, *Holiday 2026* and its *Espresso machine* still read back with the
price and the note, the uploaded `.webp` is still on the mount and still 200 at
`/api/assets/`, `/signup` is still 401, the rewritten `ORIGIN` is back in the
new compose file, and the install step reports *"already installed on an
earlier deploy; leaving the account and the signup setting alone"*. `prod.db`
is the same size but not byte-identical — SQLite checkpoints its WAL and the
sign-ins wrote session rows, which is the database being used rather than
re-created.

## Security

**Registration is closed and an account is created instead.** Wishlist has no
installer and no CLI, and it is first-visitor-wins twice over:

- `routes/setup-wizard/+page.server.ts` serves the wizard to anybody at all
  while the `user` table is empty — it only redirects away once `userCount > 0`.
  `/setup-wizard` is in `hooks.server.ts`'s `nonPrivateRoutes`.
- `routes/signup/+page.server.ts` writes
  `userCount > 0 ? Role.USER : Role.ADMIN`, so that first account is the admin.
- And creating an account does not close the door: `getDefaultConfig()` in
  `lib/server/config.ts` has `enableSignup: true`, the setting lives in the
  `system_config` table rather than in the environment, and `/signup` is a
  non-private route too.

So `hooks/prepare.sh` generates a 20-character password into
`~/.panelalpha/wishlist/credentials` (0600, in a 0700 directory), and
`files/panelalpha/install.mjs` creates the account **and** writes
`enableSignup = false` in one transaction.

It runs **from the entrypoint, before the server binds** — not as a one-shot
compose service beside the app, the way NocoDB's does. A one-shot can only
start once the app is healthy, which is once the app is answering, and the
window this exists to close is exactly that moment. Nothing is listening on
:3000 until the last line of `files/panelalpha/entrypoint.sh`.

It is guarded twice: by `SELECT COUNT(*) FROM user` and by
`data/.panelalpha-installed` on the bind mount. A redeploy never creates a
second account, never resets a password somebody chose, and never overrides an
operator who later re-opened signup under Admin > Settings.

**The decision to close it.** Wishlist's own default is open, and for a family
running it on a LAN that is reasonable. On a public HTTPS name the engine has
just published it is not: an open Wishlist with no users hands the admin role
to the first stranger who loads the page, and an open Wishlist *with* users
lets anyone join the default group, where the default list lives and where
claims and suggestions are visible. Closed is the safe default, and it is one
toggle to undo — from inside the admin panel, by the person who owns the
account, who can also generate invite links there without SMTP.

**Only node builtins in the install script.** The image's `node_modules` are
laid out by pnpm and `install.mjs` is not part of the package, so a bare import
would not resolve. `node:sqlite` and `node:crypto` are enough:
`lib/server/password.ts` is scrypt `N=16384 r=16 p=1 dkLen=64` over the NFKC
form of the password with a 16-byte hex salt used as text, stored as
`s2:<salt>:<key>` — `crypto.scryptSync` with `maxmem` raised (128·N·r is 32
MiB, over node's default) produces exactly that. Verified by signing in with the
generated password over the public domain, and by a wrong password being
rejected with 400.

**The password is never an environment variable.** It reaches the container as
a read-only bind mount of the 0600 file. The compose file the engine writes is
0644 and readable by every other tenant (engine#173), and `docker inspect`
would show an environment value too.

**Nothing from the checkout is web-reachable.** The container serves the
image's own build output plus `uploads/` (Caddy's `handle_path /api/assets/*`);
`~/project` is not a document root at all. Measured against the live domain:
`.env`, `.env.default`, `.env.example`, `.git/config`, `docker-compose.yml`,
`docker-compose.yaml`, `docker-compose.yaml.pa-stashed`,
`panelalpha/install.mjs`, `panelalpha/entrypoint.sh`, `data/prod.db`,
`uploads/`, `Dockerfile`, `package.json`, `prisma/schema.prisma` and
`api/assets/../../data/prod.db` are all **404**. `.env` and `.env.default` are
0644 on disk as engine#173 describes, and hold nothing secret — the password is
in `~/.panelalpha/wishlist/credentials`.

## Readiness

There **is** a `ready` gate here, and it was measured rather than assumed.

`AppLauncher` runs `docker compose up -d` without `--wait` and the deploy is
finished when that returns. Caddy binds `:3280` before the migrations run, so
the port answers for the whole of the first boot — 45 Prisma migrations, the
seed, three patches and the install step — with a 502 of its own. That is a
guaranteed false negative, not a risk of one.

`ready` is a no-op on `alpine:3` that waits for the app's healthcheck, so
`up -d` returns only once `/login` really serves. Measured on two clean
deploys: the app container started and `ready` exited 0 **21.8s** and **17.8s**
later, with `dial tcp :3000: connect: connection refused` 502s logged by Caddy
in between, one per healthcheck probe. `alpine:3`
rather than the app image so that nothing large is pulled for a container that
exits immediately, and a clean exit 0 is explicitly not a crash loop to
`AppHealth::isCrashing()`.

The healthcheck is `node -e "fetch('http://127.0.0.1:3280/login')…"`. `/login`
is unauthenticated and is served by SvelteKit rather than by Caddy's static
handler, so it is only 200 once node is really serving — and the image is node
on debian-slim, which has neither `curl` nor `wget`. engine#190 does not apply:
the probe's `Host: 127.0.0.1` is irrelevant because ORIGIN is set explicitly,
which is also what makes the loopback port probe read 200.

## Verified

On an 8-core / 15 GB host under an unrelated batch at load ~6, account capped
at 2000 MB: `deploy-ok`, **90.3s** cold (engine timings: preparing 8s, cloning
3s, running 75s, of which 41s was the image transfer) and **75.2s** on a second
clean deploy with the image already in the host cache, port probe **HTTP 200**,
`serving: ok`, every baseline check passing, `GET /` → 200 with the title
*Sign in* — the login page, not the setup wizard, which is what says the
account already exists. For comparison the stock deploy was 60.2s to a 502.

Against the account's real HTTPS domain, with the credential from
`~/.panelalpha/wishlist/credentials`:

- `GET /` 307s to `/login`, `GET /setup-wizard` 302s away, `GET /signup` is
  **401** — *this instance is invite-only*.
- `POST /login` with a wrong password returns
  `{"type":"failure","status":400,…,"incorrect":true}`; with the generated one,
  `{"type":"success","status":204}` and a `Secure; HttpOnly; SameSite=Lax`
  session cookie.
- `POST /lists/create?/persist` creates the list *Holiday 2026* and redirects
  to `/lists/ra9o72zao8`.
- `POST /lists/{id}/create-item` adds *Espresso machine*, €249.99, with a URL
  and a note.
- `GET /lists/{id}` renders 200 with the list name, the item, the price and the
  note all present, and `GET /lists` shows the list. That is the product.
- An item image uploaded into the container lands at
  `~/.panelalpha/wishlist/uploads/gift-with-a-picture-*.webp` and
  `GET /api/assets/<that file>` returns **200 image/webp** over the public
  domain — the uploads mount and Caddy's asset handler both work.

## Not done

- **`multipart/form-data` POSTs never reach the app through the public
  domain.** Every one of them — with a file or without — is answered by the
  engine's proxy with a 302 to
  `https://www.withoutdns.com/internal-server-error.html/`, and nothing is
  logged by the container. The same request to `127.0.0.1:3280` inside the
  account succeeds (200, image written, asset served), so this is the host's
  proxy and not the recipe or the app. It was not chased further here, but on
  this host an operator cannot upload an item image through a browser.
- **The authenticated screens were not driven in a real browser.** Every
  request above went to the public HTTPS domain and the responses were read as
  server-rendered HTML and SvelteKit action JSON, but nobody typed the password
  into the form and watched the Svelte app render the list.
- **No `overrides/app.sh`.** Wishlist has an admin role but no user API and no
  SSO hook the engine could drive; `users:list` and `users:add` would mean
  writing `user` and `user_group_membership` directly, which is what the install
  step does once and deliberately. Left out rather than written untested.
- **Mail is not configured.** Wishlist works without it — invite links and
  password-reset links can be generated and copied by hand from the admin panel
  — but emailed invitations need an SMTP server the engine does not provide, and
  it is configured in the admin panel rather than through the environment.
- **OIDC is not configured**, for the same reason: it is admin-panel state, not
  environment, and it needs an identity provider.
- **The database file is root-owned** (`prod.db`, root:root, inside an
  account-owned 0700 directory). The published image runs as root and Caddy
  writes to `/root/.config/caddy`, so running the service as the account's uid
  would need `HOME` moved as well; it was not attempted. The account can delete
  the directory but cannot read the database over SFTP.
