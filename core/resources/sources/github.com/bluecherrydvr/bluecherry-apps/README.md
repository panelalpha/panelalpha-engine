# Bluecherry DVR (github.com/bluecherrydvr/bluecherry-apps)

A network video recorder. `bc-server` is a C++ daemon that records IP cameras
over RTSP and ONVIF into files plus a MySQL database; `www/` is the PHP
administration interface; `/hls` (:7003) and `/api` (:7005) are bc-server's own
listeners, proxied by the same nginx that serves the UI.

Detection: `php`. The repository has no compose file Docker auto-loads, no
Dockerfile at its root, and a `composer.json` under `www/`, so the PHP strategy
won. It first produced `serving-missing_entry` — a 403, because `PhpDocroot`
did not know about `www/` — and then, once `www` was added to
`PhpDocroot::LATE_CANDIDATES`, `deploy-ok` with the interface on screen.

**That `deploy-ok` is the trap.** What Apache serves is the administration
console with nothing behind it: no `bc-server`, no database, and no
`/etc/bluecherry.conf` for `www/lib/db.php` to read credentials from. Recording,
the ONVIF and RTSP clients, the live-view stream and the JSON API are all the
compiled binary, which the PHP strategy neither builds nor starts. The product
is the daemon; the PHP is its front end.

## It does not need a capture card

This repository looks more like a hardware rejection than any other in the
catalogue — `lib/v4l2_device_solo6x10.cpp`, `lib/v4l2_device_tw5864.cpp`, a
`libudev` PCI enumerator in `server/bc-detect.cpp`. All of that is for
Bluecherry's own optional capture cards.

It was deployed to check. In an ordinary unprivileged container with no
`/dev/video*`, no `--device` and no kernel module, the enumerator finds nothing,
`bc-server` carries on, and the logs contain no video, solo6x10, udev or card
errors. That is the IP-camera path, which is how the product is sold today.
Idle: **49.5 MiB** for the application container, **104.7 MiB** for MariaDB.

## The image is the artifact

Upstream publishes `bluecherrydvr/bluecherry` **from this repository**:
`actions/Dockerfile`, `actions/entrypoint.sh`, `actions/supervisord.conf` and
`.github/workflows/docker-build.yml` are all in the tree. It is not a build of
the checkout even there — the Dockerfile's payload is
`curl "$BLUECHERRY_DEB_URL"`, a release `.deb` built elsewhere and then repacked
with its maintainer scripts stubbed out. GPL v2, no hosting restriction.

`hooks/prepare.sh` reads the version from `debian/changelog`
(`bluecherry (3:3.1.15) …` → `3.1.15`) and uses that tag if Docker Hub has it.
Usually it does not: of 36 tags only `3.1.9` and `3.1.0-rc8` are version tags,
the rest being `pr-NNN`, `sha-…` and date stamps. The fallback is `:latest`.

**Not `:stable`.** It is the name that sounds like the careful choice and is the
wrong one here:

| tag | last pushed |
| --- | --- |
| `master` | 2025-12-29 |
| `latest` | 2025-10-11 |
| `3.1.9` | 2025-06-06 |
| `stable` | **2024-04-06** |

`stable` is eighteen months behind `latest`. `master` is newer than `latest` but
is a rolling branch build.

## The UI is HTTPS-only on a port nothing routes to

`nginx-configs/bluecherry.conf`:

```nginx
server {
#  listen 80;
  listen 7001 ssl;
  root /usr/share/bluecherry/www;
```

The certificate is the snakeoil pair `actions/Dockerfile` copies out of
`/etc/ssl`. Port 80 inside the published image is Debian's **default site** —
`actions/Dockerfile` never removes it and the `.deb` reinstates
`sites-enabled/default` — so proxying an account's domain at the container as it
ships serves "Welcome to nginx!".

The engine's proxy can speak https upstream (`NginxProxy.php:607`), but the
app-deploy path hardcodes `'upstream_protocol' => 'http'`. Changing that is a
bigger change than this application deserves, so the recipe supplies a vhost
instead.

`files/bluecherry-http.conf` is mounted at
`/etc/nginx/sites-enabled/default`, and that single mount does both jobs: it
replaces the Debian default site and adds a plain-HTTP server for
`/usr/share/bluecherry/www`, carrying over `bluecherry.conf`'s `/hls` → `:7003`
and `/api` → `:7005` proxies and its `php-generic.conf` include. `default` is a
symlink to `sites-available/default` in the image; Docker resolves it, so the
file it points at is what is replaced either way.

The snakeoil listener on 7001 stays in the image, unpublished and unused. TLS
for the account's domain is the engine's, on a real certificate.

## The database bootstrap is broken unset

`actions/entrypoint.sh` defaults the values and passes them **positionally**:

```sh
DB_NAME="${BLUECHERRY_DB_NAME:-bluecherry}"
…
/bin/bc-database-create "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST"
```

