# How detection works

You do not have to tell the engine what kind of project you have. It looks at the files in your repository and works it out.

When this goes wrong, the usual reason is simple: the file that marks the project is not at the top of the repository. That file is often named `package.json` or `composer.json`. It is rarely a problem with your VPS.

### Check first

Ask your assistant to look at the repository before you deploy:

```text
Inspect this repository and tell me what stack you detect: https://github.com/org/app
```

The engine downloads a copy, tells you what it found, and deletes the copy. Nothing is put online. If the answer is wrong, fix the repository first.

Sometimes it will say these files cannot be run as a website. That usually means the project is a library, or it has no file that starts a site. Fix that before you deploy.

If it lists more than one way to run the project, you can choose:

```text
Deploy https://github.com/org/app as php
```

That choice is for this deploy only. The next one without it goes back to what the files say.

### What it looks at

It tries the most specific match first.

1. A kind of project it already knows. A Docker Compose file is near the top, then a Dockerfile, then well-known apps and languages, then a folder of HTML pages.
2. A general build, if none of those matched but the repository still has a language file such as `package.json` or `composer.json`.
3. A Docker Compose file in a `docker`, `.docker`, `deploy`, `deployment`, `docker-compose` or `compose` folder, if nothing else matched. It is run from the top of the repository, with its paths adjusted to match.
4. Unknown, if nothing matched.

The first match is the one it uses. That matters most if you have a `docker-compose.yml` you only use on your own computer. The engine cannot tell that from a file meant for the live site, so it will try to use it. Rename the file if you do not want that.

A few ready-made apps, such as WordPress, are recognised even when they also ship a Compose file.

### Extra notes in the repository

You can add a folder named `.panelalpha` with extra notes: extra commands, extra settings, or which known app this is. Older projects may use a file named `panelalpha.yaml` or `panelalpha.md` instead.

Those notes cannot tell the engine to look in a subfolder. The files that mark the project still have to sit at the top. A few known apps already have their own layout (phpBB lives in a subfolder, and the engine handles that). For anything else, move the project to the top of the repository.

### Sites made only of HTML pages

A folder of pages such as `home.html` and `about.html`, with a `css/` folder and no `index.html`, is still treated as a website.

Every file in the repository has to be something a browser can open: a page, stylesheet, script, image, font, or media file. One `package.json` or `composer.json` anywhere in the repository, and it is no longer treated as a plain HTML site.

A site that does have an `index.html` is handled as an ordinary static site. See [Project types](project-types.md).

### Next

- [Project types](project-types.md): what each kind of project looks like.
- [What your repository needs](what-your-repo-needs.md): the files that have to sit at the top.
