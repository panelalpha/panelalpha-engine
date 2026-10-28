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
| `pae configure` | Opens the menu: address and certificate, assistant tokens, API tokens, the queue, the Docker Hub login, prewarmed images, the site database caches, telemetry, and the projects allowed to call the engine's API. Pick one, do it, come back for the next. |
| `pae configure address` | The address clients connect to, the certificate on that name, and whether a new project without a tunnel is published under a free PanelAlpha name or under the engine's own name. |
| `pae configure api-access` | Let a project call the engine's API, rotate its token, or take that access away. Same as [Engine API for projects](#engine-api-for-projects). |
| `pae configure mcp-tokens` | Connect an assistant, or change what assistants may use. |
| `pae configure api-tokens` | Mint a token for your own software, and limit it to part of the API. |
| `pae configure queue` | How many deploys, backups, or staging jobs run at once. A number from 1 to 32. Left unset, it is 1 on a server with less than 4 GB of RAM and 2 otherwise. |
| `pae configure docker-hub` | The Docker Hub account image downloads go out under. |
| `pae configure prewarm` | Which base images are kept ready before a deploy asks, and the disk budget and reserve for them. None by default. |
| `pae configure sites-db` | How much memory the database for hosted PHP sites keeps for its caches. Defaults are 32M, 8M and 8M. Changing one restarts that database for a few seconds. |
| `pae configure telemetry` | Whether reports are sent, and how much they carry. |
| `pae configure --dry-run` | Any of the above. Shows what it would write, and writes nothing. |

The menu, and what each line does: [Configure the engine](../02-getting-started/configure-the-engine.md).

Choosing assistant tokens, then **Global scope**, opens:

```text
 Groups      37 of 37 groups on
 Commands    225 of 225 commands on
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

Tick what the assistant may use and untick what it may not. You never have to think about which settings file line carries which decision. The wizard works that out when it saves.

**Tokens**, on that same assistant menu, asks the same thing one token at a time: pick an assistant's token, tick what that one may use. It can only narrow. An assistant cannot be given a command the engine is not offering. Ticking everything means "no limit", so that token keeps following the engine. `pae mcp:token:list` shows the result in its **Commands** column, as `all` or `40 of 196`.

**Ceiling** is the one thing that is not a tick, and it is worth setting first. It is a ceiling on what a ticked command may *do*: at `readonly` the assistant can look and change nothing, whatever you have ticked. Nothing below it can raise it.

Nothing is written until you pick **Review and save** and confirm it, and the settings it replaces are printed so you can put them back. When you save, you land back on the first menu.

The wizard takes over the terminal while it runs. Each step is drawn over the last, so you always see the current one and nothing else. What it wrote is printed once more as it exits, and that is what stays on your screen afterwards.

The settings themselves are ordinary lines in `.env-core` and you can still edit them by hand: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

## Projects and deploys

| Command | What it does |
|---|---|
| `pae project:rebuild --project={project}` | Rebuilds from the last shipped plan. This is how you update a site deployed from a repository. |
| `pae project:deploy:log {project}` | Prints a deploy log. `--id=` picks one deploy. Without it, the latest. |
| `pae project:deploy:check {project}` | Opens the site and reports what it is actually serving. |
| `pae project:deploy:timings {project}` | Shows where the last deploy spent its time, stage by stage. |
| `pae project:health:report` | Checks every deployed application and reports the ones that have stopped serving themselves. |
| `pae project:staging {project}` | Creates a linked staging copy. |
| `pae project:push {project} {target}` | Pushes state between a staging pair. |
| `pae project:limit:get --project={project}` | Shows disk, memory and CPU limits. |
| `pae project:limit:set --project={project} --memory-limit=512` | Changes a limit. Memory is in megabytes, at least 6 (256 for a container project), and may not exceed what projects may have on this server. Every project has a memory limit, so it can be changed but not removed. Any other limit takes `-1` for unlimited. |
| `pae project:network:move --all` | Moves running projects created before this version onto the isolated network. The project container stays up. An application using the server's MySQL may restart, and its site is unavailable until it answers again. A stopped project is left alone; start it and run the command again. `--project={project}` moves one. |
| `pae project:ssh {project} '{command}'` | Runs one command inside the project. |
| `pae project:delete {project}` | Deletes the project and everything in it. Asks you to confirm. Without a terminal, such as in a script, it refuses and changes nothing unless you add `--force`. Work still queued for the project is cancelled first. While a deploy or rebuild, staging copy, push, backup or restore is running on it, the delete is refused and changes nothing; cancel that task or wait for it, then delete again. |

Create a project from a public repository and wait until it is up:

```bash
pae project:create --repo=https://github.com/org/app
```

What this does: creates the hosting account and deploys the repository in this command. Leave out the project name and the domain and the engine assigns them.

What you should see: a summary with the project name and the address to open. The deploy log prints while it runs. `--quiet-deploy` hides that log. `--json` prints the project as JSON instead of the summary.

A private repository. The URL must be `https://`, with no username or password in it:

