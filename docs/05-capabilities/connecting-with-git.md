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

The assistant sends you a link on this engine. Open it, paste a git access token that can clone that repository, select **Save secret**, then go back to the chat and say you are done. A token that cannot read that repository is refused on the page, before it is saved. The engine stores the token encrypted and uses it to clone. Later rebuilds reuse it. The assistant never sees the value. The link lasts one hour. The token is kept until it is deleted, unless you ask for it to expire ("keep it for 30 days"); then the engine deletes it from the vault by itself.

That token belongs to the project it was pasted for. The first project given it keeps it, and no other project can use it.

<img src="../assets/connect-repository.jpg" alt="Connect your repository page: paste a git token and select Save secret" style="max-width: 100%; height: auto; margin-top: 1.5em; margin-bottom: 1.5em;">

If the stored token later stops working, ask the assistant to replace it. You get the same paste page. Do not put the new token in the chat.

## A token shared by several projects

To paste a Git token once and use it on several projects, ask for a global one:

```text
Save a global Git token for the acme GitHub organisation.
```

The assistant sends the same paste page. You can keep several global tokens, for example one per organisation; the purpose you give tells them apart. Like a project token, it is kept until deleted unless it was given an expiry, and the link closes after one hour.

A global token is used only when you name it:

```text
Create a project from https://github.com/acme/shop with the global
acme Git token.
```

The assistant passes the token's reference, such as `vault:7`, in place of the token. A project created without a token clones without one, even when the engine holds global tokens.

A project stores the token it was given. Deleting a vault entry does not take it away from projects that already used it; to change a project's token, give it the new one. Pasting again on the old link does not change a stored secret.

## A deploy key instead of a token

A deploy key gives the engine read access to one repository and nothing else. Ask for one:

```text
Create a deploy key for the project shop and connect git@github.com:acme/shop.git, branch main.
```

The assistant creates the project's key and shows you its public half, a line that starts with `ssh-ed25519`. Add that line to the repository as a read-only deploy key (on GitHub: **Settings > Deploy keys**), then tell the assistant you are done. The engine connects the repository over SSH and deploys it. Later pulls and rebuilds use the same key. The private half is stored encrypted and never leaves the engine.

The engine checks the server's identity on every connection. It knows github.com, gitlab.com and bitbucket.org already. For your own git server, name it when the key is created, for example `git.example.com` or `git.example.com:2222`. The engine reads that server's keys once and trusts only those afterwards. If the server's key changes later, the connection is refused; delete the deploy key and create it again to trust the new one.

## A zip of files, not a repository

```text
Upload this archive into the project and deploy it: /path/to/app.zip
```

Use this when the code is not in git. After the first deploy, updating that project is a rebuild of the files already on the VPS, unless you upload a new archive.

## After the project is connected

The engine keeps a copy of the repository. Ask the assistant to pull, switch branch, or go back to the last deployed commit. A successful pull rebuilds the site. You do not ask for the rebuild as well. Switching branch, or going back to an earlier commit, rebuilds the same way.

The engine checks the request at once: the repository answers, and the branch or commit exists. The pull or switch and the rebuild then run in the background, like any rebuild, and the assistant follows them until they end. Asking again while one is still running is refused and points at the one under way. If the new version fails, or the change is cancelled, while the previous one is still running, the site keeps serving the previous one, and the project stays on the branch and commit it was on.

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
