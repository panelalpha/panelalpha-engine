# Rocket.Chat (github.com/RocketChat/Rocket.Chat)

Team chat server. `apps/meteor` is a Meteor/Node application serving the REST
API, the DDP websocket and the React webapp on :3000, over MongoDB.

Detection: `railpack` — and that is the failure. The repository root is the
Rocket.Chat monorepo: 83 yarn workspaces under `apps/`, `packages/`, `ee/apps/`
and `ee/packages/`, with no compose file Docker auto-loads and no Dockerfile at
the root. Railpack detected Node, installed yarn 4.18.0 through Corepack and
ran `yarn install --check-cache`; it fetched 3236 packages (+372 MiB) and died
in the link step:

```
#20 ERROR: process "yarn install --check-cache" did not complete successfully: cannot allocate memory
```

The deploy still reported success, because a failed build falls back to a
generated `nginx:alpine` compose — `No compose file and automatic build
unavailable, generating basic compose` — and the account served PanelAlpha's
placeholder page on :8080.

A bigger account would not have fixed it, because the checkout is not the
artifact and the repository's own release recipe says so.
`apps/meteor/.docker/Dockerfile.alpine` begins

```
FROM node:24.15.0-alpine3.23 AS builder
COPY . /app
RUN cd /app/bundle/programs/server && npm install --omit=dev
```

Its context is a finished Meteor bundle, not this tree: `docker-compose-ci.yml`
builds it with `context: /tmp/build`, a directory CI fills from a separate
`meteor build` job. A fresh clone has no `bundle/`, so that Dockerfile cannot
build here at all — there is nothing to point it at. The published image
`rocketchat/rocket.chat` is the artifact, so the recipe runs that.

`overrides/docker-compose.yml` is the stack:

- **mongo** — a single-member replica set. The major is chosen from the host's
  kernel; see *Replica set* and *Which MongoDB* below.
- **rocketchat** — `rocketchat/rocket.chat:<x.y.z>`, the tag resolved by
  `hooks/prepare.sh` from the checkout's own version. Uploads on a named volume
  at `/app/uploads`, the image's only `VOLUME`.
- **setup** — a one-shot that signs in and closes registration. See *Admin and
  registration*.
- **ready** — a no-op that exits 0. See *Readiness*.

Secrets (generated in `hooks/prepare.sh` into `.env`, which compose
interpolates): `RC_ADMIN_PASSWORD`, and the resolved `ROCKETCHAT_IMAGE` beside
it. The hook only writes `.env` when there is none — the mongo volume outlives
the checkout, so a regenerated admin password on a redeploy would be one nobody
was ever told while the account in the database still has the old one. It is
also written to `~/project/.panelalpha-admin-password` (0600), which is where a
human is pointed.

There is no database password. MongoDB runs without auth, reachable only on the
project's compose network — `ports:` is deliberately not published, unlike
upstream's CI file — which is also what keeps the connection string out of
`ComposePlaceholders`' secret-replacement path entirely.

## The tag

Docker Hub carries no major or minor tag for this repository: only exact
`x.y.z` releases, their `-rc.N` and `-fips` variants, and `:latest`. There is no
`release-8` to pin to the way Mattermost has, and `:8` is a 404.

So `hooks/prepare.sh` reads the major from the root `package.json` — `develop`
says `8.9.0-develop`, and `"version"` is the second key in the file so the
first match is the one wanted — then asks Docker Hub for that major's tags,
which come back newest-first, and takes the first plain
`<major>.<minor>.<patch>`. The closing quote in the pattern is what keeps
`8.9.0-rc.0` and `8.8.1-fips` out of the answer. `develop` carries the version
being worked towards, which usually has no release yet, so this lands on the
newest published release of the same major rather than on a candidate.
Anything unreadable or unpublished falls back to `:latest` rather than to a tag
whose pull would fail.

## Replica set

Rocket.Chat needs MongoDB **as a replica set**, not merely MongoDB. It watches
change streams for every real-time update, and the driver refuses a
`replicaSet=` connection string against a standalone `mongod`, so a plain
`mongo` container leaves the server retrying its connection forever.
`rs.initiate()` has to have run before the application connects.

The mongo service's own healthcheck is what runs it:

```
mongosh --quiet --eval 'try { rs.status() } catch (e) {
rs.initiate({_id:"rs0",members:[{_id:0,host:"mongo:27017"}]}) };
quit(db.hello().isWritablePrimary ? 0 : 1)'
```

