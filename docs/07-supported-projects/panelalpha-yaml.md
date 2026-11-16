# panelalpha.yaml

`panelalpha.yaml` tells the engine what it cannot work out from your files: which known app this is, extra commands, environment values, a compose file of its own, a public port. Most repositories need none. Check what the engine detects first: [What your repository needs](what-your-repo-needs.md#check-first).

## Where it goes

Put it at the top of the repository, in one of three forms. The engine reads the first one it finds and ignores the others:

1. A `.panelalpha/` folder with `panelalpha.yaml` inside. Scripts and files can sit beside it as files of their own.
2. A `panelalpha.md` page at the top of the repository: [The markdown form](#the-markdown-form).
3. A `panelalpha.yaml` at the top of the repository.

Your repository's file wins over any recipe the engine ships for the same repository.

Inside `.panelalpha/`:

```text
.panelalpha/
  panelalpha.yaml                        the keys on this page
  hooks/precheck.sh                      same as precheck:
  hooks/prepare.sh                       same as prepare:
  overrides/entrypoint.sh                same as entrypoint:
  overrides/app.sh                       same as app:
  overrides/docker-compose.yml           same as compose:, replacing the project's compose file
  overrides/docker-compose.override.yml  same as compose: with mode: override
  files/<path>                           same as files:, copied to <path> in the project
```

A file in the folder wins over the same thing written inline. A folder cannot ship both compose files.

## How the engine reads it

- **Every key is optional.** A key written with no value says nothing: under `extends`, it leaves the recipe's own value alone.
- **A key the engine does not know fails the deploy.** The deploy log names it and lists the keys that are allowed. So does a value of the wrong shape. Every error names the file it comes from, whether yours or one of the engine's own recipes.
- **A `strategy` the engine does not know is refused** the same way, instead of being ignored.
- **`detect` is not allowed.** The file is found by where it is, not by detection.
- **Editors can check the file as you write it.** The engine ships a JSON schema for it. With the YAML extension of VS Code or any editor using yaml-language-server, put this line at the top:

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/panelalpha/panelalpha-engine/develop/core/resources/sources/_schema.json
```

## A first example

A Laravel shop that needs a longer first start and one production setting:

```yaml
description: Our shop. Seeds its catalogue on first start, which takes a few minutes.
extends: laravel
env:
  APP_ENV: production
health:
  start_period: 600
```

## Which app this is

| Key | What it does |
|---|---|
| `description` | Free text for whoever reads the file next. |
| `extends` | The engine's recipe this application is an instance of, such as `laravel`, `wordpress`, `nextjs` or `matomo`. Every key below then changes that recipe, one top-level key at a time. Without `extends`, these keys describe the whole application, and `id` is required. |
| `id`, `label` | The recipe's name, and the name the deploy log shows. |
| `priority` | Detection order. Only the engine's own recipes need it. |
| `strategy`, `runtime` | How it is built and run. `runtime` is `node`, `nginx`, `command`, `php`, `compose` or `dockerfile`. |
| `port` | The port the application listens on, where its domain sends HTTP. For a compose file it wins over the port the engine reads from the file. The compose file must publish it. |
| `port_scheme` | `https` for an application that serves only TLS on its port. The domain's rules then reach it over HTTPS without checking its certificate. |
| `image`, `runtime_image`, `requires` | The base image, a different image for the final stage, or the toolchains it needs (names only; the version comes from your project's own files). |
| `app_root` | Where the application is when it is not the top of the repository, such as `phpBB/`. |
| `docroot` | PHP only: the web root inside the application, such as `public`, `pub` or `upload`. Without it, `public/` if there is one, else the application root. |
| `database` | `mysql`: the engine creates a database and user and gives the application `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. |
| `build_args` | `ARG` values for the repository's own Dockerfile, such as `BASE: apache`. |
| `system_packages` | PHP only: `ffmpeg` or `mediainfo` added to the PHP image. |
| `frontend_build` | PHP only: `false` skips the frontend build; a command runs instead of `package.json`'s `build` script. |
| `output_directory`, `output_from`, `commands_from`, `extra` | Build details the engine's recipes use. Rarely needed in a repository. |
| `check`, `check_skip` | Extra health checks to run, and shipped ones to skip, each with a reason. |

## Environment values

```yaml
env:
  APP_URL: '${PA_PUBLIC_URL}'
  SANCTUM_STATEFUL_DOMAINS: '${PA_PUBLIC_HOST}'
  APP_DEBUG: '0'
```

`env` adds to the recipe's values and wins on the same name. The project's own environment variables, set in the panel or over the API, win over both: [Environment variables](../05-capabilities/projects.md#environment-variables).

`env` reaches every application the engine builds. For a project that runs its own compose file, the values go into the project's `.env`, under the environment variables you set yourself. When the repository tracks its own `.env`, the engine leaves that file as committed and writes them to `.env.panelalpha` instead, which every service that loads `.env` also loads.

`${PA_PUBLIC_URL}` is the project's address, such as `https://shop.example.com`, and `${PA_PUBLIC_HOST}` is its domain alone. Names starting with `PA_` belong to the engine and cannot be set.

Write a boolean as a string. Compose hands `false` to the container as the text "false", which many applications read as true.

## Commands at each stage

```yaml
commands:
  - id: migrate
    run: php artisan migrate --force
    stage: [install, upgrade]
  - run: php artisan queue:work
    stage: start
    optional: true
```

| Stage | Runs |
|---|---|
| `precheck` | In the project's account, before the repository is cloned. |
| `prepare` | In the project's account, after the clone, before the build. |
| `build` | While the image is built. |
| `install` | In the container, once: on the first deploy. |
| `upgrade` | In the container, on every later deploy, over the existing data. |
| `start` | In the container, on every start, restarts included. |

A list such as `[install, upgrade]` runs the command whenever the code changed, never on a plain restart.

| Field | What it does |
|---|---|
| `run` | The command. Required. |
| `stage` | One stage or a list of them. |
| `id` | The name the deploy log shows. Without it, one is made from `run`. |
| `optional` | `true`: a failure is logged and the deploy or start goes on. |
| `serve` | `true`: this is the application's long-running process. At most one, in `start` only. |
| `before` | `true`: runs ahead of the recipe's own commands for that stage, not after them. |
| `when` | Runs only when the project matches, such as `{file: artisan}` or `{dep: next}`. |
| `timeout` | Seconds before the command is stopped. |
| `workdir` | The directory it runs in. |
| `role` | Build stage only. `dependencies` runs before the source is copied in, so the build cache keeps it until the lockfile changes. |
| `description` | Why the command is there. |

## Scripts

```yaml
prepare: |
  mkdir -p ~/.panelalpha/myapp
  test -f config.php || cp config.sample.php config.php
```

| Key | What it is |
|---|---|
| `precheck` | A script run in the account before the clone. A non-zero exit stops the deploy, and its output is the reason the deploy log gives. It is read from the checkout already there, so it runs from a project's second deploy on. |
| `prepare` | A script run in the account after the clone, before the build. It can write files the build reads, or a compose file when neither the repository nor `compose` has one. |
| `entrypoint` | Replaces the container's generated entrypoint outright. The `install`, `upgrade` and `start` commands and the `serve` command no longer run. |

The account's `~/.panelalpha` folder keeps what has to survive a redeploy. It is private to the account and also holds the engine's own files, so give a service only the subfolder it needs, and create that subfolder in `prepare`.

## Files and a compose file

```yaml
files:
  config/production.php: |
    <?php return ['cache' => 'redis'];
compose:
  mode: override
  content: |
    services:
      app:
        environment:
          WORKERS: "4"
```

- `files` maps a path in the project to its contents. Paths stay inside the project.
- `compose` is a compose file for the project. A plain string, or `content` with `mode: replace` (the default), replaces the repository's compose file. `mode: override` layers over it instead and leaves the repository's file untouched, which is usually the better choice.

## The application's login

```yaml
credentials:
  login_path: /admin
  fields:
    ADMIN_USER: { kind: username }
    ADMIN_EMAIL: { kind: email }
    ADMIN_PASSWORD: { kind: password }
```

The engine generates the values once and keeps them, encrypted, with the project. On every deploy it writes them to `~/.panelalpha/app-credentials.env` and hands them back on request: [The application's login](../05-capabilities/projects.md#the-applications-login).

- A compose service reads them with `env_file: ../.panelalpha/app-credentials.env`, and a script with `. ~/.panelalpha/app-credentials.env`.
- `kind` is `username` (default `admin`), `email` or `password` (24 random letters and digits).
- `value` sets a username or email of your own. It may use `{random}`, `{host}` and `{email}`. A value that ends in a newline is refused.
- `symbol: true` adds a symbol to a password, for an application whose rules demand one.
- A field the project's own environment variables set takes that value instead.

## User management

`app` is a script the engine calls to list, add and remove the application's users, reset a password and log someone in. `overrides/app.sh` is the same thing as a file. It answers in JSON, and its first command, `info`, lists which of the others it supports.

## Cloning

```yaml
git:
  history: full
  submodules: [vendor/shared-lib]
```

- `history: full` fetches the whole history and every tag, for a build that reads its version from them (`git describe --tags`). It costs time on every deploy, so set it only when the build needs it.
- `submodules` lists the only submodules to fetch; `[]` fetches none. Without it, every submodule is fetched: [Git submodules](what-your-repo-needs.md#git-submodules).

## Waiting for the application

```yaml
health:
  start_period: 900
```

The seconds a container that runs, but listens on no port yet, may take to start answering, after the deploy's own wait has run out. The default is 300, the most is 1800, and 0 turns the wait off. Set it for an application that installs itself on its first start.

## Public ports and the server's own name

```yaml
domain: host
public_ports:
  - { listen: 8081, to: 3000, transport: tcp }
  - 25565
```

`public_ports` asks for TCP or UDP ports on the server. A bare number is a TCP port forwarded to the same port in the project. `domain: host` asks for the server's own name instead of a name of the project's own. Neither does anything until the server's administrator allows it for the project: [Ports and the server's name an application asks for](../05-capabilities/domains-and-ssl.md#ports-and-the-servers-name-an-application-asks-for).

## The markdown form

`panelalpha.md` is a page of markdown. Each block of code is named by the file name in backticks on the line above it:

````markdown
# My app

`panelalpha.yaml`
```yaml
extends: laravel
health:
  start_period: 600
```

`panelalpha-after-clone.sh`
```bash
cp .env.example .env
```

`config/app-extra.php`
```php
<?php return [];
```
````

| Block name | Same as |
|---|---|
| `panelalpha.yaml` | the keys on this page |
| `panelalpha-before-clone-validation.sh` | `precheck` |
| `panelalpha-after-clone.sh` | `prepare` |
| `panelalpha-app.sh` | `app` |
| `docker-compose.yml`, `compose.yaml` and the other compose file names | `compose`, replacing the project's |
| any other path | a file copied to that path |

## Troubleshooting

**The deploy fails with "unknown key(s)".** The message names the key and lists the allowed ones. Check its spelling and level: `start_period` belongs under `health:`, `submodules` under `git:`.

**The file seems to be ignored.** Only the first of `.panelalpha/`, `panelalpha.md` and `panelalpha.yaml` is read, so a `.panelalpha/` folder with any of the files above in it hides a `panelalpha.yaml` at the top. The deploy log names the file it used.

**A `precheck` change did nothing.** It runs before the clone, from the checkout already there, so a change takes effect one deploy later.
