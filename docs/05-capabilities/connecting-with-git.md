# Connecting with Git

Most projects on the engine start from a git repository. You give the assistant a link. It creates the project, clones the code, works out the stack, and puts it on HTTPS. You do not need to pick a domain, create a database, or name the framework first.

```text
Deploy my application https://github.com/org/app on this PanelAlpha Engine.
```

What this does: the assistant creates a **project** (one application or website on this VPS), downloads the code, works out the framework, builds it, and starts it on HTTPS.

What you should see: a web address. **Open the address it gives you**, not the one you expected, because the engine may assign a different one.

The file that identifies the application (`package.json`, `composer.json`, `go.mod`, and so on) has to sit at the top level of the repository: [What your repository needs](../07-supported-projects/what-your-repo-needs.md). What the engine does after you ask: [How a deploy works](../02-getting-started/how-a-deploy-works.md).

The repository URL must be `https://`, with no username or password in it.

## A private repository

Tell the assistant the repository is private. Do not put the access token in the chat.

```text
Deploy https://github.com/org/private-app on this PanelAlpha Engine.
The repository is private.
```

The assistant sends you a link on this engine. Open it, paste a git access token that can clone that repository, select **Save secret**, then go back to the chat and say you are done. The engine stores the token encrypted and uses it to clone. Later rebuilds reuse it. The assistant never sees the value. The link lasts one hour.

<img src="../assets/connect-repository.jpg" alt="Connect your repository page: paste a git token and select Save secret" style="max-width: 100%; height: auto; margin-top: 1.5em; margin-bottom: 1.5em;">

If the stored token later stops working, ask the assistant to replace it. You get the same paste page. Do not put the new token in the chat.

## One token for the whole engine

You can paste a Git token once, for every new project, instead of once per repository.

```text
Save my Git token once for this whole engine. New projects should use it
when I do not give them one.
```

The assistant sends the same paste page. The secret does not expire. The link does, after one hour. A project that already has its own token keeps it. There is one shared Git token. To replace it, ask for that stored token to be deleted, then ask for a new link. Pasting again on the old link does not change it.

```text
Stop projects from using the engine-wide tokens.
```

Projects then use only the token they were given. Tokens already stored for the whole engine stay stored. Ask to turn sharing back on and new projects without their own token use them again.

## A zip of files, not a repository

```text
Upload this archive into the project and deploy it: /path/to/app.zip
```

Use this when the code is not in git. After the first deploy, updating that project is a rebuild of the files already on the VPS, unless you upload a new archive.

## After the project is connected

The engine keeps a copy of the repository. Ask the assistant to pull, switch branch, or go back to the last deployed commit. A successful pull rebuilds the site. You do not ask for the rebuild as well. Switching branch, or going back to an earlier commit, rebuilds the same way.

```text
Pull the latest commit on this project.
```

```text
Switch this project to the branch release.
```

```text
Show recent commits on this project.
```

```text
This project's git token no longer works. Send me the page to replace it.
```

A rebuild without a pull replays the last successful plan against the files already on disk: [How a deploy works](../02-getting-started/how-a-deploy-works.md#deploying-again-later).

Instead of asking for a pull each time, you can have your git host redeploy the project itself on every push: [Push to deploy](push-to-deploy.md).

## Troubleshooting

**The clone failed with "repository not found".**
The URL is wrong, or the repository is private and no token was saved. Check the URL is `https://` with no username in it. For a private repository, ask the assistant to send the paste page again.

**The deploy picked the wrong kind of application.**
The identifying file is often not at the top of the repository: [What your repository needs](../07-supported-projects/what-your-repo-needs.md).

**I pasted the git token in the chat.**
Revoke that token at your git host and create a new one. Ask the assistant to send the paste page. The engine never needed the value in chat.

## From the server

Git commands for a project that is already deployed: [CLI commands](../06-commands/pae-cli.md#repository-projects). The paste link and the shared token: [Secrets](../06-commands/pae-cli.md#secrets).
