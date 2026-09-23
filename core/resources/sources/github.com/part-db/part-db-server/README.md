# Part-DB (`Part-DB/Part-DB-server`)

An electronics parts inventory: Symfony 7.4, Doctrine ORM, Twig, an
Encore-built frontend. The repository ships its own production Dockerfile and
the engine takes it — this recipe changes four environment variables, adds a
healthcheck, generates two secrets and closes the instance to the public. It
does not touch the checkout.

```
panelalpha.yaml                       extends: dockerfile, and the four variables
hooks/prepare.sh                      generates APP_SECRET and INITIAL_ADMIN_PW, once
overrides/docker-compose.override.yml the second env_file, the healthcheck, the lockdown
```

## Log in

```
url:      https://<domain>/
user:     admin
password: the INITIAL_ADMIN_PW line in ~/.panelalpha/partdb/partdb.env  (0600)
```

Part-DB sets `need_pw_change` on that row, so the first login lands on the user
settings page and asks for a new password before anything else works.

There is no installer and no sign-up page. Every other account is created by
an administrator under Tools → Users.

## Anonymous access is off

Migration `Version1` creates a second row in `users` beside `admin`:
`anonymous`, id 1, in the `readonly` group. That row is the identity of a
visitor who has not logged in, and on a stock install it is not read-only
enough for a public domain. Measured on a deployed account, with no cookie:

| request | stock Part-DB | with this recipe |
|---|---|---|
| `GET /en/part/1` | 200, the part with its stock and storage location | 302 → `/en/login` |
| `GET /en/statistics` | 200 | 302 → `/en/login` |
| `GET /en/category/1/edit` | 200 | 302 → `/en/login` |
| `POST /en/category/new` | 302 → `/en/category/2/edit`, row inserted | 302 → `/en/login` |
| `GET /en/tree/categories` | 200 | 403 |
| `GET /en/login` | 200 | 200 |

The first boot takes the `anonymous` account's group away, which leaves every
permission on *inherit* with nothing to inherit from. It happens once: a
marker file beside the database (`uploads/.panelalpha-anonymous-closed`) stops
it from ever undoing an operator's own choice. **To publish the inventory
after all**, put `anonymous` back into a group under Tools → Users.

One cosmetic consequence: the sidebar trees on the login page read
`Loading… / Access Denied`, because `/en/tree/*` is a `PUBLIC_ACCESS` route in
Part-DB's own `security.yaml` whose data is now denied at the permission
layer.

`readonly` is not fully read-only either — a logged-in member of that group
gets `403` on every edit and admin page but still gets `200` on
`/en/category/new`, and a `POST` there inserts a row. That is upstream's
permission bitfield, it belongs to a group an operator manages, and this
recipe does not rewrite it.

## What survives a redeploy, and what does not

`ProjectTree::clearContents()` empties `~/project` before every deploy, so
nothing in the checkout is data. Two places are not in the checkout:

* **`project_data-var-www-html-uploads`** — the SQLite database (`app.db`),
  private attachments, the automigration backups and the OAuth keypair. This
  is a named Docker volume, created because the Dockerfile declares
  `VOLUME /var/www/html/uploads` and `DockerfileStrategy::declaredVolumes()`
  names it rather than letting Docker invent an anonymous one. A rebuild runs
  `docker compose down` (`DindDeployMechanics.php:136`) with no `-v`, so it
  is kept.
* **`project_data-var-www-html-public-media`** — public attachments, the same
  way.
* **`~/.panelalpha/partdb/`** — `APP_SECRET` and `INITIAL_ADMIN_PW`, 0600 in a
  0700 directory, read into the container as a second `env_file`. The home is
  `chown root:root` on every rebuild; this subdirectory is the account's and
  survives.

Measured across a real `POST /projects/pdbrec/rebuild`: 1 part, 1 category, 1
storage location, 1 part lot of 250, 3 attachments and 3 users before and
after; the uploaded attachment file byte-identical by md5; `APP_SECRET`
unchanged, so sessions and remember-me cookies were not invalidated; the
administrator and a second user both signed in with passwords they had
changed *before* the rebuild.

**Attachments survive.** Both the row and the file.

## Why not `database: mysql`

The account's own MySQL server would be the better home for this data —
visible in the panel, reachable in phpMyAdmin, inside the account's backups.
It is not available here: `database:` is read in exactly one place,
`PhpStrategy.php:67` and `:73`, and is silently ignored by every other
strategy. Getting it would mean taking the `php` strategy, which means giving
up this image: PHP 8.3 instead of 8.4, no php.ini at all (engine#185) so
`upload_max_filesize` is PHP's 2M default on an attachment manager, no
`<Directory public/media>` block refusing to execute PHP out of the upload
directory, and `composer install --no-plugins` with no way to lift it
(engine#199) so `symfony/runtime` never writes `vendor/autoload_runtime.php`
and `public/index.php` dies on its second line.

SQLite in the `uploads` volume is upstream's own default for this image
(`ENV DATABASE_URL="sqlite:///%kernel.project_dir%/uploads/app.db"`), and it
is a single-writer inventory for a small team.

## Costs

* image `project-app:latest`, 994 MB; build cache 5.2 GB after two builds,
  4.2 GB of it reclaimable
* container 144 MiB resident idle; the account's whole dind 298 MiB
* first deploy 4m25s, rebuild 4m11s, both dominated by the image build
