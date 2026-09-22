# CLI commands

`pae` runs engine operations from your VPS. Use it when you have no AI assistant connected, or when you want to check something directly over SSH.

**Most of what is here can also be done by asking your assistant**, usually with less typing. A new install exposes every tool. If the assistant refuses, you have limited what it may do: [what it is allowed to do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do). If you are reading this because you are stuck, try asking first: [What to ask](../04-connecting-your-ai/your-assistant.md#what-to-ask).

You do not need a token. `pae` runs as the engine itself.

This page groups the commands by what you are doing. The ones you will use day to day are in the sections above [Advanced and server maintenance](#advanced-and-server-maintenance); that section names the rest. Every command explains itself:

```bash
pae <command> --help
```

Throughout this page, `{project}` means the project's name - the value shown as `username` in the engine's output.

## Configuring the engine

| Command | What it does |
|---|---|
| `pae configure` | Opens the menu: pick what to configure, do it, come back for the next thing. |
| `pae configure mcp-tokens` | Connect an assistant, or change what assistants may use. |
| `pae configure api-tokens` | Mint a token for your own software, and limit it to part of the API. |
| `pae configure mcp --dry-run` | Shows what it would write, and writes nothing. |

The first menu asks which part of the engine you want to change; today that is the assistant's commands, and the list grows as more of the engine moves in here. Choosing it opens its own menu:

```text
 Groups      37 of 37 groups on
 Commands    182 of 182 commands on
 Ceiling     full — they may do anything, including delete
 Review and save
 Back
```

**Groups** and **Commands** are checkboxes. Groups ticks whole groups on and off at once; Commands asks which group you want, then lists that group's commands with a box each and what each one does to the server:

```text
 ◼ app_info                     read
 ◼ app_install                  write
 ◻ app_user_delete              write
```

Tick what the assistant may use and untick what it may not. You never have to think about which settings file line carries which decision — the wizard works that out when it saves.

`pae configure tokens` asks the same thing one token at a time: pick an assistant's token, tick what that one may use. It can only narrow — an assistant cannot be given a command the engine is not offering — and ticking everything means "no limit", so that token keeps following the engine. `pae mcp:token:list` shows the result in its **Commands** column, as `all` or `40 of 182`.

This limits what an assistant may call. It does not limit the token itself: anyone holding it can still reach the engine's REST API in full.

**Ceiling** is the one thing that is not a tick, and it is worth setting first. It is a ceiling on what a ticked command may *do*: at `readonly` the assistant can look and change nothing, whatever you have ticked. Nothing below it can raise it.

Nothing is written until you pick **Review and save** and confirm it, and the settings it replaces are printed so you can put them back. When you save, you land back on the first menu.

The wizard takes over the terminal while it runs — each step is drawn over the last, so you always see the current one and nothing else. What it wrote is printed once more as it exits, and that is what stays on your screen afterwards.

The settings themselves are ordinary lines in `.env-core` and you can still edit them by hand: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

## Projects and deploys

| Command | What it does |
|---|---|
| `pae project:rebuild --project={project}` | Rebuilds from the last shipped plan. This is how you update a site deployed from a repository. |
| `pae project:deploy:log {project}` | Prints the latest deploy log. |
| `pae project:deploy:check {project}` | Opens the site and reports what it is actually serving. |
| `pae project:deploy:timings {project}` | Shows where the last deploy spent its time, stage by stage. |
| `pae project:health:report` | Checks every deployed application and reports the ones that have stopped serving themselves. |
| `pae project:staging {project}` | Creates a linked staging copy. |
| `pae project:push {project} {target}` | Pushes state between a staging pair. |
| `pae project:limit:get --project={project}` | Shows disk, memory and CPU limits. |
| `pae project:limit:set --project={project} --memory-limit=512` | Changes a limit. Memory is in megabytes. |
| `pae project:ssh {project} '{command}'` | Runs one command inside the project. |
| `pae project:delete {project}` | Deletes the project and everything in it. Asks you to confirm. |

Create a project from a public repository:

```bash
pae api:call POST /projects '{"git_repo":"https://github.com/org/app"}'
```

What this does: creates the hosting account and queues the deploy. The call returns at once. The deploy runs in the background.

What you should see: JSON for a **task**. Note `id`, `username` (the project name), and `details.domain` (the address to open). `status` starts as `queued`.

Poll until that task is finished:

```bash
pae api:call GET /tasks/{id}
```

What you should see: `status` of `completed`, `failed`, or `cancelled`. On `completed`, `details.deployment_status` is `success`, or `partial` with warnings. A failed create is rolled back, so the name is free to use again.

To wait in the same request instead of polling, use the same body on `POST /users`. New scripts should use `POST /projects` and poll.

For a private repository, pass a git access token. The URL must be `https://`, with no username or password in it:

```bash
pae api:call POST /projects '{"git_repo":"https://github.com/org/private-app","git_token":"<token>"}'
```

The usual way to deploy is to ask your assistant: [Connecting with Git](../05-capabilities/connecting-with-git.md).

More project commands:

| Command | What it does |
|---|---|
| `pae project:deploy:list` | Lists every project that has deploy logs. Pass a name for one project. |
| `pae project:attach {project}` | Opens a shell inside the project's container. `--u=root` to attach as another user. |

## Environment variables and settings

These are the values your application reads when it starts. Change one, then rebuild, or the running application will not see it.

| Command | What it does |
|---|---|
| `pae project:settings:list --project={project}` | Lists the project's stored settings. |
| `pae project:settings:get {name} --project={project}` | Prints one setting. |
| `pae project:settings:set {name} {value} --project={project}` | Sets one setting. |
| `pae project:settings:unset {name} --project={project}` | Removes one setting. |

Context: [Environment variables](../05-capabilities/projects.md#environment-variables).

## Repository projects

For projects deployed from git, these act on the checkout the engine owns. Updating the running site is still `pae project:rebuild`.

| Command | What it does |
|---|---|
| `pae git:status {project}` | Shows the checkout's branch and whether it has local changes. |
| `pae git:pull {project}` | Pulls the latest commit, then rebuilds the application from it. |
| `pae git:branches {project}` | Lists the branches on the remote. |
| `pae git:change-branch {project} {branch}` | Switches the checkout to another branch. |
| `pae git:commits {project}` | Lists recent commits. |
| `pae git:revert {project}` | Returns the checkout to the last deployed commit. |
| `pae git:update-credentials {project}` | Replaces the stored git access token. |
| `pae git:deploy-hook {project}` | Creates the push-to-deploy hook and prints its URL and secret; the secret is shown only this once. Run again, it prints the same URL without the secret. `--rotate` issues a new URL and secret, `--delete` removes the hook, `--path` picks another checkout, `--provider` narrows the TLS setup notes to one git host. |

Context: [Connecting with Git](../05-capabilities/connecting-with-git.md), [Push to deploy](../05-capabilities/push-to-deploy.md).

## Backups

| Command | What it does |
|---|---|
| `pae backup:container:create --name= --driver= --location=` | Creates a place to store backups. `--driver` is `local`, `s3`, `ftp`, `ftps` or `sftp`. |
| `pae backup:container:list` | Lists your backup stores. |
| `pae backup:container:test {store}` | Checks the engine can reach that store. |
| `pae project:backup:create {project} --container={store}` | Takes a full backup of the project. |
| `pae project:backup:list {project}` | Lists that project's backups. |
| `pae project:backup:show {project} {id}` | Shows one backup's details. |
| `pae project:backup:restore {project} {id}` | Restores a backup. **This overwrites the live site.** |
| `pae project:backup:delete {project} {id}` | Deletes one backup. |
| `pae backup:container:show {store}` | Shows a store's settings. |
| `pae backup:container:update {store}` | Changes a store's connection details. |
| `pae backup:container:delete {store}` | Removes a store. |

A remote store needs its own connection details. `pae backup:container:create --help` lists the ones for each driver. Context: [Backups](../05-capabilities/backups.md).

## Domains and HTTPS

| Command | What it does |
|---|---|
| `pae domain:list --project={project}` | Lists a project's hostnames. |
| `pae domain:show {domain}` | Shows how that hostname is routed. |
| `pae domain:create {domain} --project={project}` | Adds a domain to a project. |
| `pae domain:delete {domain}` | Removes a hostname. |
| `pae ssl:project-cert:request {domain}` | Requests a Let's Encrypt certificate for a site. |
| `pae ssl:project-cert:renew {domain}` | Renews a site's certificate now. |
| `pae ssl:engine-cert:request --domain={domain}` | Requests a certificate for the engine itself. |
| `pae domain:tunnel:create {hostname} --project={project} --domain={domain}` | Attaches a public hostname through a tunnel. |
| `pae domain:tunnel:delete {hostname}` | Removes a tunnel hostname. |
| `pae project:settings:set cloudflare-api-token <token> --project={project}` | Saves a Cloudflare API token on the project. Cloudflare checks it before it is stored. |
| `pae domain:set-proxy {domain}` / `pae domain:unset-proxy {domain}` | Turns a proxy layer in front of a domain on or off. |
| `pae sites:base-domain {domain}` | Shows or sets the name new projects are given a site under. |
| `pae domain:wp-cli {domain} {command}` | Runs a WP-CLI command on a WordPress site. |

Extra routing is managed with `pae proxy:rule:list`, `pae proxy:rule:create`, `pae proxy:rule:update` and `pae proxy:rule:delete`. Most sites never need one.

Context: [Domains and HTTPS](../05-capabilities/domains-and-ssl.md) · [Cloudflare](../05-capabilities/cloudflare.md).

## Tokens

| Command | What it does |
|---|---|
| `pae connect` | Same command as `pae mcp:connect`. Shows the assistants; pick one with the arrow keys. Pass a name (`claude`, `claude-desktop`, `codex`, `chatgpt-desktop`, `gemini`, `grok`, `opencode`, `vscode`, `cursor`, `windsurf`, `pi`, `hermes`, `openclaw`) to create a token and print that assistant's command. |
| `pae mcp:token:create {name}` | Creates a token for an AI assistant, and prints a setup command for every assistant. Shown once. `--client=claude` prints only one. `--expires=90d` makes it temporary; `--api` also lets it call the API directly. |
| `pae mcp:token:list` | Lists assistant tokens. |
| `pae mcp:token:revoke {id}` | Stops a token working immediately. |
| `pae mcp:token:delete {id}` | Removes a token from the list. |
| `pae mcp:check {token}` | Checks HTTPS, the token, and that an assistant can connect. |
| `pae mcp:tool:list` | Lists what a connected assistant is currently allowed to do. |
| `pae configure mcp` | Changes that, by asking. See [Configuring the engine](#configuring-the-engine). |
| `pae mcp:log:list` | Lists recent assistant requests. |
| `pae api:token:create {name}` | Creates a token for your own software. Not for assistants — it is refused at the assistant endpoint unless you pass `--mcp`. `--expires=90d` makes it temporary. |
| `pae api:token:list` | Lists those software tokens. |
| `pae api:token:delete {id}` | Removes a software token. |

Setup: [Create a token](../04-connecting-your-ai/create-a-token.md).

## Files

| Command | What it does |
|---|---|
| `pae project:file:upload {project} {file} --path=` | Copies a local file into the project. |
| `pae project:file:download {project} --path= --out=` | Fetches one file out of the project. |
| `pae project:usage {project}` | Resource usage, including this month's transfer against the bandwidth limit. |
| `pae project:bandwidth {project} --start= --end= --group-by=day` | Transfer series for the project, in bytes. |
| `pae project:domain:bandwidth {project} {domain} --start= --end=` | Transfer series for one hostname. |
| `pae project:domain:visitors {project} {domain} --start= --end=` | Visitor overview for one hostname (`domain_visitors`). Daily hits and visits clip to the range; unique visitors and session length are calendar months. |
| `pae project:domain:visitors-breakdown {project} {domain} {dimension} --start= --end=` | Visitor breakdown (`domain_visitors_breakdown`): pages, countries, continents, regions, referrers, os, or browsers. Month grain. |
| `pae geolocation:database update` | Downloads the local City MMDB used for country / continent / region. Not scheduled. Geo lists stay empty until this has run. `--accept-terms` for scripts; `--force` to replace this month's file. |

Access-log ingest uses AWStats `LogFormat=1` (NCSA combined). LiteSpeed and OpenLiteSpeed vhost access logs use that same combined layout, so every current webserver variant shares this format.

Country charts still need a visible [DB-IP](https://db-ip.com) backlink: [Visitor statistics](../05-capabilities/visitor-statistics.md).

## Security

| Command | What it does |
|---|---|
| `pae modsec:log:show` | Lists the ModSecurity audit log files. Pass a filename to print one. |
| `pae project:domain:log {project} {domain}` | Lists the webserver logs for a hostname. Pass a filename to print one. |

Context: [Security](../05-capabilities/security.md).

## Telemetry and the engine itself

| Command | What it does |
|---|---|
| `pae telemetry:status` | Shows whether reports are being sent, the notification email, and what is queued. |
| `pae telemetry:disable` | Stops sending reports. Takes effect immediately. Monitoring is told to stop email probes. |
| `pae telemetry:enable` | Turns sending back on and syncs preferences to monitoring. |
| `pae telemetry:email get` | Prints the notification email, or `(not set)`. |
| `pae telemetry:email set {address}` | Stores a notification email and sends it to monitoring. |
| `pae telemetry:show` | Prints one queued report exactly as it would be sent. |
| `pae telemetry:ship` | Sends queued reports now. `--dry-run` shows the batch without sending it. |
| `pae telemetry:bug-report {project}` | Files a bug about one deployed application. |
| `pae system:version` | Prints the engine version. |
| `pae settings:get {name}` / `pae settings:set {name} {value}` | Reads and writes an engine setting. |

Context: [Telemetry](../02-getting-started/what-is-collected.md).

## Advanced and server maintenance

The engine ships more commands than belong on a daily list. Most are for support to point you at, or for server upkeep the engine normally handles on its own. Each one describes itself with `pae <command> --help`. The main groups:

- **Server upkeep:** `system:version`, `system:database:test`, `system:ip:sync`, `system:domain:rebuild`, `system:modsec:rebuild`, `system:sftp:rebuild`, `system:exim:rebuild`, `system:webserver:update`.
- **Scheduled cleanup:** `task:prune`, `metrics:prune`, `deploy:cache:prune`, `deploy:log:prune`, `acme:challenge:prune`, `vault:purge`.
- **Per-project repair:** `project:permission:fix`, `project:quota:rebuild`, `project:domain:fix`, `project:domain:rebuild`, `project:domain:cleanup`.
- **Per-domain Apache modules:** `apache:mod:enable`, `apache:mod:disable`.
- **Engine settings:** `settings:get`, `settings:set`, `settings:exists`.

Only run one of these when you understand what it does, or when support hands you the exact line.

## Anything not on this page

A few operations have no command of their own: databases, cron jobs, FTP accounts. **Ask your assistant to do those.** If you have no assistant, call the API as the engine:

```bash
pae api:call GET /projects
```

Installing, updating and removing the engine are scripts rather than `pae` commands: [Install](../02-getting-started/install.md), [Updating](../02-getting-started/updating.md), [Uninstall](../02-getting-started/uninstall.md).

## Troubleshooting

**`pae: command not found`.**
The registration step did not finish during install. Run it yourself:

```bash
bash /opt/panelalpha/shared-hosting/scripts/pae-command.sh register
```

**A command needs a project name and I do not know it.**
List them with `pae api:call GET /projects` - the value you want is `username`.

**A destructive command is asking me to confirm and I am in a script.**
Most take `--force` to skip the prompt. Be certain before you use it: `pae project:delete --all --force` deletes every project on your VPS with no further questions.
