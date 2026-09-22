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

Django starts the way Django usually starts. Other Python projects start from `main.py` or `app.py`, or from a common Python web server if one is already in the project.

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
