# Project types

This is what the engine can run. You point it at a repository. It looks at the files and starts the project the way that kind of project expects.

To check one repository without putting it online, see [How detection works](how-detection-works.md). For the files that have to sit at the top of the repository, see [What your repository needs](what-your-repo-needs.md).

## Before you start

Three things apply to every project.

The version of the language comes from your own files, not from a setting in the engine. You write it in the project (`composer.json`, `package.json`, `go.mod`, and similar).

The file that marks the project has to sit at the **top** of the repository, not in a subfolder. If it is buried, the engine will misread the project. A few ready-made apps already know their own layout and are handled for you.

The engine tells the app which address to use. If the app is written to use only `localhost`, or a fixed port of its own, it will build but nobody will be able to open it.

## Websites

### A home page named index.html

A folder of files with `index.html` (or `index.htm`) at the top is a static site. The engine shows those files as they are.

### Pages without an index.html

A hand-written site of pages such as `home.html` and `about.html`, with no `index.html`, is still a website. Every file has to be something a browser can open: a page, stylesheet, script, image, font, or media file. One `package.json` or `composer.json` anywhere in the repository, and it is no longer treated this way.

## WordPress and ready-made apps

WordPress, Matomo, phpBB, Magento, Passbolt, OpenCart, osTicket, Flarum, SuiteCRM, Chamilo, MantisBT, Easy!Appointments, Adminer, and phpMyAdmin are recognised as PHP apps and started for you.

How to finish the first WordPress setup and look after a site that is already on this engine: [WordPress and known apps](../05-capabilities/wordpress-and-apps.md).

## JavaScript apps

These are projects with a `package.json` at the top of the repository. Commit the lockfile (`package-lock.json`, `yarn.lock`, and similar). The engine uses whichever lockfile is in the project.

### Apps that stay running

Next.js, Nuxt, SvelteKit, Remix, Astro (when it runs on the server), NestJS, TanStack Start, Express, Fastify, and a plain Node app. A plain Node app also needs a `start` script in `package.json`, or a file such as `index.js`, `server.js`, or `app.js`.

They start from the `start` script when there is one.

### Apps that build into files

Vite, Angular, Create React App, Astro when it builds a static site, and Next.js when it exports static files. The engine builds the files, then shows them as a website.

### If package.json is almost empty

