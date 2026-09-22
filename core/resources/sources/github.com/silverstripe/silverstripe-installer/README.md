# silverstripe/silverstripe-installer

SilverStripe CMS, from the project the framework's own installer ships as.

`composer.json` declares `"type": "silverstripe-recipe"`: this repository is
the runnable product, not one of the `silverstripe-vendormodule` packages it
pulls in. The default branch is `6`, and every SilverStripe requirement on it
is a `x-dev` constraint under `minimum-stability: dev` — that is how upstream
develops, and it resolves against Packagist like any other tree.

## What was wrong

Before this recipe the deploy finished `deploy-ok` and the site answered
**403** in 45s. Nothing in the engine was broken; one Composer flag was.

`platforms/php.yaml` installs dependencies with `--no-plugins`, because a
Composer plugin is arbitrary PHP out of a customer repository and the host
build runs it on the host's daemon. SilverStripe's whole install *is* a set of
Composer plugins:

| plugin | what it does at install time |
|---|---|
| `composer/installers` | puts a `silverstripe-theme` package in `themes/<name>/`, not `vendor/` |
| `silverstripe/recipe-plugin` | copies a recipe's `extra.project-files` and `extra.public-files` into the project — this is where `public/index.php`, `public/.htaccess` and `app/src/Page.php` come from |
| `silverstripe/vendor-plugin` | exposes each module's `client/dist` into `public/_resources/` |

All three are pinned by the project's own `config.allow-plugins`. With them
disabled the deploy logged

```
The "composer/installers" plugin was not loaded as plugins are disabled.
The "silverstripe/recipe-plugin" plugin was not loaded as plugins are disabled.
The "silverstripe/vendor-plugin" plugin was not loaded as plugins are disabled.
```

and left `public/` holding `assets/`, `_graphql/` and `favicon.ico` — no index
file. `PhpDocroot::detect()` then found no index anywhere in the tree and
returned `''`, so `PhpStrategy::documentRoot()` emitted no `PA_DOCROOT`, and
`/usr/local/bin/panelalpha-serve` fell back to its own `[ -d /app/public ]`
test and pointed Apache at that empty directory. Apache's answer to a document
root with no `DirectoryIndex` match and `-Indexes` is 403.

## What this recipe does

* **`docroot: public`** — declared, so the answer is settled when the compose
  file is written rather than inferred from whatever the build left behind.
* **`silverstripe-composer-install`** (install + upgrade) — `rm -rf vendor &&
  composer install`, inside the account's container, with the plugins on. The
  host build's `composer.lock` makes it resolve nothing. Two other shapes were
  tried and measured: a plain `composer install` over the existing tree does no
  package operation at all, so no plugin event fires; `composer reinstall
  "*/*"` fires the events but reinstalls each package into the path already in
  `vendor/composer/installed.json`, which leaves `silverstripe/startup-theme`
  under `vendor/` and its images unreachable at the `themes/startup-theme/...`
  URL the templates ask for.
* **`database: mysql`** — the account's own MySQL server. SilverStripe has no
  SQLite driver in `recipe-cms`, and the repository ships no compose file and
  no usable `.env`, so without this it reaches its own installer with nothing
  to type in. `files/.panelalpha/env.sh` maps the engine's `DB_*` onto
  `SS_DATABASE_*` and is *sourced* into the entrypoint's own shell — the one
  `exec panelalpha-serve` becomes — so no database password is written into
  `~/project`.
* **`silverstripe-db-build`** (install + upgrade) — `sake db:build`, which is
  SilverStripe 6's `dev/build`. Idempotent, so it is right over existing data.
* **`silverstripe-admin`** (install only) — creates the administrator.

## Administrator

`hooks/prepare.sh` generates a ~30-character password once, into
`~/.panelalpha/silverstripe/admin.env` (0600 inside a 0700 directory). It is
*not* in `~/project`: `ProjectTree::clearContents()` empties that on every
redeploy (engine#173). `overrides/docker-compose.override.yml` hands it to the
container as a second `env_file`, resolved relative to the compose project
directory, so `../.panelalpha/...` reaches the account's home.

The login is `admin@<the site's main domain>`. The prepare hook cannot know
that — nothing in the account names the domain when the hook runs — so
`.panelalpha/pa-admin.php` derives it from `SERVERNAME` inside the container
and prints it into the deploy log.

`SS_DEFAULT_ADMIN_USERNAME` / `SS_DEFAULT_ADMIN_PASSWORD` are deliberately not
used. They are checked on every login attempt for as long as they are set, so
leaving them in the environment leaves a second way in; and
`DefaultAdminService::findOrCreateDefaultAdmin()` writes a `Member` with no
password of its own ("this user won't be able to login until a password is
set"), so unsetting them after the install would lock everybody out. The
recipe writes a real `Member` with a real password instead.

## .env

The prepare hook writes `.env` itself, rather than letting the engine copy
`.env.example`. That file ships `SS_DATABASE_SERVER="localhost"`,
`SS_DATABASE_USERNAME="<user>"` and `SS_ENVIRONMENT_TYPE="dev"`; the generated
compose names `.env` in `env_file:`, and `env_file` is read when the container
is *created*, so those placeholders would be in the environment before the
entrypoint could say otherwise — and `dev` prints the database password into
the browser on any error. The recipe's `.env` holds `SS_ENVIRONMENT_TYPE=live`
and `SS_DATABASE_CLASS=MySQLDatabase` and no credentials at all.

## Known rough edge

`silverstripe/startup-theme`'s footer references
`themes/startup-theme/images/logo--silverstripe-cms.svg`. It is exposed
correctly by this recipe, but the base image runs with `display_errors` on, so
any template resource SilverStripe cannot resolve is printed above the page
rather than only logged. That is an engine-wide property of the PHP image, not
something this recipe can set per project.
