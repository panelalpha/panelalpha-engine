# shopware/shopware — Shopware 6.7 Community Edition

Upstream: <https://github.com/shopware/shopware> · tracker issue
[#692](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/692)

A Symfony 7 e-commerce platform: a Twig-rendered storefront, a Vue
administration SPA, and a MySQL-backed Data Abstraction Layer. This directory
deploys it with the `php` strategy, the account's own MySQL server, and the
engine's host Node stage for the two frontends.

## What was wrong, in the order the deploy hits it

| # | Symptom | Cause | Fixed by |
|---|---------|-------|----------|
| 1 | `serving-error_page`, strategy `compose` | The root `compose.yaml` is upstream's *workstation*: `ghcr.io/shopware/docker-dev`, a MariaDB with `root`/`root`, an Adminer on 9080, Mailpit, Valkey, OpenSearch. Nothing in it installs Shopware, so Caddy served `public/index.php` against an empty `vendor/`. | `extends: php` in `panelalpha.yaml` (resolved by `PlatformSelector::fromSource()` ahead of the detection walk) and `hooks/prepare.sh` moving `compose.yaml` to `.panelalpha/` so engine defect **#166** cannot mine its services as sidecars |
| 2 | Every request and every console command fatals | No `composer.lock` (it is in `.gitignore`), so the `php` manifest's `--no-plugins` is never lifted and `symfony/runtime` never writes `vendor/autoload_runtime.php` — required on line 11 of `public/index.php`. Engine defect **#168**. | one `composer dump-autoload` in the account's own container, `files/panelalpha/shopware-setup.sh` |
| 3 | `theme:change` throws `ThemeCompileException` | The storefront's built assets are not in the repository. `theme.json` resolves its SCSS `vendor` alias to a directory `copy-to-vendor.js` fills from `node_modules/bootstrap`, and lists `dist/storefront/storefront.js` under `script`, which `ThemeFileResolver::processDirectFile()` throws on. | `files/package.json` (a dependency-free bridge — `HostCompile::runForPhp()` only fires on a **root** build script, and Shopware's npm projects are nested) driving `files/panelalpha/build-assets.sh` |
| 4 | Node stage dies: `/app/var/plugins.json could not be found` | `webpack.config.js:61-64` throws without it, and only `bin/console bundle:dump` writes it — there is no PHP in the Node container. | `build-assets.sh` writes `{}`; webpack reads that file only for third-party plugin entries and a fresh clone has none |
| 5 | `npm warn EBADENGINE … npm: >=11.8.0` | Image chosen from `engines.node`, then `.nvmrc` — and `.nvmrc` here is `lts/*`, which resolves to nothing, so Node 20 / npm 10.8.2 was used. | `engines.node: "24"` in `files/package.json` |
| 6 | HTTP 400 "Domain Mapping Misconfiguration" | Shopware resolves a request to a sales channel by matching scheme+host against `sales_channel_domain.url`. | `sales-channel:create:storefront --url="$APP_URL"`, which `PublicUrlEnvironment::for()` sets to the account's own origin; re-pointed on the upgrade stage when the address changed |
| 7 | Every route but `/` is a 404 | `public/.htaccess` is not in the clone — `.gitignore` un-ignores only `index.php` and `.htaccess.dist`. | the setup script copies `.htaccess.dist` on install *and* on every redeploy (the re-clone loses it) |
| 8 | Probe hits a container still installing | Engine defect **#90**: `docker compose up -d` without `--wait`. | healthcheck + no-op `ready` service in `overrides/docker-compose.override.yml` |

## Security

Two published credentials, both closed deliberately:

* **`system:install --basic-setup` creates `admin` / `shopware`** —
  `SystemInstallCommand.php:130-137` hard-codes it. This recipe never passes
  that flag. `hooks/prepare.sh` generates a password per account into
  `~/project/.panelalpha-admin-password` (0600, outside the document root) and
  the setup script passes it to `user:create`.
* **`/installer` is open whenever `install.lock` is absent** —
  `public/index.php:26-35` redirects every request to `InstallerKernel`, which
  asks for a database and then creates an administrator. `install.lock` is in
  `.gitignore` and `~/project` is re-cloned on every redeploy, so the setup
  script re-writes it on the **upgrade** path too, over a shop that is already
  live.

The document root is `public/`, so `.env`, `.env.local` (the account's MySQL
password, 0600), `.panelalpha-admin-password`, `composer.json`, `.git/` and the
relocated `compose.yaml` are all outside it; the base image's vhost also denies
dotfiles outright.

## The limit: the Administration build needs a ~2.8 GB **host build budget**

`VITE_MODE=production ts-node -T build.ts` is a Rollup pass over the whole
administration. Measured three times at the same commit, each at the heap
`ServiceLimits::nodeHeapMbFor()` derives from the cgroup:

| host build cgroup | heap | result |
|---|---|---|
| 2000 MB | 1400 MB | `FATAL ERROR: Ineffective mark-compacts near heap limit` |
| 2400 MB | 1800 MB | exit 137, cgroup OOM kill |
| 2800 MB | 2200 MB | success in 52 s, RSS peaked ≈2.5 GB |

That cgroup is **not** the account's `--memory-limit`.
`DindHostBuilder::sandboxPrefix()` passes `--memory $this->memoryLimit()`, and
that value comes from `DindEngine::resolveBuildMemory()`: `DEPLOY_BUILD_MEMORY`
if an operator set one, otherwise
`ServiceLimits::hostBuildMemoryMb()` = `max(2048, min(8192, hostRAM/3))`. So it
is a property of the **engine host**:

* 15 GB host → 5202 MB → the administration builds (proven here).
* under ~8 GB → at or near the `MIN_BUILD_MEMORY_MB` floor of 2048 MB → it
  cannot, whatever the account is sized at. Raise `DEPLOY_BUILD_MEMORY`.

`build-assets.sh` reads its own cgroup and **skips** the administration below
2700 MB, saying so in the deploy log, rather than spending 51 s and 1.2 GB of
`node_modules` on a kill that `runContainer()` would turn into a failed deploy —
taking a storefront that does work down with it. Skipped, the shop is still a
shop: storefront, customer account area and checkout are server-rendered Twig,
but `/admin` will not load.

The storefront half is cheap either way: `npm ci` 37 s / 845 MB, the production
build ≈6 s.

## Requirements met by the engine

* PHP — `~8.2 || ~8.3 || ~8.4 || ~8.5`; every extension Shopware requires
  (including `sodium`, `intl`, `gd`, `zip`) is in the shared base image.
* MySQL ≥ 8.0.22 / MariaDB ≥ 10.11 (`DatabaseConnectionFactory::checkVersion()`).
  The account's own server is MariaDB 12.2.2.
* No search engine. Unlike Magento, Shopware only uses OpenSearch when
  `SHOPWARE_ES_ENABLED` says so; the DAL serves the catalogue from MySQL.
* `php.ini` — the base image loads none (128M/2M/8M compiled-in), so
  `files/panelalpha/php/zz-shopware.ini` is reached through `PHP_INI_SCAN_DIR`.
