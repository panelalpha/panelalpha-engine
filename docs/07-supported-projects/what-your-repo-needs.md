# What your repository needs

The engine identifies your application from its files. If the identifying file is not at the top level of the repository, you get an unexpected result and a confusing deploy.

This page is the minimum for each kind of application.

## Check first

```text
Inspect this repository and tell me what stack you detect: https://github.com/org/app
```

Seconds, and creates nothing. Do this before a first deploy of anything unusual.

## What each kind needs

Everything in the middle column must be at the **top level** of the repository, not in a subfolder.

PHP and Laravel run Apache **inside the project's container**. The VPS still serves your sites through nginx-proxy only: [Install](../02-getting-started/install.md).

| Your application | Needs at the top level | How it gets started |
|---|---|---|
| Docker Compose | A working `docker-compose.yml` | Your compose file |
| Dockerfile | A `Dockerfile` or `Containerfile` | The image's own `CMD` or `ENTRYPOINT` |
| Laravel | `composer.json` and `artisan` | PHP with Apache inside the project |
| PHP | `composer.json`, or a recognised application. Plain PHP needs only source files and no Composer | Apache inside the project |
| Rails | `Gemfile` and `config/application.rb` | `rails server` |
| Ruby | `Gemfile`, plus `config.ru` or a Procfile web entry | `rackup` |
| Django | `manage.py`, and `requirements.txt` if you have one | `manage.py runserver` |
| Python | `requirements.txt`, `pyproject.toml`, `Pipfile` or `setup.py`, and no `manage.py` | `python main.py`, or gunicorn/uvicorn if detected |
| Go | `go.mod` | The compiled program |
| Rust | `Cargo.toml` | `cargo build --release` |
| Java | `pom.xml`, or `build.gradle` / `build.gradle.kts` | The packaged jar |
| .NET | A `.csproj` or `.sln` file (it may sit one or two folders down) | The published application |
| Next.js, Nuxt, Nest, Remix, Express, Fastify | The framework in your dependencies, or its config file | The `start` script from `package.json` when there is one. Without one, an Express server in `server.js`, `server.ts`, `server/index.js` or `server/index.ts` ([A server beside a Vite site](#a-server-beside-a-vite-site)) |
| A Node app with no framework | `package.json`, plus a `start` script or a file such as `index.js` or `server.js` | The `start` script, or that file |
| Vite, Angular, CRA, Astro static, Next.js export | The framework's own markers | nginx serving the built files |
| Static site | An `index.html` or `index.htm`, and **none** of the language files above | nginx |
| HTML site | Web pages only, no `index.html`, and none of the language files above | nginx |

## Docker Compose projects: editing the compose file

The engine never runs your `docker-compose.yml` as it is. It runs a copy made fit for hosting, with resource limits and a restart policy on every service that publishes a port or that another service needs, and your own file stays unchanged. A service counts as needed when another one names it in `depends_on`, `links`, `volumes_from` or `network_mode: service:…`, or reaches it by name in its environment (`DB_HOST=db`, `REDIS_URL=redis://cache:6379`). Any other service that sets no `restart:` of its own is not restarted when it exits, so a one-off helper runs once, and a background worker nothing depends on needs `restart: unless-stopped` if it must come back after a crash or a server restart. If you edit your compose file on the account, over SSH or with the file tools, start the project again with the `up` or `pull` action. The engine rebuilds that copy from your edited file before it starts the containers. It does not clone the repository again. The same applies to an account with no repository where you created a `docker-compose.yml` yourself.

**Where a service may mount from.** A service may bind only your project folder (`~/project`, which is where relative paths such as `./data` point) and the account's `~/.panelalpha` folder. **Any other bind is removed from the copy the engine runs**, and the deploy log warns which service and volume it removed. This covers the Docker socket, the account's home folder and every other path on the server. If a service needs more, copy the files into your repository, or keep them in a named Docker volume.

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

A `bun.lock` or `bun.lockb` next to a `package-lock.json` is installed with npm, unless your `package.json` asks for Bun: a `packageManager` of `bun@…`, an `engines.bun`, the `bun-types` or `@types/bun` package, or a script that runs `bun`. Some project generators commit a Bun lockfile you never use. If npm is not what you want, delete `package-lock.json`. If the first one fails, the engine also tries the others whose lockfile is in the project ([When the first match fails](how-detection-works.md#when-the-first-match-fails)).

**Commit your lockfile.** Without one, dependency versions are not pinned, and a build that worked last week can fail this week because something upstream released a new version.

The engine never puts one in your repository. A lockfile its own install generates, because none was committed, is kept out of the project's changes on the server and is never pushed. That holds for a PHP project's `composer.lock` too.

A `start` script in your `package.json` takes precedence over the default the engine would use.

## A server beside a Vite site

Apps made with Lovable and similar tools often pair a Vite site with a small Express server in `server/`, and leave out a `start` script. With no `start` script, the engine looks for that server in `server.js`, `server.ts`, `server/index.js` and `server/index.ts`, in that order. A TypeScript server runs with `tsx` when `package.json` lists it.

If the server serves the built site itself (`express.static` or `sendFile`), it is all that runs. If it only answers API calls, the engine serves the built site from `dist/` and passes everything else to your server: the paths under `/api` and the paths your `vite.config` proxies, any request that is not a page, and WebSocket connections. Your server gets its own `PORT`. If it listens on a port of its own choosing instead, the engine finds that port. If the server stops, the whole app restarts, so the deploy log shows the error.

A Vite site bakes its `VITE_` values in when it is built. When your code falls back to a local address, as in `import.meta.env.VITE_API_URL || "http://localhost:3001"`, the engine sets that variable to the app's own address for the build, keeping the path. A value you set yourself, or that your `.env` gives, is used instead.

A server that needs a signing secret, such as `JWT_SECRET` or `SESSION_SECRET`, gets a generated one when your `.env.example` names it and the repository's own `.env` leaves it out, or when the example only has a placeholder like `change-this-to-a-secure-random-string`. Other keys in `.env.example`, and credentials for other services such as `STRIPE_SECRET_KEY`, are never filled in for you. See [Environment variables](../05-capabilities/projects.md#environment-variables).

## Git submodules

Submodules are fetched with the repository, and again on every pull, branch change and push, at the commit the repository records. A submodule that cannot be fetched fails the first deploy. After that, a submodule the last deployed commit already had keeps whatever copy it had and the deploy log warns, while one the new commit adds fails the deploy. A rebuild clones the repository afresh, so it cannot keep a copy: a submodule the previous deploy had checked out fails the rebuild instead of deploying the app without it. A submodule addressed over SSH (`git@host:...`) is fetched over HTTPS unless the project has a deploy key. If the application needs only some of them, for example because the others are private, name those in `.panelalpha/panelalpha.yaml` at the top of the repository:

```yaml
git:
  submodules:
    - vendor/shared-lib
```

An empty list fetches none. Without this setting, every submodule is fetched. The file's other settings: [panelalpha.yaml](panelalpha-yaml.md). A private submodule needs a git token that can read it: [Connecting with Git](../05-capabilities/connecting-with-git.md#troubleshooting).

## Troubleshooting

**It says `static` or `html`, but this is an application.**
There is no `package.json` or `composer.json` at the top level. Either your application lives in a subfolder, or the file is genuinely missing. Move the application to the top level.

**A complete HTML site came out as `fallback`.**
Something in your repository is not a browser file, so the HTML rule would not claim it. Look for a stray script, a binary or a build file. See [Sites made only of HTML pages](how-detection-works.md#sites-made-only-of-html-pages).

**It says `compose`, but that file is only for local development.**
Docker Compose is checked near the top and almost always wins. Rename or remove the file, or expect the engine to run it. A named application the engine already knows can win instead.

**It says `fallback` on a PHP application.**
There is no `composer.json` at the top level, probably because your PHP application is in a subfolder. Fix the layout before deploying again.

**It says `fallback` and I do not know why.**
Ask your assistant to inspect the repository and explain what it found. Most cases come down to a missing file at the top level, or a language the engine does not support.

**Inspect says this cannot be deployed.**
The engine found no way to start a website from these files. Often it is a library, or the start file is missing. Fix the repository before you deploy.

**Inspect does not read a file that is a link.**
An inspection reads only inside the repository it inspects. A symbolic link to another file in the repository is followed; one that leads out of the repository is not, and that file's contents are not read. Make it a regular file, or link it to a file inside the repository.

**The build fails with "Cannot change ownership to uid …".**
Every file in a build or image must be owned by a user and group id between 0 and 65535; an account cannot hold anything owned above that. This happens when a build extracts an archive made on another system, for example `tar` in a Flutter web build. Extract it with `tar --no-same-owner` (or set `TAR_OPTIONS=--no-same-owner` in that `RUN` step), or deploy the project's published image instead.

**Inspect stops with "… is a symbolic link in the repository".**
A file in your `.panelalpha/files` folder, or one the app's recipe supplies, goes to a path where your repository commits a symbolic link: a folder on the way, or the file itself. The engine writes these files only into real folders and never through a link, so the inspection stops instead. Commit a real folder or file at that path, or put the file in `.panelalpha/files` under the path the link points to.
