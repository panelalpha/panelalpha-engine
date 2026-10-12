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

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Deploys of real applications, one per stack, are on [Project types](../07-supported-projects/project-types.md#real-applications-to-try).

**Is the name free?**

```text
Is the project name tshop free on this engine?
```

Mode: read. Tools: `project_verify_name`. Check: the answer says free or taken, and a name that breaks the rules (for example `Shop-1`) is refused with the reason.

**Look before deploying**

```text
Check https://github.com/FreshRSS/FreshRSS without deploying it. What stack,
which branch, which port?
```

Mode: read. Tools: `source_inspect`. Check: the answer names a stack, a branch and the way it was read (`tree` or `clone`). No project exists afterwards (`project_list` does not show a new one). No `.env` values appear, only names.

**Choose another way to run it**

```text
Inspect https://github.com/org/app and list every way the engine could run it.
Then deploy it as php.
```

Mode: modify. Tools: `source_inspect`, `project_create`. Check: the ways to run it come from the inspection, and the deploy log names the one that was asked for.

**Deploy from a public repository, in the background**

```text
Deploy https://github.com/usememos/memos as project tmemos on this PanelAlpha
Engine and tell me the address when it is up.
```

Mode: modify. Tools: `project_create`, `task_get` (repeated), `project_get`. Check: the first answer carries a task id at once, the assistant polls until `completed`, and `project_get` shows the domain. The assistant does not start a second deploy while the first runs.

**Deploy and wait in one call**

```text
Create project tntfy from https://github.com/binwiederhier/ntfy and wait for
the deploy to finish before you answer.
```

Mode: modify. Tools: `project_create_sync`. Check: one call returns the project with its final deploy status, without the assistant having to poll.

**Deploy with settings**

```text
Deploy https://github.com/org/app as project tsettings, branch develop, with
the environment variable APP_MODE set to staging and a 1024 MB memory limit.
```

Mode: modify. Tools: `project_create` (`git_branch`, `env_vars`), `project_update` (`memory_limit`), `project_rebuild`. Check: the deployed branch is `develop`; `project_inspect` lists `APP_MODE` by name only, never by value; the memory limit reads 1024.

**Private repository without a token in the chat**

```text
Deploy https://github.com/org/private-app as project tprivate. The repository
is private. Do not ask me to paste the token here.
```

Mode: modify. Tools: `vault_secret_create`, `vault_secret_status`, `project_create`. Check: the assistant returns a page address, not a request for the token. After the paste, `vault_secret_status` reads `filled`. The token appears nowhere in the conversation.

**Shared token, inventory, replace, remove**

```text
Save a global Git token for the acme organisation. Then list the secrets stored
on this engine, show which global ones exist, and delete the acme one.
```

Mode: full. Tools: `vault_secret_create` (`scope` global), `vault_secret_list`, `vault_config_get`, `vault_secret_delete`. Check: the list never contains a secret value, only reference, type, scope, purpose and status. After the delete, using the reference fails. A stored secret is replaced by delete and create, never by overwrite.

**Follow a deploy**

```text
What is the deploy of tmemos doing right now? Show me the log so far, and the
task's own log lines.
```

Mode: read. Tools: `deploy_log_get` (with `offset` while `more` is true), `task_get`, `task_log_list`. Check: the log is read in pages, the second call continues from `next_offset`, and a failed deploy shows a `problem` with the fix when the failure is a known one.

**Cancel a deploy**

```text
Start a rebuild of tmemos, then cancel it while it is running.
```

Mode: full. Tools: `project_rebuild`, `deploy_cancel` or `task_cancel`. Check: the task ends `cancelled`, nothing is left running, and the project can be rebuilt again afterwards.

**Is it really the application?**

```text
Is tmemos serving the application itself, or a placeholder page?
```

Mode: read. Tools: `app_health_check`. Check: the answer says the application itself is answering. A page that answers but is not the application is not a pass.

**Deploy a zip**

```text
Upload /path/to/app.zip into project tzip and deploy it.
```

Mode: modify. Tools: `file_upload`, `project_deploy_archive`, `task_get`. Check: a single top-level folder in the zip is unwrapped, the deploy completes, and a second request while it runs is refused with a pointer to the running task.

**Do not deploy twice**

```text
Rebuild tmemos. While that runs, rebuild it again.
```

Mode: modify. Tools: `project_rebuild` twice. Check: the second answer is a conflict that names the running task. No second deploy starts.

**Project inspection against the last deploy**

```text
Inspect tmemos. Have its files changed since the last deploy?
```

Mode: read. Tools: `project_inspect`, `file_write`, `project_inspect`. Check: after a file is written by hand, the second inspection reports the difference.

**Pull, branch, history**

```text
On tmemos: show git status, list the branches and the last five commits.
Then switch to the branch release and pull the latest commit.
```

Mode: modify. Tools: `git_status`, `git_branches`, `git_commits`, `git_change_branch`, `git_pull`, `task_get`. Check: `git_status` is called first and says the project is deployed by the engine. Both the branch change and the pull return a task and rebuild the app. A dirty tree or a missing branch is refused.

**Go back**

```text
Roll tmemos back to the previous commit.
```

Mode: modify. Tools: `git_commits`, `git_revert` with `ref`, `task_get`. Check: the assistant warns that local changes are discarded and asks first. The deployed commit afterwards is the one asked for.

**Push local edits, force a pull**

```text
On project tsitegit, commit my edits and push them. Then pull, and if history
has diverged, tell me before you force anything.
```

Mode: modify. Tools: `git_push`, `git_pull` with `strategy` ff, `push_first` or `force`. Check: on a project the engine deploys, the push is refused with the reason. On a project whose files are kept in git, it commits and pushes. `force` is never used without confirmation.

**Connect and disconnect git on files**

```text
Connect the project tfiles to https://github.com/org/site.git, branch main.
Later, disconnect it again without deleting the files.
```

Mode: full. Tools: `git_connect`, `git_status` (poll while `connecting` is true), `git_disconnect`. Check: connect is not called a second time while `connecting` is true. After the disconnect the files are still there and a deploy hook, if it had one, is gone.

**Replace a token**

```text
The git token of tmemos no longer works. Send me the page to replace it.
```

Mode: modify. Tools: `vault_secret_create`, `git_update_credentials`. Check: the token is not typed in chat, and `git_pull` works afterwards.

**Deploy key for an SSH remote**

```text
Create a deploy key for tprivate and connect git@github.com:acme/shop.git,
branch main. Then remove the key.
```

Mode: full. Tools: `git_deploy_key_create`, `git_connect`, `git_deploy_key_delete`. Check: only the public half is shown, and it starts with `ssh-ed25519`. Asking again returns the same key. After the delete, SSH remotes stop authenticating.

**A deploy that fails**

```text
Deploy https://github.com/org/repo-without-a-start-script as project tbroken.
The deploy fails: read the log and tell me what to change.
```

Mode: modify. Tools: `project_create`, `task_get`, `deploy_log_get`. Check: the task ends `failed`, the answer quotes the engine's own explanation from `problem` or `details.problems`, and the assistant does not retry in a loop.

## From the server

Git commands for a project that is already deployed: [CLI commands](../06-commands/pae-cli.md#repository-projects). The paste link and the shared token: [Secrets](../06-commands/pae-cli.md#secrets).