On a fresh volume `rs.status()` throws `NotYetInitialized`, `rs.initiate()`
runs in the catch, and the probe keeps failing until the node has actually
become primary — which is exactly what `depends_on: condition:
service_healthy` needs to mean here. On a redeploy `rs.status()` succeeds and
nothing is re-initiated. One service, no init container.

The member host must be the compose service name: that is the address the
application resolves the set's one member by after SDAM discovery. `MONGO_URL`
is `mongodb://mongo:27017/rocketchat?replicaSet=rs0` — restated in full rather
than amended, because the image bakes in a string without `replicaSet` and
because sidecar mining has overwritten half-specified ones before. No
`directConnection`: the driver rejects it alongside `replicaSet`.

`--wiredTigerCacheSizeGB 0.25` because a default `mongod` sizes its cache from
the *host's* memory rather than the container's, which on a 2 GB account is
most of it.

## Which MongoDB

`hooks/prepare.sh` picks the major from the host's kernel, and that is not
fussiness — it is the one thing that made the difference between a rolled-back
deploy and a working one.

The first run of this recipe pinned `mongo:8.0`, the version
`docker-compose-ci.yml` tests against. Every container died instantly:

```
{"s":"F","c":"CONTROL","id":12257600,"ctx":"main","msg":"MongoDB cannot start:
Linux kernel versions 6.19 and newer has a known incompatibility with this
version of MongoDB. See https://jira.mongodb.org/browse/SERVER-121912"}
```

That is a fatal exit before `mongod` starts, so the replica-set healthcheck
never passed, `up -d` never returned and the engine rolled the whole deploy
back. It is a refusal, not a crash: MongoDB added the guard itself. It is in
every current 8.x image — `mongo:8.0` and `mongo:latest` both — and `mongo:8.1`
and `mongo:8.2` do not exist. The test host runs `7.0.0-30-generic`, so nothing
in the 8 series can run there at all.

`mongo:7.0` (7.0.43, rebuilt six days before this was written) carries no such
guard and starts normally, and Rocket.Chat accepts it:
`apps/meteor/server/startup/serverRunning.ts` calls `exitIfNotBypassed` only on
`<7.0.0` and merely prints a `DEPRECATION` box on `<8.0.0` — "support for
MongoDB <8.0 will be removed in Rocket.Chat 9.0.0". So 7.0 is supported today
and has a deadline, which is why the choice is conditional rather than a pin:
the hook uses 8.0 on a kernel below 6.19 and 7.0 at or above it. Containers
share the host's kernel, so `uname -r` in the account shell is the kernel
`mongod` will run on.

When MongoDB ships an 8.x image without the guard, the `-ge 19` branch is the
only line that needs deleting.

## ROOT_URL

Rocket.Chat builds every permalink, invitation, password reset, OAuth redirect
and CDN path from `ROOT_URL`; `Site_Url`'s own default is
`__meteor_runtime_config__.ROOT_URL` (`apps/meteor/server/settings/general.ts`).
It is not known until the account's domain exists.

Here the engine needs no help. `ROOT_URL` matches
`ComposePlaceholders::PUBLIC_URL_KEY_PATTERN` — `/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i`,
and this key ends in `_URL` — and a bare `http://localhost` has an implied port
80, which is on `isLocalPublicUrl()`'s allowlist. `UserComposeStrategy`
replaces it with the account's `https://…` URL before the stack starts.

It has to be written in the compose file all the same. The image bakes in
`ROOT_URL=http://localhost:3000`, which the engine would *also* rewrite — port
3000 is on the same allowlist — but only if it were in the compose file for the
pass to see. A key that exists only in the image's `ENV` is invisible to it.

`Site_Url` is re-asserted through the API by the setup service as well: a
default is only consulted for a setting that has never been stored, so on the
second deploy of an account whose domain changed, the stored value is what the
server serves.

## Admin and registration

Rocket.Chat has no installer. Its first boot leaves the setup wizard open, and
the wizard hands the admin role to whoever completes it — on a public HTTPS
name, the first stranger to load the page.

`insertAdminUserFromEnv()` in `apps/meteor/server/startup/initialData.ts`
creates that account from `ADMIN_PASS` / `ADMIN_USERNAME` / `ADMIN_EMAIL` on a
boot where `Roles.countUsersInRole('admin') === 0`, so the compose file carries
them and the wizard has nothing left to give away. The password is generated
per account, never a default, and is built to satisfy Rocket.Chat's own default
policy: `Accounts_Password_Policy_Enabled` is `true`, `MinLength` is 14 and the
character classes are all required, so the suffix `Aa1-` guarantees an
uppercase, a lowercase, a digit and a symbol whatever the random part is.

Two settings still need moving, and they need moving different ways:

