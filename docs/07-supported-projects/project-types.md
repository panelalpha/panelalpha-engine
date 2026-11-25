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

A hand-written site of pages such as `home.html` and `about.html`, with no `index.html`, is still a website. Every file has to be something a browser can open: a page, stylesheet, script, image, font or media file. One `package.json` or `composer.json` anywhere in the repository, and it is no longer treated this way.

### Sites made with a site generator

Hugo, Jekyll, VitePress, Eleventy, MkDocs, Docusaurus and Gatsby turn your source files into a folder of finished pages. **The engine does not run these generators, so it does not build the site from the repository.** Depending on the repository, you get the "Project not configured" page, a failed deploy, a site that does not answer, or pages that show the generator's own tags, such as `{% for post in posts %}`, instead of your content.

Put such a site online in one of two ways.

**Deploy the built pages.** Build the site on your computer or in your CI, then deploy the output folder as a zip: [How a deploy works](../02-getting-started/how-a-deploy-works.md). The folder has `index.html` at the top, so the engine shows it as a static site.

| Generator | Build command | Output folder |
|---|---|---|
| Hugo | `hugo` | `public/` |
| Jekyll | `bundle exec jekyll build` | `_site/` |
| VitePress | `npx vitepress build docs` | `docs/.vitepress/dist/` |
| Eleventy | `npx @11ty/eleventy` | `_site/` |
| MkDocs | `mkdocs build` | `site/` |
| Docusaurus | `npm run build` | `build/` |
| Gatsby | `npm run build` | `public/` |

For VitePress, `docs` is the folder your pages sit in. Use your own folder name if it is different.