`actions/bc-database-create.sh` ignores its arguments entirely and reads the
environment, with no defaults of its own:

```sh
/usr/share/bluecherry/bc_db_tool.sh new_db \
    "$MYSQL_ADMIN_LOGIN" "$MYSQL_ADMIN_PASSWORD" \
    "$BLUECHERRY_DB_NAME" "$BLUECHERRY_DB_USER" "$BLUECHERRY_DB_PASSWORD" \
    "$BLUECHERRY_DB_HOST" "$BLUECHERRY_USERHOST"
```

Unset, the first boot runs `DROP DATABASE IF EXISTS ; CREATE DATABASE ` and the
container exits with `ERROR 1064 … DB create failed`. The compose file sets all
of them, and sets the host allowance under **both** names, because
`bc-database-create.sh` reads `BLUECHERRY_USERHOST` while the entrypoint's own
`/etc/bluecherry.conf` and `GRANT` block read `BLUECHERRY_DB_ACCESS_HOST`.

### One error in the log is expected

After the `GRANT` succeeds, the entrypoint runs

```sql
ALTER USER 'bluecherry'@'%' IDENTIFIED WITH mysql_native_password BY '…';
```

which MariaDB 11 answers with `ERROR 1064`. The block is wrapped in
`|| log 'WARN: grant/alter failed; continuing'`, the privileges the application
needs were granted by the statement before it, and the account authenticates
normally. **A health check must not treat that line as fatal.**

## The administrator, and the door it leaves open

Bluecherry has no installer and no first-run wizard. `bc_db_tool.sh new_db`
loads `misc/sql/initial_data_mysql.sql` into every new database, and its first
statement is:

```sql
INSERT INTO Users (`username`, `password`, `salt`, …) VALUES
  ('Admin', 'b22dec1d6cfa580962f3a3796a5dc6b3', '1234', …);
```

`b22dec1d6cfa580962f3a3796a5dc6b3` is `md5('bluecherry' . '1234')`.
`www/lib/lib.php` confirms it from both directions — `checkPassword()` is
`md5($password.$salt) === $password`, and `user::getInfo()` flags
`default_password` when the stored hash equals `md5('bluecherry'.$salt)`. The
only consequence in the UI is a dismissible banner.

So a fresh instance on a public HTTPS name is an administrator login of
**`Admin` / `bluecherry`** for anyone who knows the product. This is not a
first-visitor-wins window that closes — it stays open until a human changes it.

`files/panelalpha-setup.sh` replaces that row with a password generated per
account, then signs in with it and fetches `/devices` (an `access_setup` page)
to prove the replacement works:

```sql
UPDATE Users
   SET salt = '<new>', password = MD5(CONCAT('<generated>', '<new>'))
 WHERE username = 'Admin'
   AND password = MD5(CONCAT('bluecherry', salt));
```

The `WHERE` clause is the same test `lib.php` makes, which is what makes this
safe to run on every deploy: it is idempotent, and on an account whose owner has
since changed their password it matches nothing and the script says so instead
of logging a sign-in refusal. Verified in both directions — the shipped
credential is refused (`{"status":"2",…}`), the generated one is accepted
(`{"status":"1","msg":["\/"]}`), and a customer-chosen password survives a
redeploy untouched.

`salt` is `char(4)` and the application's own generator is
`data::getRandomString(4)` over `[0-9a-z]`, so the salt matches that shape; the
entropy is in the 20-character alphanumeric password. The hash is MD5 because
that is the scheme the application implements — nothing in a recipe can change
that without patching `www/`.

## Where the credential lives

`~/.panelalpha/bluecherry.env`, mode 0600, directory 0700 — **not** in the
project:

```
BLUECHERRY_ADMIN_USERNAME=Admin
BLUECHERRY_ADMIN_PASSWORD=…
BLUECHERRY_ADMIN_SALT=…
BLUECHERRY_DB_PASSWORD=…
MARIADB_ROOT_PASSWORD=…
MYSQL_ADMIN_PASSWORD=…   # the same value, under the name the app image reads
```

That file is also the compose stack's `env_file`, at
`../.panelalpha/bluecherry.env` — relative to compose's `--project-directory`,
which is `~/project` (`Dind\Paths::composeCommand`). So the secrets reach the
containers without ever being written into the project, and `~/project/.env`
holds one line: the image tag.

Two reasons, both engine behaviour rather than preference.