No known framework, no `start` script, and no server file: the engine tries a [general build](#if-the-engine-does-not-recognise-it) instead.

## PHP sites

Laravel and other Composer-based PHP need `composer.json` at the top of the repository. The PHP version comes from that file.

Plain PHP needs only its source files. It does not need Composer.

Ready-made PHP apps are covered under [WordPress and ready-made apps](#wordpress-and-ready-made-apps).

## Other languages

### Python

Django needs a `manage.py` file (it may sit in a subfolder). Other Python projects need one of `requirements.txt`, `pyproject.toml`, `Pipfile`, or `setup.py` at the top, and no `manage.py`.

Django starts the way Django usually starts. Other Python projects start from `main.py` or `app.py`, or from a common Python web server if one is already in the project. Failing those, the engine runs the one command the project declares under `[project.scripts]` in `pyproject.toml`, or the one top-level `.py` file that ends in an `if __name__ == "__main__":` block. When there are several of either, name the entry point in a `panelalpha.yaml`.

### Ruby

Rails needs a `Gemfile` and `config/application.rb`. Plain Ruby needs a `Gemfile` plus either a `config.ru` file or a `web:` line in a Procfile.

A `Gemfile` on its own, with none of those, goes to a [general build](#if-the-engine-does-not-recognise-it).

### Go, Rust, Java, and .NET

- **Go:** a `go.mod` at the top. The engine builds the program and runs it.
- **Rust:** a `Cargo.toml` at the top, built for production. A library with nothing to run is refused.
- **Java:** a `pom.xml` file, or `build.gradle` / `build.gradle.kts`. The packed application is run.
- **.NET:** a `.csproj` or `.sln` file, which may sit one or two folders down.

## If you already pack the app in Docker

**Docker Compose.** A `docker-compose.yml` or `compose.yml` file is checked near the top, so it usually wins. A compose file you only use on your own computer is still picked up. Rename or remove it if you do not want that.

**A Dockerfile.** A `Dockerfile` or `Containerfile`. The image starts the way it was written to start. If both files exist, the `Dockerfile` is used.

## If the engine does not recognise it

It still tries a **general build** when the repository has a language file (`package.json`, `composer.json`, `go.mod`, `requirements.txt`, `Gemfile`, and similar) but did not match a named kind above.

**Unknown** means it did not recognise the project, or the project is not a website. Check that before you deploy: [What your repository needs](what-your-repo-needs.md).

## Names you might see in a log

These are the short names the engine uses when you inspect a repository and in deploy logs.

| Name | What it is |
|---|---|
| `compose` | Docker Compose |
| `dockerfile` | A `Dockerfile` or `Containerfile` |
| `laravel` | Laravel |
| `php` | PHP, including WordPress, OpenCart, Magento, Matomo, and PHP without Composer |
| `rails` | Ruby on Rails |
| `ruby` | Ruby |
| `django` | Django |
| `python` | Python |
| `go` | Go |
| `rust` | Rust |
| `java` | Java |
| `dotnet` | .NET |
| `nextjs` | Next.js, including a static export |
| `nuxt` | Nuxt |
| `sveltekit` | SvelteKit, including static |
| `remix` | Remix |
| `astro` | Astro, including when it runs on the server |
| `nestjs` | NestJS |
| `tanstack-start` | TanStack Start |
| `angular` | Angular |
| `cra` | Create React App |
| `vite` | Vite |
| `express` | Express |
| `fastify` | Fastify |
| `node` | A Node app that is not one of the frameworks above |
| `static` | Files including an `index.html`. Pages with no `index.html` use the same serving and are labelled HTML site |
| `railpack` | The general build, used when nothing more specific matched |
| `fallback` | Unknown, or not a website |

## Real applications to try

These deploy real applications from the Supported board, a few from each stack. The tools and the way to follow a deploy are on [Connecting with Git](../05-capabilities/connecting-with-git.md#more-requests-to-try).

| Application | Technology | Repository | Board issue |
|---|---|---|---|
| PrestaShop | PHP | `https://github.com/PrestaShop/PrestaShop` | #334 |
| OpenCart | PHP, known app | `https://github.com/opencart/opencart` | #335 |
| Firefly III | Laravel | `https://github.com/firefly-iii/firefly-iii` | #8 |
| MeTube | Python | `https://github.com/alexta69/metube` | #191 |
| Umami | Node.js | `https://github.com/umami-software/umami` | #26 |
| ntfy | Go | `https://github.com/binwiederhier/ntfy` | #338 |
| Memos | Go | `https://github.com/usememos/memos` | #101 |
| Stump | Rust | `https://github.com/stumpapp/stump` | #730 |
| Scoold | Java | `https://github.com/Erudika/scoold` | #924 |
| Chatwoot | Ruby | `https://github.com/chatwoot/chatwoot` | #310 |

A deploy of any of them is working when all four hold:

1. The inspection before the deploy names a branch and a way to run it.
2. The deploy ends `success` or `partial`, and the deploy log records its timings.
3. The application's health check reads `serving: ok`. A placeholder page that answers is not support.
4. The site's address answers 200, 301 or 302. 303, 307, 308, 401 and 403 count too when the health check says the application itself is answering, because many applications answer with a login wall.

Tools for every request below: `source_inspect`, `project_create`, `task_get`, `deploy_log_get`, `app_health_check`, `project_get`. Mode: modify.

**PHP: PrestaShop**

```text
Check https://github.com/PrestaShop/PrestaShop first, then deploy it as project
tprestashop on this PanelAlpha Engine. When it is up, tell me whether it is
serving the application and which address to open.
```

Check: the shop's installer or front page at the address.

**PHP known app: OpenCart**

```text
Deploy https://github.com/opencart/opencart as project topencart. Tell me if the
engine recognised it as a known app, and what the first page asks for.
```

Check: the inspection reports a known app, and the first page is the installer or the login.

**Laravel: Firefly III**

```text
Deploy https://github.com/firefly-iii/firefly-iii as project tfirefly. Tell me
which PHP version it picked and why, and whether it needs a database from me.
```

Check: the inspection names Laravel, and the PHP version comes from `composer.json`. Extra tools: `project_inspect`. If the application needs a database that the engine does not create, the assistant says so rather than guessing: see [Databases](../05-capabilities/databases.md).

**Python: MeTube**

```text
Deploy https://github.com/alexta69/metube as project tmetube and tell me how it
is started.
```

Check: the assistant names the start command from the inspection, not a guess.

**Node.js: Umami**

```text
Deploy https://github.com/umami-software/umami as project tumami and tell me
what the first page shows.
```

Check: Umami's login page at the address.

**Go: ntfy**

```text
Deploy https://github.com/binwiederhier/ntfy as project tntfy2 and tell me which
port it listens on.
```

Check: `project_inspect` lists the published port, and the assistant names it.

**Go: Memos**

```text
Deploy https://github.com/usememos/memos as project tmemos. Then rebuild it and
confirm it still answers.
```

Extra tools: `project_rebuild`. Check: the second deploy completes and the application answers again.

**Rust: Stump**

```text
Deploy https://github.com/stumpapp/stump as project tstump. A Rust build can be
slow: keep following the deploy and tell me which stage is taking the time.
```

Extra tools: `deploy_log_get` with `offset`. Check: the assistant reports a stage from the log, not a guess.

**Java: Scoold**

```text
Deploy https://github.com/Erudika/scoold as project tscoold and tell me if the
build ran out of memory.
```

Check: a memory failure in the log is reported as a memory failure, with the limit named: [Limits](../05-capabilities/projects.md#limits).

**Ruby: Chatwoot**

```text
Deploy https://github.com/chatwoot/chatwoot as project tchatwoot. Tell me which
services it starts and whether the site answers.
```

Extra tools: `container_list`. Check: every service it starts is listed, and the address answers.

**Clean up**

```text
Delete the projects tprestashop, topencart, tfirefly, tmetube, tumami, tntfy2,
tmemos, tstump, tscoold and tchatwoot.
```

Mode: full. Tools: `project_delete`, `project_list`. Check: each project is gone from the list, and its address no longer answers. Delete only the names this run created.
