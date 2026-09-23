# Thelia — github.com/thelia/thelia

Thelia 3.1 is an e-commerce platform: Symfony 7.4, Propel over MySQL, a Flexy
front office and a back office at `/admin`. Tracker issue
[#934](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/934).

Detection reads the repository correctly on its own — composer.json and no
artisan, so the `php` strategy, PHP 8.3, the shared Apache base image with
`~/project` bind-mounted, `composer install` on the host, `public/` as the
document root. The deploy finished successfully without this recipe and every
request answered **HTTP 500**, which is the `serving-error_page` verdict it
exists to fix.

## Log in

```
user:     admin
password: ~/project/.panelalpha-admin-password     (0600, generated per account)
url:      https://<domain>/admin/login
```

The email is `admin@example.com` — a hook is told neither the account's address
nor its domain, and the password is the secret, not the address. Override it
with `THELIA_ADMIN_EMAIL` in the project's env vars before the first deploy.
The same address is seeded as the shop's notification recipient, which an
administrator changes in the back office.

## What the engine could not infer

Almost all of it comes back to one fact: **thelia/thelia commits no
composer.lock**, and `PhpHostBuild::mayRunPlugins()` can only lift
`--no-plugins` for a project that has one. So none of the five plugins this
project allows ran, and this repository needs three of them.

| What was wrong | Where it is fixed |
|---|---|
| `vendor/autoload_runtime.php` never written (symfony/runtime), so `public/index.php` dies on `LogicException: Symfony Runtime is missing` — the 500 | `thelia-setup.sh` runs `composer dump-autoload` |
| …and that plugin-enabled dump writes a *wrong* map: composer/installers puts the templates under `templates/`, where nothing is yet, and drops them and everything under them (130 psr-4 prefixes instead of 157, no liip/imagine-bundle) | a second `dump-autoload --no-plugins` right after it |
| all 16 `thelia-module` packages left at `vendor/thelia/<package>`, where `THELIA_MODULE_DIR` does not look: `0 module(s) registered` | `thelia-place-modules.php` symlinks them into `vendor/thelia/modules/<Name>` |
| no database, and nothing in the checkout to infer one from | `database: mysql` |
| the committed `.env`'s empty `DATABASE_*` shadow the `.env.local` bin/install writes, because `env_file:` makes them real environment variables | `prepare.sh` deletes that block; same hook sets `APP_ENV` and a generated `APP_SECRET` for the same reason |
| `CheckPermission` refuses to install below `post_max_size` 20M; the base image has 8M and no writable conf.d | `PHP_INI_SCAN_DIR` in the compose override + `panelalpha/php/zz-thelia.ini` |
| `APP_ENV=production` — Laravel's word — so the shop ran with Symfony's debug handler | `env: APP_ENV: prod` in `panelalpha.yaml` |
| no first administrator, and no web installer to fall back on | `bin/install --with-admin` with a generated password |

And a second cluster, which is the same `--no-plugins` from another side:
**symfony/flex never ran, so no Flex recipe was ever applied** — and
thelia/thelia `.gitignore`s `/config/`, so what the repository holds under it
is only the handful of files someone added with `git add -f`. Four pieces of
generated configuration were missing, each of them fatal on its own:

| Missing | Symptom |
|---|---|
| `config/packages/lexik_jwt_authentication.yaml` | `You must either configure a "public_key" or a "secret_key"` while compiling the container |
| `framework.secret` (the framework-bundle recipe's `config/packages/framework.yaml`) | `You have requested a non-existent parameter "kernel.secret"` |
| `config/routes/api_platform.yaml` | no REST API at all, and `/` → 500 on `No routes found for "/api/header-highlights/"`, which a theme hook calls from the layout |
| `config/routes/ux_live_component.yaml` | `/` → 500 on `Unable to generate a URL for the named route "ux_live_component"` |

plus three bundles missing from the committed `config/bundles.php`, which Flex
also generates: without `TalesFromADevTwigExtraTailwindBundle` every
front-office page is `Unknown "tailwind_merge" filter`, and without the two
SymfonyCasts bundles `tailwind:build` and `sass:build` do not exist, so
`bin/install` skipped them — silently, because it treats a command that is not
registered as one the active theme does not need — and no stylesheet was ever
compiled. `thelia-register-bundles.php` adds them, and only when the class is
really installed and not already registered.

## Files

| File | What it does |
|---|---|
| `panelalpha.yaml` | `extends: php`, `docroot: public`, `database: mysql`, `APP_ENV=prod`, and the `thelia-setup` command on the install and upgrade stages |
| `hooks/prepare.sh` | `.env` (APP_ENV, APP_SECRET, the empty `DATABASE_*` block), the generated administrator password, the directories Thelia writes into |
| `files/panelalpha/thelia-setup.sh` | the install/upgrade command's body |
| `files/panelalpha/thelia-place-modules.php` | the modules, where Thelia looks for them |
| `files/panelalpha/thelia-register-bundles.php` | the three bundles Flex would have registered |
| `files/panelalpha/php/zz-thelia.ini` | the php.ini Thelia's own permission check demands |
| `files/config/packages/panelalpha.yaml` | `framework.secret`, and the trusted proxies |
| `files/config/packages/lexik_jwt_authentication.yaml` | Flex's file for the JWT bundle |
| `files/config/routes/api_platform.yaml` | Flex's file: the REST API, which the front office reads its own data through |
| `files/config/routes/ux_live_component.yaml` | Flex's file: the route Flexy's header component generates |
| `overrides/docker-compose.override.yml` | `PHP_INI_SCAN_DIR`, `mem_limit: 1g`, the app healthcheck and the `ready` gate |

## A redeploy keeps the shop

`GitRepository::cloneConfiguredRepository()` clears `~/project` before it
clones, so the checkout, `.env.local` and `.panelalpha-admin-password` are all
new on every redeploy — but the database is the account's own MySQL and
survives. `thelia-setup.sh` decides which it is by asking the database for the
`module` table, and on the upgrade path it rewrites `.env.local` from the
credentials the container was handed and clears the cache instead of
installing. **The generated password file does not survive a redeploy** while
the administrator it describes does; keep it somewhere before redeploying, or
reset the password from the back office afterwards.

## Verified on mariusz, 2026-09-20

- Batch run from a clean account: `deploy-ok`, `serving: ok`, HTTP 200, 12 of
  12 health checks pass, 286s.
- Logged in over the public domain as `admin` with the generated password:
  `POST /admin/checklogin` → 302 → `/admin`, `Dashboard - Thelia`.
- The front office renders the Flexy theme at `/` (header, language selector,
  cart, footer); `/admin/login` carries the password field the healthcheck
  greps for.
- `.env`, `.env.local` and `.panelalpha-admin-password` answer 403; everything
  outside `public/` — `composer.json`, `panelalpha/`, `config/jwt/private.pem`
  — answers 404, because the document root is `public/`.
- Absolute URLs on the home page are `https://<domain>` with no `:8000`, so the
  trusted-proxy configuration is doing its job.
- The upgrade branch replayed in the deployed container: no reinstall, cache
  cleared, site still 200.

## Not done

- **No `overrides/app.sh`**: no `info`, `install`, `users:*` or SSO. Thelia has
  an `admin:create` console command and a JWT API with an admin login endpoint,
  so all three SSO patterns are buildable; this recipe only gets the shop
  deployed, installed and safe.
- **No demo catalogue.** `bin/install --with-demo` exists and fetches sample
  product images at deploy time. A new shop starts empty, which is Thelia's own
  empty state.
- **Upgrades between Thelia versions.** `setup/update.php` walks the numbered
  update scripts and nothing here calls it: the upgrade stage re-points the
  configuration and clears the cache, and a version jump is left to an
  operator.
- `GET /install/bdd.php` answers 200. It is the empty placeholder this branch
  ships at `public/install/bdd.php` — zero bytes, no wizard behind it — and it
  is what `THELIA_SETUP_WIZARD_DIRECTORY` points at, so it is left alone.
- Four `WARN` lines from the Page module's own update SQL (`Can't DROP
  'position'`) during the install. They are upstream's update scripts replayed
  over a fresh schema; `DatabaseSetup` lists 1091 among the codes it ignores.

## One for the engine

`PhpStrategy::isSymfony()` reads the **root** composer.json's `require` for
`symfony/framework-bundle` or `symfony/symfony`. A Symfony application that
pulls the framework in through its own first-party package — Thelia through
`thelia/core`, and it will not be the only one — is therefore handed Laravel's
`APP_ENV=production`, and with it `APP_DEBUG=true` on a public site.
`composer.lock`, or the resolved tree, answers the same question correctly.