- **`Show_Setup_Wizard`** defaults to `'pending'` and is declared
  `readonly: true`, so no settings API call can move it.
  `OVERWRITE_SETTING_Show_Setup_Wizard=completed` in the environment is the
  only lever — `overwriteSetting` is `process.env['OVERWRITE_SETTING_' + key]`
  — and locking a field the admin UI never let anyone edit costs nothing.
- **`Accounts_RegistrationForm`** defaults to `'Public'`, so a fresh instance
  accepts a signup from anyone. This is deliberately *not* done with
  `OVERWRITE_SETTING_`: a value supplied through the environment is locked for
  good, and an operator inviting a team should be able to re-open registration
  from the admin area. `files/panelalpha-setup.sh` signs in through
  `POST /api/v1/login` and turns it off through
  `POST /api/v1/settings/Accounts_RegistrationForm`, leaving the field editable.

The sign-in is also the proof the recipe wants: that the generated password
works against the account the server created from the environment, rather than
that a container started. The script waits on `/api/info` rather than trusting
the healthcheck, because `/livez` answers before the REST layer is registered.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, and the deploy is
finished when that returns — which is when every service has been *started*.
Rocket.Chat's first boot runs its whole migration set against an empty replica
set and takes about a minute, and `setup` being started is not `setup` having
finished.

Compose does honour `depends_on` conditions during startup, so `ready` —
`entrypoint: exit 0`, `restart: "no"`, on
`setup: condition: service_completed_successfully` — makes `up -d` return only
once Rocket.Chat has answered `/livez`, the admin exists, the sign-in as that
admin has succeeded and registration is closed. A clean exit 0 is explicitly
not a crash loop to `AppHealth::isCrashing()`. It runs the curl image rather
than Rocket.Chat's own only because it is already being pulled for `setup` and
is 1/10th the size.

The application healthcheck is the repository's own, from
`docker-compose-ci.yml`: `wget --spider http://127.0.0.1:3000/livez`. The image
is `node:24-alpine`, so busybox `wget` is present and `curl` is not. Meteor
does not validate the `Host` header, so the loopback probe on `127.0.0.1:3000`
is answered normally (engine#165 does not apply).

## Observed

Detection `Docker Compose`, strategy `compose`, port 3000, deploy 136–166s
including both image pulls. Deploy log: *"Pointed the application address at
its public URL: ROOT_URL, PA_PUBLIC_URL"* — both keys rewritten, and no sidecar
mining ran (the repository's `docker-compose-ci.yml`, `docker-compose-ci.fips.yml`
and `docker-compose-local.yml` all use a hyphen, which
`RuntimeSidecars::exampleComposeFilenames()`'s `docker-compose.*.yml` glob does
not match).

Rocket.Chat 8.8.1 on MongoDB 7.0.43. At rest: `rocketchat` 661 MiB of its
1280m cap, `mongo` 253 MiB of 448m — 914 MiB against a 2000 MB account.

Verified over the public HTTPS domain, not just a 200: `POST /api/v1/login` as
`admin` with the generated password returns `status: success` and
`roles: ["admin"]`; `Site_Url` reads back as the account's address,
`Show_Setup_Wizard` as `completed`, `Accounts_RegistrationForm` as `Disabled`;
`POST /api/v1/users.register` as a stranger is refused with
`error-user-registration-disabled`; and `POST /api/v1/chat.postMessage` to
`#general` succeeds, which is the proof the replica set and its change streams
are actually working.

One cosmetic line in the boot log: *"Username provided already exists or is
blocked from usage; Ignoring environment variables ADMIN_USERNAME"*.
`insertAdminUserFromEnv()` seeds `username: 'admin'` on the draft user before
it validates `ADMIN_USERNAME`, so passing the same value it already chose trips
its own availability check. The account is still created as `admin` — the next
log line is `Username: admin`. The variable is kept because the recipe should
say which username it means rather than inherit it silently.

## Not configured

Mail. The admin signs in, channels and direct messages work, but invitations,
password resets and notification emails need an SMTP server the engine does not
provide. Set `SMTP_HOST` and its companions through the account's env vars, or
the Email settings in the admin area.

Cloud registration is off (`OVERWRITE_SETTING_Register_Server=false`), so the
instance does not phone Rocket.Chat Cloud on first boot; the wizard would have
asked. Turning it on later is a workspace registration in the admin area.

The `ee/` microservices in `docker-compose-ci.yml` — authorization, account,
presence, ddp-streamer, queue-worker, omnichannel-transcript, plus NATS — are
the scaled shape and are Premium. The monolith serves all of it in one process,
which is what a single-account instance wants.
