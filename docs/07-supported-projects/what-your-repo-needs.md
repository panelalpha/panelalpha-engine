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

| Your application | Needs at the top level | How it gets started |
|---|---|---|
| Docker Compose | A working `docker-compose.yml` | Your compose file |
| Dockerfile | A `Dockerfile` or `Containerfile` | The image's own `CMD` or `ENTRYPOINT` |
| Laravel | `composer.json` and `artisan` | The PHP/Apache setup |
| PHP | `composer.json`, or a recognised application. Plain PHP just needs source files and no Composer | Apache |
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

The engine never runs your `docker-compose.yml` as it is. It runs a copy made fit for hosting, with resource limits and a restart policy, and your own file stays unchanged. If you edit your compose file on the account, over SSH or with the file tools, start the project again with the `up` or `pull` action. The engine rebuilds that copy from your edited file before it starts the containers. It does not clone the repository again. The same applies to an account with no repository where you created a `docker-compose.yml` yourself.

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
