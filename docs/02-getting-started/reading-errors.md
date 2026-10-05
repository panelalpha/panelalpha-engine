# What the error means

When a deploy fails, the engine tries to turn the wall of build output into one plain sentence. This page lists those sentences and what to do about each.

Start with your assistant. It reads the same log, matches the same causes, and can usually apply the fix:

```text
That deploy failed. Read the deploy log and tell me what went wrong, then fix
what you can.
```

If PanelAlpha already knows the fix for this failure, the deploy log ends with `Known problem:`, why it happened and how to fix it. Your assistant sees it too. See [What it gives you](what-is-collected.md#what-it-gives-you).

Come back here when you want to look up the sentence yourself. If none of the rows below match, the log usually says `A build step failed (exit code N)`. That means the engine did not recognise the cause, not that there is no cause. The command that failed is in the log just above it. This page is the common wording, not every sentence the engine can produce.

## Wrong version of something

| The message | What it means | What to do |
|---|---|---|
| This project needs PHP x, but it was built with PHP y | Your `composer.json` asks for a PHP version the engine did not pick. | Check what version your project really needs and make `composer.json` say so clearly. |
| This project needs Node … | Your `engines.node` does not match the Node version used. | Same idea - set `engines.node` or add a `.nvmrc`. |
| This project needs Go x, but it was built with Go y | Your `go.mod` asks for a different Go version. | Align `go.mod` with a version the engine offers. |
| This project needs the PHP extension … | Your application needs a PHP extension the engine did not install. | Ask your assistant. If the extension is a common one, this is worth reporting as a bug. |
| This project has to be compiled for Java … | The project asks for a newer Java than the image the engine builds with. | Lower the Java release in the project, or report it if that release is the one you have to use. |

## Dependencies will not install

| The message | What it means | What to do |
|---|---|---|
| This project's Composer dependencies cannot all be installed together | Two packages need incompatible versions of a third. | Resolve it in your repository. It would fail on your own machine too. |
| The project's dependencies conflict with each other | The same thing for npm or yarn. | Fix the versions in `package.json`, then commit the updated lockfile. |
| A required package was missing when the app started | The install did not finish, but the start was attempted anyway. | Read the install section of the log - the real error is further up. |
| Bun lockfile / frozen lockfile | Your `bun.lock` no longer matches `package.json`. | Run bun locally, commit the updated lockfile. |

## Out of resources

| The message | What it means | What to do |
|---|---|---|
| The build ran out of disk space | The project's disk allowance is used up. | Free space, or raise the limit: [Limits](../05-capabilities/projects.md#limits). |
| Deploy refused before it started: the engine host has … free | The server itself is nearly full (below `DEPLOY_HOST_MIN_FREE`, 3G by default). Nothing was built. | Free disk on the server; `pae system:image:prune` removes base images no project has used recently. Then deploy again. |
| The deploy was stopped because the engine host has … free / the project uses … over its disk limit | Disk ran out while the deploy was downloading or building: the server fell below `DEPLOY_HOST_MIN_FREE`, or the project passed its disk limit. The project's unused Docker storage was cleared afterwards. | Free disk on the server, or raise the project's disk limit, then deploy again. |
| The build ran out of memory | The build needs more RAM than the plan allows. Common with large JavaScript builds. | Raise the memory limit. |
| The build ran out of memory in the engine's build container | The build ran on the server, outside the project, and needed more than the server gives one build (`DEPLOY_BUILD_MEMORY`; by default 8 GB, or half the server's RAM when that is less). | Raising the project's memory limit does not help. The server operator can raise `DEPLOY_BUILD_MEMORY`. |
| The Java build ran out of heap / The Node build ran out of heap | The compiler or Node was given less heap than this project needs. That cap is inside the engine, not the project's plan. | Raising the project's memory limit does not fix this. Deploy again after an engine update, or report it: [Tell PanelAlpha the engine got it wrong](what-happens.md#step-5-tell-panelalpha-the-engine-got-it-wrong). |
| Docker Hub temporarily refused further downloads | A public download rate limit, not your fault. | Wait a few minutes and deploy again. To raise the limit for good, give the engine a Docker Hub login: `pae docker-hub:login`. |
| A base image this project asks for could not be downloaded | Your `Dockerfile` names an image that does not exist or is private. | Check the image name and tag in your `Dockerfile`. |
| The image name "*name*" is not valid: Docker image names must be lowercase | The compose file or `Dockerfile` spells an image with capitals, such as `Owner/app:1`. Docker reads `Owner` as the address of a registry and fails to find it. | Use the lowercase name the message gives. |

## The repository

| The message | What it means | What to do |
|---|---|---|
| The repository could not be read | The engine could not access the repository. | Check the URL. If it is private, supply an access token. |
| The repository was not found | Wrong address, or private with no access. | Paste the URL into a browser in a private window - if you cannot see it, neither can the engine. |

## The application refuses to start

| The message | What it means | What to do |
|---|---|---|
| The application refused to start because one of its settings is missing or still has a placeholder value | Your app needs an environment variable that is missing or still says something like `changeme`. | Set the real value and rebuild: [Environment variables](../05-capabilities/projects.md#environment-variables). |
| The application could not sign in to its database | The application's password and the database's password no longer match. | The password is stored inside the database from when it was first created, so changing the setting does not change it. Either set the setting back to the original password, or delete the database's stored data and let it be recreated. |

## Build tooling

| The message | What it means | What to do |
|---|---|---|
| The build needs the git binary | Usually a Git hook installer such as husky. Those cannot run during a hosting build. | Make the `prepare` script in your `package.json` tolerate not having Git. |
| A package.json "prepare" script failed | The same cause. | The same fix. |
| The project has no "build" script in package.json | The engine expected a build step and there is none. | Add a `build` script, or check whether this project needs one at all. |
| No runnable program was found in this Go repository | The engine could not find a program to start. | Make sure your `main` package is where the engine will look. |
| This project embeds files that have to be produced by an earlier build step | Your build expects files another build step was supposed to generate first. | Commit the generated files, or add a Dockerfile that does both stages. |
| A dependency has to be compiled … needs development headers / no C or C++ compiler / no Python or C toolchain | A native library has to be built during install, and the build image does not have the tools for it. | Prefer a release that ships a prebuilt binary. If the repository is fine and the engine mishandled it, report it. |
| The build step "…" printed nothing for … and was stopped | That step went silent, usually on a download or network call, and the engine stopped it. | Deploy again. If it stalls at the same point, that step is the problem. |

## Nothing matched

| The message | What to do |
|---|---|
| A build step failed (exit code N) | The engine did not recognise this one. Read the log around that point - the failing command is right there. Then ask your assistant. |
| Failed to start app: … | The rest of the sentence is one of the causes above. |

## The site is up but the page is wrong

If your deploy **succeeded** and the page is still wrong - a placeholder, a directory listing, someone else's welcome page, a PHP error - that is not a build failure and it will never appear here. The build worked.

The engine has a separate check for exactly that, and it can usually name what is being served instead: [What the engine checks](../05-capabilities/monitoring-and-logs.md#what-the-engine-checks).

## If you think the engine is wrong

Not every failure is your repository. If your project is fine and the engine mishandled it, report it: [Tell PanelAlpha the engine got it wrong](what-happens.md#step-5-tell-panelalpha-the-engine-got-it-wrong).

## From the server

Reading the deploy log: [CLI commands](../06-commands/pae-cli.md#projects-and-deploys).