```bash
pae project:create --repo=https://github.com/org/private-app --git-token=<token>
```

`--branch` picks a branch, tag, or commit. `--project` and `--domain` set the name and hostname when you do not want them generated. `--env-var=KEY=VALUE` can be repeated.

Deploy a local archive into a project that was created without a repository:

```bash
pae project:deploy-archive --project={project} --file=/opt/panelalpha/app.tar.gz
```

What this does: puts the archive into the project, deploys it in this command, and removes the archive again. `.zip`, `.tar.gz` and `.tgz` are accepted. `pae` runs inside the engine's container, so the file must be somewhere that container can read; anything under `/opt/panelalpha` is. A project that deploys from a repository refuses an archive: push the change and rebuild instead.

What you should see: the deploy log while it runs, then `Deployed <file> to <project>.` The command exits non-zero when the deploy fails. `--recipe`, `--env-var=KEY=VALUE` and `--quiet-deploy` work as they do for `project:create`.

The usual way to deploy is to ask your assistant: [Connecting with Git](../05-capabilities/connecting-with-git.md).

More project commands:

| Command | What it does |
|---|---|
| `pae project:deploy:list` | Lists every project that has deploy logs. Pass a name for that project's kept deploys: status, install or redeploy, source, branch, trigger, commit and times. `DEPLOY_LOG_KEEP` in `.env-core` sets how many each project keeps (10). |
| `pae project:attach {project}` | Opens a shell inside the project's container. `--u=root` to attach as another user. |
| `pae projects:php-config:refresh` | On traditional PHP hosting, makes projects created before this version write PHP errors to a log file of their own, which the assistant can then read. `--project={project}` does one, `--all` every project. Safe to run again. Each project picks it up when its PHP handler next restarts. |

## Environment variables and settings

These are the values your application reads when it starts. Change one, then rebuild, or the running application will not see it.

| Command | What it does |
|---|---|
| `pae project:settings:list --project={project}` | Lists the project's stored settings. |
| `pae project:settings:get {name} --project={project}` | Prints one setting. |
| `pae project:settings:set {name} {value} --project={project}` | Sets one setting. |
| `pae project:settings:unset {name} --project={project}` | Removes one setting. |
| `pae project:settings:set protected on --project={project}` | Marks the project protected. While it is on, an assistant or API token can no longer delete, stop or redeploy it, and the answer says the project is protected. Your own `pae` commands are not affected, and a token cannot turn it off. `pae project:settings:unset protected --project={project}` turns it off. |
| `pae project:settings:set persist-paths /app/storage,/app/public/uploads --project={project}` | Keeps what the application writes under these container paths across redeploys and rebuilds, on volumes of the project's own. Takes effect from the next deploy. A project that brings its own `docker-compose.yml` declares its volumes there instead. |
| `pae project:settings:set registry-auth "ghcr.io {user} {token}" --project={project}` | Logs the project in to private image registries, one `host username token` line per registry. The deploy uses it to pull images and build from them; the Docker login file is present in the project only while a deploy runs, and an image it covers never goes through the server's shared registries. |

