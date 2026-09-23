# SolidInvoice

<https://github.com/SolidInvoice/SolidInvoice> — self-hosted invoicing
(clients, quotes, invoices, payments). Symfony, shipped as one static
FrankenPHP binary with the whole application gzipped inside it.

## The admin account is created before the site is reachable

SolidInvoice seeds no user and has no default password. Until it is installed,
`InstallBundle\Listener\RequestListener` redirects **every** route to
`/install`, and `/install` is `PUBLIC_ACCESS` — so a deploy that stops at "the
wizard renders" has published a form on which the first stranger to arrive
becomes the only administrator.

This recipe runs upstream's non-interactive installer from a one-shot `install`
service that has to exit 0 before the web server is started at all. The
password is generated per account and written to

    ~/project/.panelalpha-admin-password        (mode 0600)

together with the login address, which is `admin@example.com` — hooks are told
nothing about the account, so the address is a fixed placeholder and the
password is the secret. Change both from *Profile → Edit*.

## What went wrong without the recipe

The tracker run ended `serving-unknown`: nothing on port 8765, the domain 502,
and `app (Restarting (137))` in the health report. Two failures stacked.

**The unpack is expensive.** `initializeApp()` (frankenphp/app.go) untars the
embedded application into `$HOME/.SolidInvoice` before cobra dispatches any
subcommand. Measured here, that untar needs more than 1280 MB of cgroup — the
dirty pages are charged to it — and is comfortable at 1900. `ServiceLimits`
gives an unlisted `app` service a share of the account, the kernel killed it
mid-untar, `restart: always` brought it back, and round it went.

**And a killed unpack is permanent.** `extractEmbeddedApp()` decides it has
already run by `os.Stat` on the target directory alone. A half-unpacked tree
therefore satisfies it for ever: the container comes up `Up`, `bin/console` and
`Caddyfile` are missing, Caddy logs `reading config from file: ... no such file
or directory`, the messenger worker fatals once a second, and nothing ever
listens. Reproduced here exactly, with an `app` service capped at 1280 MB.

So the unpack moved into the `install` service, which does it once into a
volume the `app` service shares, and which deletes an `app_*` directory with no
`bin/console` before trying again. `app` is then sized for serving (measured
resident set ≈ 600 MB) rather than for an untar it no longer performs.

## Three upstream bugs in the published image

`:latest` is **3.0.1** (`SOLIDINVOICE_VERSION` in the image), while the branch
the engine clones is `3.1.x`. Each of these is fixed on 3.1.x and each still
has to be worked around for the released image.

| | |
|---|---|
| **`solidinvoice console` always exits 0** | The cobra subcommand calls `frankenphp.ExecuteScriptCLI` and throws away its return value, under `Run:` not `RunE:`. So neither "is it installed?" nor "did the install work?" can be answered by an exit status. Both read `/etc/solidinvoice/solidinvoice.list.php` — the index of SolidInvoice's own secret vault — for `SOLIDINVOICE_INSTALLED`, which `InstallCommand` writes last, after the admin user. Upstream's own `isAppInstalled()` has the same hole, which is why the messenger worker starts consuming against a database that does not exist yet. |
| **The installer's steps never run** | 3.0.1's `install()` calls `$step->execute(...)` on five steps whose `execute()` is a `Generator`, and iterates none of them. "Generating secret", "Creating database", "Creating database schema" print their labels and do nothing; the run then dies in `createAdminUser()`. 3.1.x's fix carries the comment *"execute() returns a Generator; it must be iterated for the step body to run"*. |
| **The database configuration is written in a format nothing reads** | 3.0.1's `saveConfig()` seals `SOLIDINVOICE_DATABASE_DRIVER`, `_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASSWORD`, `_VERSION`; `config/packages/doctrine.php` reads exactly one key, `SOLIDINVOICE_DATABASE_URL`. The only code that assembles one from the parts (`CoreBundle\Config\Loader\EnvLoader`) runs solely when it finds a legacy `config/env.php`, which a container never has. A CLI install therefore falls back to the default `sqlite:///$SOLIDINVOICE_CONFIG_DIR/db/solidinvoice.db` and dies on `SQLSTATE[HY000] [14] unable to open database file`. 3.1.x seals `database_url` directly. |

The recipe answers the last one by setting `SOLIDINVOICE_DATABASE_URL` as a real
environment variable on both services — a real env var outranks the vault in
Symfony, so it is correct on 3.0.1 and still correct on 3.1.x — and the second
by doing the missing step's work itself.

### Building the schema is not running the migrations

