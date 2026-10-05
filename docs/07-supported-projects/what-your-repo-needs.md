# What your repository needs

The engine identifies your application from its files. If the identifying file is not at the top level of the repository, you get an unexpected result and a confusing deploy.

This page is the minimum for each kind of application.

## Check first

```text
Inspect this repository and tell me what stack you detect: https://github.com/org/app
```

Seconds, and creates nothing. Do this before a first deploy of anything unusual.

## What each kind needs

Everything in the middle column must be at the **top level** of the repository - not in a subfolder.

PHP and Laravel run Apache **inside the project's container**. The VPS still serves your sites through nginx-proxy only: [Install](../02-getting-started/install.md).

| Your application | Needs at the top level | How it gets started |
|---|---|---|
| Docker Compose | A working `docker-compose.yml` | Your compose file |
| Dockerfile | A `Dockerfile` or `Containerfile` | The image's own `CMD` or `ENTRYPOINT` |
| Laravel | `composer.json` and `artisan` | PHP with Apache inside the project |
| PHP | `composer.json`, or a recognised application. Plain PHP just needs source files and no Composer | Apache inside the project |
| Rails | `Gemfile` and `config/application.rb` | `rails server` |
| Ruby | `Gemfile`, plus `config.ru` or a Procfile web entry | `rackup` |
| Django | `manage.py`, and `requirements.txt` if you have one | `manage.py runserver` |
| Python | `requirements.txt`, `pyproject.toml`, `Pipfile` or `setup.py`, and no `manage.py` | `python main.py`, or gunicorn/uvicorn if detected |
| Go | `go.mod` | The compiled program |
| Rust | `Cargo.toml` | `cargo build --release` |
| Java | `pom.xml`, or `build.gradle` / `build.gradle.kts` | The packaged jar |
| .NET | A `.csproj` or `.sln` file (it may sit one or two folders down) | The published application |
| Next.js, Nuxt, Nest, Remix, Express, Fastify | The framework in your dependencies, or its config file | The `start` script from `package.json` when there is one |
| A Node app with no framework | `package.json`, plus a `start` script or a file such as `index.js` or `server.js` | The `start` script, or that file |
| Vite, Angular, CRA, Astro static, Next.js export | The framework's own markers | nginx serving the built files |
| Static site | An `index.html` or `index.htm`, and **none** of the language files above | nginx |
| HTML site | Web pages only, no `index.html`, and none of the language files above | nginx |

## Docker Compose projects: editing the compose file

The engine never runs your `docker-compose.yml` as it is. It runs a copy made fit for hosting, with resource limits and a restart policy on every service that publishes a port or that another service needs, and your own file stays unchanged. A service counts as needed when another one names it in `depends_on`, `links`, `volumes_from` or `network_mode: service:…`, or reaches it by name in its environment (`DB_HOST=db`, `REDIS_URL=redis://cache:6379`). Any other service that sets no `restart:` of its own is not restarted when it exits, so a one-off helper runs once, and a background worker nothing depends on needs `restart: unless-stopped` if it must come back after a crash or a server restart. If you edit your compose file on the account, over SSH or with the file tools, start the project again with the `up` or `pull` action. The engine rebuilds that copy from your edited file before it starts the containers. It does not clone the repository again. The same applies to an account with no repository where you created a `docker-compose.yml` yourself.

## Dockerfile projects: what goes into the build

The engine leaves the `.git` folder and its own compose files out of the build, so a redeploy of an unchanged commit can reuse the build cache. It writes `Dockerfile.dockerignore` next to your Dockerfile for this. Docker reads that file instead of `.dockerignore`, so the engine copies your `.dockerignore` rules into it. Your own `.dockerignore` is never changed.

The engine also leaves out `.env.panelalpha`, which holds the project's environment variables when your repository tracks its own `.env`. An empty `.env`, which the engine creates when the project has no environment of its own, is left out too, unless your Dockerfile copies `.env` by name. A `.env` with values stays in the build, so a build step such as Vite or Next.js can read it.

The engine keeps `.git` in the build when your build reads git history: the Dockerfile copies `.git` or runs a command such as `git describe`, or the project takes its version from git (for example `setuptools-scm`). Each redeploy then rebuilds from the first `COPY . .`.

To decide this yourself, put `!.git` in your `.dockerignore` to keep `.git`. You can also commit your own `Dockerfile.dockerignore`. The engine then uses your file as it is.

## Workers and release commands: a Procfile

A `Procfile` at the top level can name more processes than the web server. Each line other than `web:` runs beside your application, from the same image, with the same environment and files, and without a public port:

```text
web: bundle exec puma -C config/puma.rb
worker: bundle exec sidekiq
release: bundle exec rails db:migrate
```

`release:` is different: it runs once on every deploy, before your application starts. If it fails, the deploy fails and the application is not started. Every other line, such as `worker:` or `clock:`, keeps running and is restarted if it stops. This applies to every kind of project above except Docker Compose, where your compose file lists the services, and sites served by nginx.

## Node projects: the lockfile decides the tool

Whichever lockfile is present is the package manager that gets used:

| Lockfile | Tool |
|---|---|
| `bun.lock` or `bun.lockb` | Bun |
| `pnpm-lock.yaml` | pnpm |
| `yarn.lock` | yarn |
| `package-lock.json` | npm |

**Commit your lockfile.** Without one, dependency versions are not pinned, and a build that worked last week can fail this week because something upstream released a new version.

A `start` script in your `package.json` takes precedence over the default the engine would use.

## Troubleshooting

**It says `static` or `html`, but this is an application.**
There is no `package.json` or `composer.json` at the top level. Either your application lives in a subfolder, or the file is genuinely missing. Move the application to the top level.

**A complete HTML site came out as `fallback`.**
Something in your repository is not a browser file, so the HTML rule would not claim it. Look for a stray script, a binary, or a build file. See [Sites made only of HTML pages](how-detection-works.md#sites-made-only-of-html-pages).

**It says `compose`, but that file is only for local development.**
Docker Compose is checked near the top and almost always wins. Rename or remove the file, or expect the engine to run it. A named application the engine already knows can win instead.

**It says `railpack` on a PHP application.**
There is no `composer.json` at the top level, probably because your PHP application is in a subfolder. Fix the layout before deploying again.

**It says `fallback` and I do not know why.**
Ask your assistant to inspect the repository and explain what it found. Most cases come down to a missing file at the top level, or a language the engine does not support.

**Inspect says this cannot be deployed.**
The engine found no way to start a website from these files. Often it is a library, or the start file is missing. Fix the repository before you deploy.
