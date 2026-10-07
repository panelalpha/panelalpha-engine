# Magento Open Source

`github.com/magento/magento2` — PHP 8.3/8.4, MySQL or MariaDB, and a search
engine. The shipped `magento` recipe gets the document root and the database
right and stops there, because two of Magento's requirements are things a
platform manifest has no vocabulary for. This directory supplies both.

## What the recipe could not do alone

**A search engine.** Since 2.4.0 the catalogue search is OpenSearch or
Elasticsearch and there is no way to turn it off — `setup:install` will not
complete without one. A manifest can ask for `database: mysql` and nothing
else: there is no `search:` key and no sidecar the platform can attach. So
OpenSearch comes as `overrides/docker-compose.override.yml`, layered over the
generated compose file rather than replacing it.

The service declares its own `mem_limit`. `ServiceHardener` only fills in a
limit the author did not write, and the sidecar catalogue sizes
`elasticsearch` — `opensearch` is its alias — at 384m, which is smaller than
the JVM heap this sets. Two numbers that disagree fail as a cgroup kill
partway through the first indexing run, which reads like a Magento bug and is
not one.

**An install that needs credentials.** `bin/magento setup:install` wants an
admin username, password and email, and there is nowhere for a customer to
type them. The engine generates them (`credentials:` in `panelalpha.yaml`),
keeps them and returns them from `GET /projects/{name}/app-credentials` (MCP
`app_credentials_get`); the compose override hands
`~/.panelalpha/app-credentials.env` to the app container, so the install
command reads them from its environment. The admin path is one of them
(`MAGENTO_ADMIN_URI`, declared as a secret so the engine generates it): left
alone Magento invents one (`Magento Admin URI: /admin_inabui6`) and prints it
once, into a build log nobody keeps. An account deployed before this keeps all
four from `~/.panelalpha/magento/admin.env` (`adopt_from`).

They are never regenerated. The store already holds these credentials in its
database, and a new set would only be a record of credentials that do not work.

## What survives a redeploy

`~/project` is `/app` in the container (a bind mount) and the engine empties it
before every deploy. Everything Magento writes that matters is kept in
`~/.panelalpha/magento/`, mounted at `/pa-data`:

| Kept | How |
|---|---|
| `app/etc/env.php` (crypt key, DB, install date), `app/etc/config.php` | `hooks/prepare.sh` links both to `/pa-data/etc/` on every deploy; `setup:install` writes through the links |
| `pub/media` (catalogue images, uploads) | bind-mounted from `~/.panelalpha/magento/media`, seeded once from the checkout so its `.htaccess` files stay |
| the OpenSearch index | the `opensearch-data` named volume |

Before this, a redeploy lost `env.php`: the upgrade stage found none and exited
0, and the store answered as uninstalled over a database that still held every
table. `generated/`, `var/` and `pub/static` are not kept — `setup:upgrade`,
`setup:di:compile` and on-demand static content rebuild them.

## The install itself

`files/panelalpha/install.sh`, run from the `install` and `upgrade` stages.

- **Every `bin/magento` call goes through `php -d memory_limit=-1`.** Magento's
  own `pub/.user.ini` raises the limit to 756M for *web* requests, which is why
  a storefront works; `bin/magento` is CLI and gets PHP's 128M default, on
  which `setup:install` dies partway through with `Allowed memory size of
  134217728 bytes exhausted`.
- **`setup:di:compile` runs after `setup:install`.** Without it every request is
  `ReflectionException: Class "Magento\Framework\App\Http\Interceptor" does not
  exist` — a store that installed cleanly and answers 500 on its own front page.
- **It waits for the database.** The install stage can start before MySQL is
  accepting connections, and `setup:install` failing there leaves a half-written
  database that the next attempt will not install over.
- **It stops if `app/etc/env.php` exists.** That file is written by
  `setup:install` and nothing else, so it is the store existing. A redeploy must
  not reinstall over live data.

`app` waits on the OpenSearch healthcheck rather than merely on the container
starting: `setup:install` talks to the search engine partway through, and a
refused connection there is the half-written database again.

## App management

`overrides/app.sh` → `files/panelalpha/app.php`, which bootstraps Magento and
asks its own models. Magento's core CLI has `admin:user:create` and nothing
else — no list, no delete, no password reset — and the alternative was
replicating its password hashing against `admin_user` directly. That hashing
has changed twice; a wrong guess writes a hash nobody can log in with. Paying a
couple of seconds of bootstrap keeps hashing, validation and the ACL Magento's
problem.

The helper lives outside `pub/`, so it is not reachable over HTTP.

**No `users:sso`.** Magento's admin session is a server-side session keyed to a
form key and a session id, with no token endpoint to mint one from. None of the
three SSO patterns fits without forging a session, and admin access to a store
is the wrong place to guess. `info` does not advertise it, so the engine will
not call it.

## Known cost

A first deploy is not quick: Composer resolves 904 modules' worth of
dependencies, and `setup:install` walks all of them. `setup:static-content:deploy`
is deliberately *not* run — the store is left in Magento's default mode, which
generates static content on demand, so the first page view is slow instead of
the deploy being several minutes longer. Production mode is an operator's
decision, not a deploy's.