**The guard.** The engine wipes and re-clones `~/project` on every deploy
(engine#173), so the `if [ -f .env ]` guard every other recipe uses never fires
on a redeploy — it would regenerate the database password while `db_data` still
held the old one, and regenerate an admin password nobody was ever told.
`~/.panelalpha` survives the clone. The account's home is root-owned `0755`, so
`hooks/prepare.sh` creates the directory rather than assuming it.

**The leak.** `~/project/.env` is copied to `~/project/.env.default` at mode
**644** (`ProjectEnvironment::apply`), inside a home that is `root:root 0755` and
a project directory that is `0755`. So anything in `.env` is readable by every
other account's uid on the same host. Confirmed on a live deploy, reading one
account's file as another account's user:

```
# su -s /bin/sh -c 'cat /home/<acct>/project/.env.default' <other-acct>
BLUECHERRY_ADMIN_PASSWORD=…
BLUECHERRY_DB_ROOT_PASSWORD=…
```

That is why nothing secret goes in `.env` here. It is the same engine#173 that
breaks the guard, but this half of it is a cross-tenant credential disclosure
rather than an inconvenience.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait` (engine#90), so the
deploy is "finished" when containers have been *started*. Here that gap is the
whole first boot: MariaDB initialises a data directory, then the entrypoint
waits for it, creates the schema, loads the initial data and only then starts
`bc-server`.

`ready` (`entrypoint: exit 0`, `restart: "no"`) waits on
`setup: service_completed_successfully`, which waits on the application's
healthcheck. So `up -d` returns only once the login page answers, the shipped
credential is gone and the generated one has signed in. A clean exit 0 is
explicitly not a crash loop to `AppHealth::isCrashing()`.

The application healthcheck is deliberately not the image's own, which is
`pgrep -f '/usr/sbin/bc-server'` and passes while nginx is still 502. It is:

```sh
curl -fsS -m 5 http://127.0.0.1/login | grep -q 'Bluecherry DVR'
```

— the vhost this recipe adds, through php-fpm, matching the login page's own
`<title>`, so a PHP fatal rendered with a 200 does not pass either. `curl` is in
the image and nginx has `server_name _`, so engine#165 does not apply.

Both one-shots set `healthcheck: {disable: true}`: they run the application's
image, whose `HEALTHCHECK` is false in a container that runs neither nginx nor
`bc-server`.

## Storage

**This is the part to think about before selling it.** `bc-server` writes
recordings continuously — that is the whole product.

`initial_data_mysql.sql` seeds one Storage row:

```sql
INSERT INTO Storage VALUES (1, '/var/lib/bluecherry/recordings', 95.00, 90.00);
```

95% and 90% are `max_thresh` and `min_thresh`: start deleting the oldest
recordings when the filesystem is 95% full, stop when it is back to 90%. They
are percentages of the **filesystem**, taken from `statvfs`, not of any quota
Bluecherry knows about.

The recipe puts `/var/lib/bluecherry` on a named volume (`bluecherry_data`),
which covers `recordings/` and `monitor.rrd`, the round-robin database behind
the Health and Statistics pages. The whole directory rather than `recordings/`
alone, so a redeploy does not throw the monitoring history away.

Measured on a live account, not reasoned about:

- **It survives a redeploy.** The volume outlives `docker compose down` and the
  `~/project` re-clone. Verified: a rebuild kept the data and the credentials.
- **`df` inside the container reports the host's root filesystem.** Not the
  account's share of it — the actual figure from a deployed account was
  `/dev/sda1 150G 118G 26G 82% /var/lib/bluecherry`, which is the host disk
  every other tenant is also on. Bluecherry's 95% ceiling is therefore 95% *of
  the host disk*, and a camera left recording will drive it there and hold it
  there by design.
- **The account's disk quota does not catch it.** The engine's limit is
  `setquota -u <account>` (`Project::configureQuota`), an ext4 **uid** quota.
  Recordings are written by `bc-server` as the container's `bluecherry` user,
  and they land on the host owned by **uid 1001** — verified against an account
  whose own uid was 1081, with uid 1001 assigned to no host user at all. So
  nothing Bluecherry records is charged to the account that owns it. (On the
  test host no quota was active at all: `/` is mounted `rw,relatime` with no
  `usrquota`.) The panel's own usage figure is `du -shm` run as the account
  user, and `~/docker` is `root:root 0700`, so it does not see the volume
  either.
- Lowering the thresholds does not cap the footprint: they are still
  percentages of the same filesystem.

Rough rate: one 2 Mbit/s camera is about **22 GB/day**.

Bounding it means a size-capped volume, a filesystem `statvfs` actually
reports per account, or the operator editing the Storage row. None of that is
expressible in a compose file. **Sizing and policy decision for whoever offers
this — not a deployment blocker, but not a detail either.**

## Not configured

- **Mail.** Event notifications need an SMTP server the engine does not
  provide: Settings → E-mail, or `G_SMTP_*` in `GlobalSettings`.
- **Cameras.** Added by the customer against their own RTSP/ONVIF endpoints.
  This is the one part of the application no deploy here exercises.
- **Licensing.** The UI has a Licenses page that activates a key through
  `/usr/lib/bluecherry/licensecmd` against Bluecherry's own service. Nothing
  here activates one, and no camera limit was found enforced in the tree.
