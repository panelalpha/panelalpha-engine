# How detection works

You do not have to tell the engine what kind of project you have. It looks at the files in your repository and works it out.

When this goes wrong, the usual reason is simple: the file that marks the project is not at the top of the repository. That file is often named `package.json` or `composer.json`. It is rarely a problem with your VPS.

### Check first

Ask your assistant to look at the repository before you deploy:

```text
Inspect this repository and tell me what stack you detect: https://github.com/org/app
```

The engine downloads a copy, tells you what it found, and deletes the copy. Nothing is put online. If the answer is wrong, fix the repository first.

The answer ends with a verdict on what a deploy would serve:

| Verdict | Meaning |
|---|---|
| deployable | The application would start. |
| no start command | It would deploy, but nothing says how to start it, so the deploy warns. |
| placeholder | The deploy would serve the "Project not configured" page. |
| not deployable | The engine found no way to start a website from these files. It says why. |

Not deployable usually means the project is a library, or it has no file that starts a site. Fix that before you deploy.

A repository that has moved to another address is refused: the engine does not follow redirects. Give it the new address.

**.NET** projects are read as the .NET SDK reads them: the SDK version comes from `global.json` (`sdk.version`, or `tools.dotnet`), and the target framework from the projects in the solution and any `Directory.Build.props` above them. Desktop apps and libraries are not claimed, because neither serves a website. A .NET Framework web app is refused, since the engine runs only modern .NET.

If it lists more than one way to run the project, you can choose:

```text
Deploy https://github.com/org/app as php
```

That choice is for this deploy only. The next one without it goes back to what the files say.

### What it looks at

It tries the most specific match first.

1. A kind of project it already knows. A Docker Compose file is near the top, then a Dockerfile, then well-known apps and languages, then a folder of HTML pages.
2. A Docker Compose file in a `docker`, `.docker`, `deploy`, `deployment`, `docker-compose` or `compose` folder, if nothing else matched. It is run from the top of the repository, with its paths adjusted to match.
3. Unknown, if nothing matched. The deploy shows the "Project not configured" page, with HTTP status 404 on every address.

A general build (Railpack) is never picked by itself. Ask for it, for example `Deploy https://github.com/org/app as railpack`. **That choice is not saved.** A rebuild, a pull, a revert, a branch change, a deployed archive and a push webhook each have to ask again, or the deploy ends on the "Project not configured" page. To stop asking, give the repository a Dockerfile, a Compose file or a `panelalpha.yaml`. Inspect still lists Railpack as a candidate when the repository has a language file, and says "the recipe railpack may build it".

Some stacks that Railpack would have built, such as Deno and Elixir, are therefore unknown unless you ask for the general build.

The first match is the one it uses. That matters most if you have a `docker-compose.yml` you only use on your own computer. The engine cannot tell that from a file meant for the live site, so it will try to use it. Rename the file if you do not want that.

A few ready-made apps, such as WordPress, are recognised even when they also ship a Compose file.

### When the first match fails

A repository often matches more than one kind of project: a `composer.json` and a `package.json` side by side are both PHP and Node. If the deploy fails with the first match, the engine tries the next one, in the same order, until one starts. A Node project with lockfiles for more than one package manager tries each of them before the next kind of project: Bun, then npm.

The deploy log names every attempt:

```text
Recipe php failed (composer-unresolvable); trying express
```

What started is remembered, and the next deploy starts with it. If nothing starts, the deploy fails with the error of the first match.

The engine does not try again when the problem is the server, not the project: a full disk, too little memory, a build that ran out of time, a registry limit or a network failure. It does not try another kind of project when you named one (`Deploy … as php`) or the repository has a `panelalpha.yaml`. With a named one, it still tries the other package managers. Railpack and the "Project not configured" page are never tried this way.

Each attempt is a full build, so a repository that matches several kinds of project takes longer to fail. If you know which one is right, name it, or remove the file that makes the other match.

### Extra notes in the repository

You can add a folder named `.panelalpha` with extra notes: extra commands, extra settings or which known app this is. A `panelalpha.yaml` or `panelalpha.md` at the top of the repository works too. Every key it may hold: [panelalpha.yaml](panelalpha-yaml.md).

Those notes cannot tell the engine to look in a subfolder. The files that mark the project still have to sit at the top. A few known apps already have their own layout (phpBB lives in a subfolder, and the engine handles that). For anything else, move the project to the top of the repository.

### Sites made only of HTML pages

A folder of pages such as `home.html` and `about.html`, with a `css/` folder and no `index.html`, is still treated as a website.

Every file in the repository has to be something a browser can open: a page, stylesheet, script, image, font or media file. One `package.json` or `composer.json` anywhere in the repository, and it is no longer treated as a plain HTML site.

A site that does have an `index.html` is handled as an ordinary static site. See [Project types](project-types.md).

### Next

- [Project types](project-types.md): what each kind of project looks like.
- [What your repository needs](what-your-repo-needs.md): the files that have to sit at the top.
- [panelalpha.yaml](panelalpha-yaml.md): what a repository can tell the engine that detection cannot work out.