`InstallBundle\Installer\Database\Migration` creates a fresh schema from the
**entity metadata** (`SchemaTool::getUpdateSchemaSql($tables, true)`) and then
marks every migration complete in the metadata storage *without executing any
of them*. `migrations/` is an upgrade path, not a build. Replaying it on an
empty MySQL 8 does not merely take longer — it dies at `Version20305` with
`1828 Cannot drop column 'company_id': needed in a foreign key constraint`,
and the schema is left broken enough that the admin user is then lost to
`Unknown column 't0.totp_secret'`. Both observed here before
`doctrine:migrations:migrate` was replaced by

```
doctrine:schema:update --force
doctrine:migrations:sync-metadata-storage
doctrine:migrations:version --add --all
```

`SOLIDINVOICE_APP_SECRET=bootstrap` is set for those three commands **and
nowhere else**: booting Symfony's security with no secret is fatal
(`A non-empty secret is required.`, `SignatureHasher`) and on a fresh install
there is no secret yet. The real one is a `Defuse\Crypto\Key` that the
installer seals into the vault a moment later, and which `McpBundle`'s
`KeyManager` loads as an encryption key — so a hex string invented by a shell
script would be the wrong *kind* of secret to leave behind.

## Two things the engine got right on its own

- **The public URL.** `SOLIDINVOICE_APPLICATION_URL` is written as a bare
  `http://localhost` and `ComposePlaceholders` rewrites it to the account's
  https address (`Pointed the application address at its public URL:
  SOLIDINVOICE_APPLICATION_URL` in the deploy log). Hooks are told no domain,
  so this is the only way the stack can learn it. Verified: the login form's
  `action` is the account's own `https://…/_login_check`.
- **The Host header.** No workaround needed, unusually. The image sets
  `SOLIDINVOICE_DOCKER=true`, so `serverconfig.BuildServerName` makes the Caddy
  site address `http://:8765` — a port with no hostname, matching any `Host` —
  and the engine's probe on `127.0.0.1:8765` is answered rather than 400ed
  (engine#165).

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | Description only — no `extends`, so detection reads the compose file this recipe writes |
| `hooks/prepare.sh` | Generates the MySQL passwords and the admin password once into `~/.panelalpha/solidinvoice.env`, writes `.env` for compose and `.panelalpha-admin-password` for the customer |
| `overrides/docker-compose.yml` | mysql:8.0, the one-shot `install`, the app on `8765:8765`, and a `ready` gate on the app's healthcheck |

Secrets live in `$HOME`, not in `~/project`: the project directory is emptied
and re-cloned on every deploy while the MySQL volume and the vault volume
persist, so a regenerated database password would be one the database no longer
accepts and a regenerated admin password would be one nobody was ever told.

## Readiness

`docker compose up -d` runs without `--wait`, so a no-op `ready` service gated
on `app: condition: service_healthy` is what makes the deploy finish only once
the site answers. The app's healthcheck is `/health` (answered by Caddy itself,
proving the server bound) **and** a grep of `/login` for `type="password"`.
The grep is the point: with nothing installed, `/login` 302s to `/install`,
`wget` follows, and the wizard answers 200 — a status-code check would call
that healthy. It caught exactly that during development.

## Verified

Deployed twice through `app-support-batch.py` on a 2500 MB account:
`deploy-ok` both times, 105 s end to end, loopback `:8765` → 302, domain → 200
titled *SolidInvoice - Login*, all seven `_baseline` checks pass. `install` and
`ready` exited 0; the app container's `RestartCount` stayed 0.

Driven as a user over the account's public https domain, not just probed:
`POST /_login_check` with `admin@example.com` and the password from
`.panelalpha-admin-password` returned 302 to `/onboarding`; `/create-company`
accepted a real company and redirected to `/dashboard`, which renders 73 KB of
authenticated page; `/clients`, `/invoices` and `/quotes` all render ~100 KB;
`/static/app.*.css` (226 KB) and the JS bundles serve 200. `/install` answers
302 to `/` and `/register` answers 404, so neither door is left open.

Idempotency was checked by bringing the stack down and up again on the existing
volumes: `install` logged *"SolidInvoice is already installed; nothing to do."*
and the app was healthy 27 s later.

## Not configured, and the known gaps

- **Mail.** `SOLIDINVOICE_MAILER_DSN` stays `null://null`, so invoice e-mails
  and password-reset links are accepted and discarded. Set a real DSN before
  anyone relies on either.
- **Upgrades.** The migration table is marked complete without the migrations
  having run, which is what upstream's own installer does — but it means an
  in-place upgrade to a newer image is untested here. A new image tag also
  unpacks under a new `app_<checksum>` directory and leaves the old one in the
  volume.
- **3.0.1's parting instruction** is *"you must add a scheduled task to run
  every minute"* (`cron:run`). Nothing in this recipe adds one; the container's
  messenger consumer covers async work but not that scheduler.
- **Meilisearch, Sentry, Google OAuth** are all left unset. Registration is
  left closed (`SOLIDINVOICE_ALLOW_REGISTRATION=0`, `/register` → 404).