**Add a Dockerfile** that builds the site and serves the result, for example with nginx. The engine then starts the image: [If you already pack the app in Docker](#if-you-already-pack-the-app-in-docker).

## WordPress and ready-made apps

WordPress, Matomo, phpBB, Magento, Passbolt, OpenCart, osTicket, Flarum, SuiteCRM, Chamilo, MantisBT, Easy!Appointments, Roundcube, Adminer and phpMyAdmin are recognised as PHP apps and started for you.

Two more apps have their own recipe and deploy from their repository: Roundcube (`https://github.com/roundcube/roundcubemail`, version 1.7 and the development branch) and Postmill (`https://github.com/postmill-ai/postmill-app`).

Roundcube is a mail client only. Set `ROUNDCUBE_IMAP_HOST` and `ROUNDCUBE_SMTP_HOST` in the project's environment variables to the mail servers it should log in to and send through. Without them it tries `localhost`, where no mail server runs. The engine creates its database and sets up its configuration on the first deploy.

Postmill starts with a Redis service of its own and applies its database migrations on every deploy.

OpenCart deploys from its `4.1.x.x` branch. The default `master` branch is refused: [OpenCart](#real-applications-to-try).

How to finish the first WordPress setup and look after a site that is already on this engine: [WordPress and known apps](../05-capabilities/wordpress-and-apps.md).

## JavaScript apps

These are projects with a `package.json` at the top of the repository. Commit the lockfile (`package-lock.json`, `yarn.lock`, and similar). The engine uses whichever lockfile is in the project.

### Apps that stay running

Next.js, Nuxt, SvelteKit, Remix, React Router (framework mode), Astro (when it runs on the server), NestJS, TanStack Start, Express, Fastify and a plain Node app. A plain Node app also needs a `start` script in `package.json`, or a file such as `index.js`, `server.js`, or `app.js`.

They start from the `start` script when there is one.

### Apps that build into files

Vite, Angular, Create React App, Astro when it builds a static site and Next.js when it exports static files. The engine builds the files, then shows them as a website.

### If package.json is almost empty

No known framework, no `start` script, and no server file: the engine does not recognise it. A [general build](#if-the-engine-does-not-recognise-it) is there if you ask for one.

## PHP sites

Laravel and other Composer-based PHP need `composer.json` at the top of the repository. The PHP version comes from that file.

Plain PHP needs only its source files. It does not need Composer.

Ready-made PHP apps are covered under [WordPress and ready-made apps](#wordpress-and-ready-made-apps).

## Other languages

### Python

Django needs a `manage.py` file (it may sit in a subfolder). Other Python projects need one of `requirements.txt`, `pyproject.toml`, `Pipfile` or `setup.py` at the top, and no `manage.py`.

Django starts the way Django usually starts. Other Python projects start from `main.py` or `app.py`, or from a common Python web server if one is already in the project. Failing those, the engine runs the one command the project declares under `[project.scripts]` in `pyproject.toml`, or the one top-level `.py` file that ends in an `if __name__ == "__main__":` block. When there are several of either, name the entry point in a `panelalpha.yaml`.

### Ruby

Rails needs a `Gemfile` and `config/application.rb`. Plain Ruby needs a `Gemfile` plus either a `config.ru` file or a `web:` line in a Procfile.

A `Gemfile` on its own, with none of those, is not recognised. A [general build](#if-the-engine-does-not-recognise-it) is there if you ask for one.

### Go, Rust, Java and .NET

- **Go:** a `go.mod` at the top. The engine builds the program and runs it.
- **Rust:** a `Cargo.toml` at the top, built for production. A library with nothing to run is refused.
- **Java:** a `pom.xml` file, or `build.gradle` / `build.gradle.kts`. The packed application is run.
- **.NET:** a `.csproj` or `.sln` file, which may sit one or two folders down.

## If you already pack the app in Docker

**Docker Compose.** A `docker-compose.yml` or `compose.yml` file is checked near the top, so it usually wins. A compose file you only use on your own computer is still picked up. Rename or remove it if you do not want that.

**A Dockerfile.** A `Dockerfile` or `Containerfile`. The image starts the way it was written to start. If both files exist, the `Dockerfile` is used. A Dockerfile in `docker/`, `build/` or `scripts/docker/`, or a variant such as `Dockerfile.prod`, is also found. A single `<name>.dockerfile` at the top is used when nothing else matches. A Dockerfile written for local development (one that maps the host's user id) is not used.

## If the engine does not recognise it

**Unknown** means it did not recognise the project, or the project is not a website. The deploy then shows the "Project not configured" page, with HTTP status 404, so an uptime monitor reports the site as down. Check that before you deploy: [What your repository needs](what-your-repo-needs.md).

A **general build** (Railpack) runs only when you ask for it, for example `Deploy https://github.com/org/app as railpack`. Inspect offers it among the candidates when the repository has a language file (`package.json`, `composer.json`, `go.mod`, `requirements.txt`, `Gemfile`, and similar), and says "the recipe `railpack` may build it". It is not picked by itself, because most repositories that reach it are libraries or tools with no website to start.

**The choice is for one deploy.** It is not saved. Every later deploy of the project has to ask for it again: a rebuild, a pull, a revert, a branch change, a deployed archive and a push webhook. Without it, that deploy ends on the "Project not configured" page. To stop asking, give the repository a `Dockerfile`, a Compose file or a [panelalpha.yaml](panelalpha-yaml.md).

## Names you might see in a log

These are the short names the engine uses when you inspect a repository and in deploy logs.

| Name | What it is |
|---|---|
| `compose` | Docker Compose |
| `dockerfile` | A `Dockerfile` or `Containerfile` |
| `laravel` | Laravel |
| `php` | PHP, including WordPress, OpenCart, Magento, Matomo and PHP without Composer |
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
| `remix` | Remix and React Router (framework mode) |
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
| `railpack` | The general build, used only when you ask for it |
| `fallback` | Unknown, or not a website |

## Real applications to try

These deploy real applications from the Supported board, a few from each stack. The tools and the way to follow a deploy are on [Connecting with Git](../05-capabilities/connecting-with-git.md#more-requests-to-try).

| Application | Technology | Repository | Board issue |
|---|---|---|---|
| PrestaShop | PHP | `https://github.com/PrestaShop/PrestaShop` | #334 |
| OpenCart | PHP, known app | `https://github.com/opencart/opencart`, branch `4.1.x.x` | #335 |
| Roundcube | PHP, known app | `https://github.com/roundcube/roundcubemail` | #61 |
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
Deploy https://github.com/opencart/opencart, branch 4.1.x.x, as project
topencart. Tell me if the engine recognised it as a known app, and what the
first page asks for.
```

Check: the inspection reports a known app, and the first page is served by OpenCart: its storefront, its installer or its login.

OpenCart's default branch, `master`, is an unreleased rewrite whose page loads its scripts from `http://localhost/`. The engine recognises it and refuses the deploy, naming the branch to use instead. The 4.1 release line, branch `4.1.x.x`, is the one supported.

**PHP known app: Roundcube**

```text
Deploy https://github.com/roundcube/roundcubemail as project troundcube. Tell me
what mail server settings it needs and what the first page shows.
```

Check: the inspection reports a known app, the assistant names `ROUNDCUBE_IMAP_HOST` and `ROUNDCUBE_SMTP_HOST`, and the first page is Roundcube's login. Delete the project when done.

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

Check: the assistant says how it is started from what the inspection reports, not a guess. For an app built from its own Dockerfile the inspection names no start command: the image's own entrypoint starts it.

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

Check: `project_inspect` lists the published port and the application's own port, and the assistant names both.

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
Delete the projects tprestashop, topencart, troundcube, tfirefly, tmetube, tumami, tntfy2,
tmemos, tstump, tscoold and tchatwoot.
```

Mode: full. Tools: `project_delete`, `project_list`. Check: each project is gone from the list, and its address no longer answers. Delete only the names this run created.