Context: [Environment variables](../05-capabilities/projects.md#environment-variables).

## Repository projects

For projects deployed from git, these act on the checkout the engine owns. A pull, a branch change, or a revert on that checkout rebuilds the running site. `pae project:rebuild` rebuilds without changing the checkout.

| Command | What it does |
|---|---|
| `pae git:status {project}` | Shows the checkout's branch and whether it has local changes. |
| `pae git:pull {project}` | Pulls the latest commit, then rebuilds the application from it. |
| `pae git:branches {project}` | Lists the branches on the remote. |
| `pae git:change-branch {project} --branch={branch}` | Switches the checkout to another branch, then rebuilds. |
| `pae git:commits {project}` | Lists recent commits. |
| `pae git:revert {project}` | Returns the checkout to the last deployed commit, then rebuilds. |
| `pae git:update-credentials {project}` | Replaces the stored git access token. |
| `pae git:deploy-hook {project}` | Creates the push-to-deploy hook and prints its URL and secret; the secret is shown only this once. Run again, it prints the same URL without the secret. `--register` also adds the webhook at GitHub, GitLab or Bitbucket with the project's git token, `--rotate` issues a new URL and secret, `--delete` removes the hook, `--path` picks another checkout, `--provider` narrows the TLS setup notes to one git host. |
| `pae git:deploy-key {project}` | Creates the project's SSH deploy key and prints its public half to add to the repository. Run again, it prints the same key. `--host` also trusts a git server other than github.com, gitlab.com and bitbucket.org, `--delete` removes the key. |

Context: [Connecting with Git](../05-capabilities/connecting-with-git.md), [Push to deploy](../05-capabilities/push-to-deploy.md).

## Backups

| Command | What it does |
|---|---|
| `pae backup:container:create --name= --driver= --location=` | Creates a place to store backups. `--driver` is `local`, `s3`, `ftp`, `ftps` or `sftp`. |
| `pae backup:container:list` | Lists your backup stores. |
| `pae backup:container:test {store}` | Checks the engine can reach that store. |
| `pae project:backup:create {project} --container={store}` | Takes a full backup of the project. |
| `pae project:backup:list {project}` | Lists that project's backups. |
| `pae project:backup:schedule {project} --container={store} --cron='0 3 * * *' --keep=7` | Sets the project's automatic backup, or replaces it. The time is in UTC, and a backup may run at most once an hour. `--keep` is how many finished automatic backups are kept, 1 to 365. `--disable` turns the schedule off. |
| `pae project:backup:download {project} {id} {item} --output=/opt/panelalpha/file` | Copies one item of a finished backup to a file. Write it under `/opt/panelalpha`, which the engine's container can reach. Without `--output` the file contents go to the screen. `pae project:backup:show {project} {id}` lists the item numbers. |
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
| `pae ssl:project-cert:renew --domain={domain} --force` | Renews a site's certificate now. |
| `pae ssl:check {domain}` | Connects to the site over HTTPS and reports the certificate it really serves. Exits 1 unless visitors get a trusted one. `--json` prints everything. |
| `pae ssl:engine-cert:request --domain={domain}` | Requests a certificate for the engine itself. |
| `pae domain:tunnel:create {hostname} --project={project} --domain={domain}` | Attaches a public hostname through a tunnel. |
| `pae domain:tunnel:delete {hostname}` | Removes a tunnel hostname. |
| `pae project:settings:set cloudflare-api-token <token> --project={project}` | Saves a Cloudflare API token on the project. Cloudflare checks it before it is stored. |
| `pae domain:set-proxy --domain={domain} --proxy-to={port}` / `pae domain:unset-proxy --domain={domain}` | Turns a proxy layer in front of a domain on or off. |
| `pae sites:base-domain {domain}` | Shows or sets the name new projects are given a site under. |
| `pae domain:php-directives {domain}` | Shows the PHP settings for that hostname, on traditional PHP hosting. |
| `pae domain:php-directives:set {domain} --settings='{"memory_limit":"256M"}'` | Replaces the whole set of PHP settings for that hostname. `--clear` removes them. Naming one setting drops the others. |
| `pae domain:wp-cli {domain} {command}` | Deprecated: use `pae wordpress:command` below. Runs a WP-CLI command on a WordPress site. |

Extra routing is managed with `pae proxy:rule:list`, `pae proxy:rule:create`, `pae proxy:rule:update` and `pae proxy:rule:delete`. Most sites never need one.

Context: [Domains and HTTPS](../05-capabilities/domains-and-ssl.md) · [Cloudflare](../05-capabilities/cloudflare.md).

## WordPress

Every command takes the project first. They work on traditional PHP hosting and on WordPress in the project's own containers. Each one also takes `--path=` (the WordPress directory), `--service=` (the Compose service that runs WordPress) and `--skip-plugins` (load WordPress without its plugins and themes, for a site a broken plugin stops).

| Command | What it does |
|---|---|
| `pae wordpress:command {project} {args}` | Runs any WP-CLI command, for example `pae wordpress:command shop plugin list`. |
| `pae wordpress:plugin:list {project}` | Lists plugins. `--fields=` and `--status=` narrow it. Also `plugin:get`, `plugin:search {term}` (`--per-page=`), `plugin:is-installed`, `plugin:install` (`--force`), `plugin:activate`, `plugin:deactivate`, `plugin:update` and `plugin:delete`, each with the plugin's slug. |
| `pae wordpress:theme:list {project}` | Lists themes, with the same options. Also `theme:get`, `theme:search`, `theme:is-installed`, `theme:install`, `theme:activate`, `theme:update` and `theme:delete`. |
| `pae wordpress:user:list {project}` | Lists users. `--role=` and `--fields=` narrow it. Also `user:create {login} {email} {password}` (`--role=`, `--send-email`), `user:update {user}`, `user:password {user} {password}`, `user:delete {user}` (`--reassign=`) and `user:sso {user}` for a one-time login link. |
| `pae wordpress:user:application-password:list {project} {user}` | Lists a user's application passwords. Also `:create {user} {name}` and `:delete {user} {uuid}`. |
| `pae wordpress:core:status {project}` | Whether WordPress is installed, and the `state` when it is not. Also `core:check-update`, `core:update {version}`, `core:install {url} {title} {admin_user} {admin_email} {admin_password}` (`--skip-email`) and `core:download` (`--locale=`, `--wp-version=`; traditional PHP hosting only, never over an existing WordPress). |
| `pae wordpress:cache:flush {project}` | Flushes the object cache and the page caches of the known caching plugins. |
| `pae wordpress:maintenance:get {project}` / `maintenance:set {project} {1\|0}` | Reads or switches maintenance mode. |
| `pae wordpress:config:list {project}` | Lists `wp-config.php` entries. Also `config:get`, `config:set`, `config:delete` (`--type=constant`, `variable` or `all`) and `config:create {dbname} {dbuser}`. |
| `pae wordpress:option:list {project}` | Lists options. Also `option:get {option}` and `option:set {option} {value}`. |
| `pae wordpress:auto-update:get {project}` / `auto-update:set {project} {core} {plugins} {themes}` | Reads or sets automatic updates: core `off`, `on` or `minor`; plugins and themes `off` or `on`. |
| `pae wordpress:language:set {project} {language}` | Sets the site language, for example `pl_PL`. |
| `pae wordpress:search-replace {project} {search} {replacement}` | Replaces a string in the database. Takes only its own flags and table names after that. |
| `pae wordpress:database:export {project} {file}` | Exports the database to a file only the project's user can read. `--tables=` (comma-separated) limits it. Also `database:import {project} {file}`. |

### Importing a site

| Command | What it does |
|---|---|
| `pae wordpress:migrate {url} {username?}` | Starts an import and prints its id. `{username}` is the wp-admin user, left out for `manual_plugin`. `--method=wp_admin` (the default, with the wp-admin user) or `manual_plugin`, `--login-url=`, `--source-ip=`, `--domain=`, `--project=`, `--project-name=`. The password, or the plugin token for `manual_plugin`, comes from `WORDPRESS_MIGRATE_PASSWORD` or `WORDPRESS_MIGRATE_SOURCE_TOKEN`, or a prompt; it is never taken on the command line. |
| `pae wordpress:migrate:status {id}` | Shows how far an import is, its target, the preview address and the DNS record to set. |
| `pae wordpress:migrate:resume {id}` | Resumes a failed or stalled import. |
| `pae wordpress:migrate:cancel {id}` | Cancels an import and removes what it made here. |
| `pae wordpress:migrate:delete {id}` | Deletes the record of a finished, failed or cancelled import. |

Scheduled: `wordpress:migrate:dns-switch` (every 15 minutes: requests the certificate and removes the preview once an imported domain points here) and `wordpress:migrate:expire-secrets` (every hour: clears the passwords and tokens of imports idle for 7 days).

Context: [WordPress and known apps](../05-capabilities/wordpress-and-apps.md).

## Tokens

| Command | What it does |
|---|---|
| `pae connect` | Same command as `pae mcp:connect`. Shows the assistants; pick one with the arrow keys. Pass a name (`claude`, `claude-desktop`, `codex`, `chatgpt-desktop`, `gemini`, `grok`, `opencode`, `vscode`, `cursor`, `windsurf`, `pi`, `hermes`, `openclaw`) to create a token and print that assistant's command. |
| `pae mcp:token:create {name}` | Creates a token for an AI assistant, and prints a setup command for every assistant. Shown once. The token reaches every project; a token limited to some projects is made through the engine's API ([Create a token](../04-connecting-your-ai/create-a-token.md#a-token-limited-to-some-projects)). `--client=claude` prints only one. `--expires=90d` makes it temporary; `--api` also lets it call the API directly. |
| `pae mcp:token:list` | Lists assistant tokens. |
| `pae mcp:token:revoke {id}` | Stops a token working immediately. |
| `pae mcp:token:delete {id}` | Removes a token from the list. |
| `pae mcp:check` | Checks HTTPS, the token, and that an assistant can connect. Asks for the token, or reads it with `--stdin`. |
| `pae mcp:tool:list` | Lists what a connected assistant is currently allowed to do. |
| `pae configure mcp-tokens` | Changes that, by asking. See [Configuring the engine](#configuring-the-engine). |
| `pae mcp:log:list` | Lists recent assistant requests. `--limit=` sets how many (20 by default). `--token-id=` keeps the requests made with that token, even one deleted since; repeat it for several. `--token-name=` keeps the requests whose token name contains that text, ignoring case. `--project=` keeps the requests that named that project. |
| `pae api:token:create {name}` | Creates a token for your own software. Not for assistants. It is refused at the assistant endpoint unless you pass `--mcp`. `--expires=90d` makes it temporary. |
| `pae api:token:list` | Lists those software tokens. |
| `pae api:token:delete {id}` | Removes a software token. |

Setup: [Create a token](../04-connecting-your-ai/create-a-token.md).

## Engine API for projects

An application that manages the engine itself, such as a dashboard or an agent running as a project, can be given its own access to the engine's API. The engine makes a token for it, opens the way from the project to the API, and puts the address and token into the project's environment. Only container projects can be allowed.

| Command | What it does |
|---|---|
| `pae system:api-access:allow {project} --scope=self` | Allows a project. `--scope` is `self` (only that project, the default), `all` (every project), or a comma-separated list of project names. `--mcp` lets the token call assistant tools as well. Run again with the same scope, nothing changes. A different scope makes a new token. |
| `pae system:api-access:list` | Lists the allowed projects, their scope, whether they have assistant tools, and the token ids. Never the token itself. |
| `pae system:api-access:rotate {project}` | Replaces the project's token and keeps its scope, less any project deleted since. The old token stops working once the application runs on the new one. If that fails, both stay valid and the task says why. |
| `pae system:api-access:deny {project}` | Takes the access away: the token is revoked and the way to the API is closed. |

Deleting a project takes it out of every other project's scope, so a project created later under the same name is not reached. A scope left with no project reaches none until you allow the project again with a new one.

The project's environment gets `PANELALPHA_ENGINE_URL` and `PANELALPHA_ENGINE_TOKEN` on every deploy. `PANELALPHA_ENGINE_CA` is added only when the engine's certificate is not one the project could verify on its own. A token limited to a project cannot change that project's limits or suspend it.

## Secrets

A paste link for a Git or Cloudflare token. The value is never printed back.

| Command | What it does |
|---|---|
| `pae vault:secret:create {type} --purpose=` | Prints a paste link and the reference (`vault:<key>`). `{type}` is `git_token` or `cloudflare_api_token`. By default the secret belongs to the first project given it; `--project=NAME` makes it that project's from the start (the project need not exist yet); `--scope=global` lets any project use it. `--expires-in=SECONDS` makes the secret expire and be deleted automatically; without it the secret is kept until deleted. The link expires in an hour. |
| `pae vault:secret:list` | Lists entries: reference, type, purpose, scope, the project that owns it, and whether it is filled. Never the secret. `--scope=` or `--type=` narrows the list. |
| `pae vault:secret:delete {ref}` | Deletes one entry and its secret. `{ref}` is the `vault:<key>` from the list. Projects that already used it keep their copy. Asks you to confirm. `--force` skips that. |
| `pae vault:config` | Lists the global secrets. A project uses one only when it is given that secret's `vault:<key>`. |

Context: [A token shared by several projects](../05-capabilities/connecting-with-git.md#a-token-shared-by-several-projects) · [Cloudflare](../05-capabilities/cloudflare.md).

## Files

| Command | What it does |
|---|---|
| `pae project:file:upload {project} {file} --path=` | Copies a local file into the project. |
| `pae project:file:download {project} --path= --out=` | Fetches one file out of the project. |
| `pae project:file:fetch {project} --url= --path=` | Downloads an `http` or `https` address into a directory that already exists. `--filename=` sets the name when the address has none. This does not deploy the file. |
| `pae project:file:chmod {project} --path= --mode=755` | Sets the mode of one file or directory. Three or four octal digits. It does not walk into folders inside it. |
| `pae project:file:move-contents {project} --source= --dest=` | Moves the immediate children of a directory into a destination that already exists. A name that is already there is replaced. `--override=0` leaves the existing file. |
| `pae project:usage {project}` | Resource usage, including this month's transfer against the bandwidth limit, and the disk its own Docker takes (images, build cache, volumes) apart from its files. Disk figures are measured every 15 minutes. |
| `pae project:bandwidth {project} --start= --end= --group-by=day` | Transfer series for the project, in bytes. |
| `pae project:domain:bandwidth {project} {domain} --start= --end=` | Transfer series for one hostname. |
| `pae project:domain:visitors {project} {domain} --start= --end=` | Visitor overview for one hostname (`domain_visitors`). Daily hits and visits clip to the range; unique visitors and session length are calendar months. |
| `pae project:domain:visitors-breakdown {project} {domain} {dimension} --start= --end=` | Visitor breakdown (`domain_visitors_breakdown`): pages, countries, continents, regions, referrers, os, or browsers, at month grain; or `status_codes`, requests and bytes per HTTP status for exactly the range. |
| `pae geolocation:database update` | Downloads the local City MMDB used for country / continent / region. Not scheduled. Geo lists stay empty until this has run. `--accept-terms` for scripts; `--force` to replace this month's file. |

Country charts still need a visible [DB-IP](https://db-ip.com) backlink: [Visitor statistics](../05-capabilities/visitor-statistics.md).

## Security

| Command | What it does |
|---|---|
| `pae project:set-password --project={project}` | Asks visitors for a password on every address of that project. Prompts for the password. `--password=` sets it without a prompt. `--force` skips the confirmation. |
| `pae project:unset-password --project={project}` | Removes that password. `--force` skips the confirmation. |
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
| `pae settings:set default_tunnel none` | A new project created without naming a tunnel is published under the engine's own name (the certificate name, or the base domain if one is set), and nothing is registered with PanelAlpha Online. `panelalpha` is the default: a base domain if there is one, otherwise a free `panelalpha.online` name. Any other value is refused. A tunnel named in the request always wins. |

Context: [Telemetry](../02-getting-started/what-is-collected.md).

## PanelAlpha Connect

| Command | What it does |
|---|---|
| `pae hub:connect CODE` | Connects this engine to the PanelAlpha Connect account that created the code (**Connect engine** in Connect). `--ip`, `--replace`. |
| `pae hub:status` | Shows the connection, Connect's own view of it, and the last calls Connect made. `--local` skips asking Connect. |
| `pae hub:disconnect` | Revokes Connect's access at once, then tells Connect. |

Context: [Connect to PanelAlpha Connect](../02-getting-started/connect-to-panelalpha-connect.md).

## Docker Hub login

Every image download on your server goes to Docker Hub under one login. Without one, your server shares Docker Hub's anonymous limit with everything else on its address.

| Command | What it does |
|---|---|
| `pae docker-hub:login` | Asks for a Docker Hub username and access token, checks them with Docker Hub, and starts using them. |
| `echo "$TOKEN" \| pae docker-hub:login --username={name} --token-stdin` | The same from a script. The token never appears on the command line. |
| `pae docker-hub:status` | Which login is in use, and how many downloads it has left this hour. |
| `pae docker-hub:logout` | Goes back to anonymous downloads. |

Create the token on Docker Hub under **Account settings → Personal access tokens**, with the **Public Repo Read-only** scope. If the token can read private repositories, `docker-hub:login` lists them and stops: every project on your server could download them. `--allow-private` saves it anyway.

## Advanced and server maintenance

The engine ships more commands than belong on a daily list. Most are for support to point you at, or for server upkeep the engine normally handles on its own. Each one describes itself with `pae <command> --help`. The main groups:

- **Server upkeep:** `system:version`, `system:database:test`, `system:ip:sync`, `system:domain:rebuild`, `system:modsec:rebuild`, `system:sftp:rebuild`, `system:exim:rebuild`, `system:webserver:update`.
- **Scheduled jobs:** `backups:run-scheduled` (every minute: starts the automatic backups that are due, set with `project:backup:schedule`), `metrics:projects:disk` (every 15 minutes: measures each project's disk use for `project:usage`), `metrics:projects:sample` (every minute: CPU and memory of each project and its containers), `metrics:projects:rollup` (every hour: hourly averages of those), `metrics:projects:bandwidth` (every hour: this month's transfer on each project), `tunnels:expire` (every five minutes: removes tunnels whose lifetime has passed), `proxy:rule:refresh-certificates` (every hour: serves each extra route on port 443 with the certificate on the server that covers it now, so a renewed, removed or newly added one is picked up), `git:credentials:sweep` (every hour: removes leftover git credential helpers and deploy keys from account homes).
- **Scheduled cleanup:** `task:prune`, `metrics:prune`, `deploy:cache:prune` (runs once a day and deletes build caches unused for 24 hours; `--older-than=7d` changes the window, `--dry-run` only prints), `system:image:prune` (runs once a day; while a deploy is running the image half waits and is retried the next hour: removes deploy base images not selected in `pae configure prewarm` that no deploy has pulled, built or named for 3 days, and host build cache unused for 24 hours; `--older-than`, `--build-cache-older-than` and `--dry-run` as above, `--due-after=20h` skips the image half when it last completed within that window, defaults in `DEPLOY_HOST_IMAGE_RETENTION` and `DEPLOY_HOST_BUILD_CACHE_RETENTION`), `system:disk:guard` (every five minutes: keeps host build cache under `DEPLOY_HOST_BUILD_CACHE_MAX`, 10G, and while free space on the Docker root or /home is below `DEPLOY_DISK_PRESSURE_FREE`, 15% by default, prunes the oldest build cache and then base images unused for 6 hours; `--dry-run` only prints), `deploy:log:prune`, `acme:challenge:prune`, `vault:purge` (removes expired paste slots, never a filled engine-wide secret).
- **Per-project repair:** `project:permission:fix`, `project:quota:rebuild`, `project:domain:fix`, `project:domain:rebuild`, `project:domain:cleanup`, `project:mapping:check` (every five minutes: recreates a running project container whose image the container runtime no longer maps, which a runtime restart or a host crash leaves behind; `--dry-run` only prints).
- **PHP Apache modules (inside a project, not on the VPS):** `apache:mod:enable`, `apache:mod:disable`.
- **Engine settings:** `settings:get`, `settings:set`, `settings:exists`.

Only run one of these when you understand what it does, or when support hands you the exact line.

## Anything not on this page

A few operations have no command of their own: databases, cron jobs, FTP accounts. **Ask your assistant to do those.**

Installing, updating and removing the engine are scripts rather than `pae` commands: [Install](../02-getting-started/install.md), [Updating](../02-getting-started/updating.md), [Uninstall](../02-getting-started/uninstall.md).

## Troubleshooting

**`pae: command not found`.**
The registration step did not finish during install. Run it yourself:

```bash
bash /opt/panelalpha/shared-hosting/scripts/pae-command.sh register
```

**A command needs a project name and I do not know it.**
List them with `pae project:deploy:list` - the value you want is `username`.

**A destructive command is asking me to confirm and I am in a script.**
Most take `--force` to skip the prompt. Be certain before you use it: `pae project:delete --all --force` deletes every project on your VPS with no further questions.
