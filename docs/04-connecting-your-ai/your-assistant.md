# Connecting your AI

Once your assistant is connected, you run your hosting by describing what you want in a chat window. You say *"list my projects"*, *"set up WordPress"*, *"deploy this repository"* or *"this site is broken, what happened?"*, and it does the work.

You need an installed engine. The installer already printed a Claude Code command. For any other assistant, get yours with [Create a token](create-a-token.md).

## Set your assistant up

On your VPS, run `pae connect` and pick yours with the arrow keys. Each page below has how to check it worked and what usually goes wrong.

| Assistant | Page |
|---|---|
| Claude Code | [Set up Claude Code](claude-code.md) |
| Claude Desktop | [Set up Claude Desktop](claude-desktop.md) |
| Cursor | [Set up Cursor](cursor.md) |
| Codex | [Set up Codex](codex.md) |
| ChatGPT Desktop | [Set up ChatGPT Desktop](chatgpt-desktop.md) |
| Gemini CLI | [Set up Gemini CLI](gemini-cli.md) |
| VS Code / GitHub Copilot | [Set up VS Code and Copilot](vs-code-copilot.md) |
| Grok | [Set up Grok](grok.md) |
| OpenCode | [Set up OpenCode](opencode.md) |
| Windsurf | [Set up Windsurf](windsurf.md) |
| Pi | [Set up Pi](pi.md) |
| Hermes | [Set up Hermes](hermes.md) |
| OpenClaw | [Set up OpenClaw](openclaw.md) |

Using something else? Any assistant that speaks MCP will work: [Connecting other assistants](other-mcp-clients.md).

## Decide what the assistant may do

Read this before you paste a token anywhere.

**A token with default permissions can permanently delete an entire project and everything in it**, including its files, databases and websites. That is what the engine's own interface can do, and the token grants access to all of it.

### The quick way: let the engine ask

```bash
pae configure mcp
```

What this does: shows the assistant's commands as checkboxes — whole groups, or one group's commands at a time — and one setting for how far a ticked command may go. Tick what the assistant may use, untick what it may not, and the wizard works out which of the four settings below carries each decision. Nothing is written until you pick **Review and save** and say yes, and what it replaces is printed so you can put it back. Add `--dry-run` to see what it would write and write nothing.

`pae configure` on its own starts one level up, with the list of things the engine can walk you through.

Use it instead of editing the file by hand. The rest of this section is the reference for the settings it writes, and how to set the same things yourself.

These settings live in `/opt/panelalpha/shared-hosting/.env-core`. If you edit that file by hand, the change does nothing until you restart the engine: [Change a setting](../02-getting-started/install.md#change-a-setting). Either way, an assistant that is already connected keeps the tool list it was given when it connected — reconnect it to see the change.

### How far it may go

| Value | What the assistant can do |
|---|---|
| `readonly` | Look at what is switched on, change nothing. Inspecting a repository still works. |
| `modify` | Create and change things, but not delete them. |
| `full` | Everything, including deletion. **This is the default.** |

```bash
MCP_PERMISSION_MODE=readonly
```

### Which areas it can see

A new install exposes every group. The group names the setting accepts are in the table on [Every tool](#every-tool). That is also the default:

```bash
MCP_TOOLSETS=all
```

To limit the assistant to some groups, replace `all` with a comma-separated list of those names. Do not add one name on top of `all`: `all` already includes every group.

**An empty value means every group, not none.** A setting nobody has touched and one that permits everything are the same thing here, so if you want no group at all, write `MCP_TOOLSETS=none`. Single tools named in `MCP_TOOLS` still work on top of that.

If this engine was installed earlier and you never set `MCP_TOOLSETS`, the assistant now sees every group, including ones it could not see before. Narrow the list if that is more than you want.

### One tool from an off group

Use this when you want a single operation from a group you are otherwise leaving off:

```bash
MCP_TOOLS=wp_cli_run,project_setting_set
```

What this does: exposes those tools on top of the groups above. Names must match the tool names on [Every tool](#every-tool).

### Tools to take away

```bash
MCP_DENIED_TOOLS=project_delete,*_delete
```

What this does: removes those tools no matter what enabled them. `*` matches a family: `project_*` is every project tool, `*_delete` is every delete.

A regular expression, without delimiters, is also accepted as `MCP_DENIED_TOOLS_REGEX`. An invalid pattern is ignored, so a typo cannot silently disable the denylist.

A tool has to be in an enabled group (or named in `MCP_TOOLS`), allowed by the permission ceiling, and not denied. Denials always win. Naming a destructive tool in `MCP_TOOLS` does not get it past `MCP_PERMISSION_MODE=readonly`.

These settings only affect the assistant. They do not change the REST API or `pae`.

### Check what is on offer

After a restart:

```bash
pae mcp:tool:list
```

What you should see: a table of tool names, their group, the HTTP verb, and whether each is a read or a write. The summary line is how many are exposed and how many are withheld.

To see what this configuration removes:

```bash
pae mcp:tool:list --excluded
```

To see one group:

```bash
pae mcp:tool:list --toolset=projects
```

Anything missing from the first list is missing for the assistant too.

## What to ask

These are not magic phrases. Describe things your own way. They show the level of detail worth giving. If the assistant refuses, you have limited what it may do: [Decide what the assistant may do](#decide-what-the-assistant-may-do).

**See what you already host**

```text
List the projects on this engine.
```

**Look after a WordPress site that is already here**

```text
List the users on this WordPress site.
Update all plugins on this site.
```

WP-CLI, backups and domains work on WordPress that is already on this engine. The WordPress user, install and one-click-login actions need a WordPress site in its own container. Traditional PHP hosting can still change PHP and use WP-CLI: [If WordPress is already on this engine](../05-capabilities/wordpress-and-apps.md#if-wordpress-is-already-on-this-engine).

**Change the PHP version on traditional WordPress**

```text
What PHP versions can this engine run?
Set shop.example.com to PHP 8.2.
```

**Raise PHP memory on this project**

```text
Set PHP memory_limit to 256M on this project.
```

**Deploy a public repository**

```text
Deploy my application https://github.com/org/app on this PanelAlpha Engine.
```

**Deploy a private repository**

```text
Deploy https://github.com/org/private-app on this PanelAlpha Engine.
The repository is private.
```

Do not paste the git token into the chat. The assistant sends a link on the engine; open it, paste the token, save, and tell the assistant you are done. Full steps: [A private repository](../05-capabilities/connecting-with-git.md#a-private-repository).

**Check what a repository is before deploying it**

```text
Inspect this repository and tell me what stack you detect, without creating
anything: https://github.com/org/app
```

If it lists more than one way to run the project, you can pick:

```text
Deploy https://github.com/org/app as php
```

**Add your own domain with HTTPS**

```text
Add the domain shop.example.com to this project and request a Let's Encrypt
certificate for it.
```

Point the DNS record at your VPS first, or the certificate request will fail.

**Reach a site through Cloudflare**

```text
Attach shop.example.com to this project through a Cloudflare tunnel.
```

Do not paste the Cloudflare API token into the chat. The assistant sends a paste link for it. Full steps: [Cloudflare](../05-capabilities/cloudflare.md).

**Fix a site that is not working**

```text
This site does not open. Read the deploy log, check what the site is actually
serving, and fix what you can.
```

The assistant sees the deploy log, the health checks, and what the site returns from inside your VPS.

**Create a database**

```text
Create a MySQL database named shop on this project, create a user for it, and
grant that user access.
```

**Back up and restore**

```text
Create a backup of this project.
Restore last backup onto this project.
```

A restore overwrites the live site. Ask to see the list of backups first.

**Make a test copy of a live site**

```text
Make a staging copy of this project.
Push the staging copy live.
```

**Install WordPress**

```text
Install WordPress on this project. Title: My Site. Admin user: admin.
Admin email: admin@example.com. Admin password: <password>
```

**Change a setting your application reads**

```text
Set DATABASE_URL to <value> on this project and rebuild.
```

The rebuild matters. Most applications only read their settings when they start.

**Update the engine**

```text
Update this PanelAlpha Engine to the newest version.
```

**Check the VPS itself**

```text
How much CPU, memory and disk is this host using, and how close is this
project to its limits?
```

**Report that the engine got something wrong**

```text
File a bug: the engine treated this project wrongly. Here is what happened
and what should have happened instead.
```

Open the address the assistant gives you, not the one you expected. If it ends in `.local`, the site is not reachable from the internet: [Domains and HTTPS](../05-capabilities/domains-and-ssl.md). When something is wrong, say so in the same conversation rather than starting over.

## Troubleshooting

**The assistant registered your VPS but will not connect.**
Almost always the certificate. Your engine is self-signed and the assistant's machine does not trust it. Fixed on your computer, not your VPS: [Trust a self-signed certificate](claude-code.md#trust-a-self-signed-engine-certificate).

**A 401 error.**
Wrong token type. Assistants need a token from `pae connect` or `pae mcp:token:create`, not `pae api:token:create`. Or the token was truncated when you copied it.

**The assistant says it cannot do something you know the engine can do.**
The permission settings are filtering it out. Run `pae mcp:tool:list` on your VPS. Check `MCP_PERMISSION_MODE`, `MCP_TOOLSETS`, `MCP_TOOLS` and `MCP_DENIED_TOOLS` in `.env-core`. [Every tool](#every-tool) is the full list. That list is not the same as what your assistant is currently allowed to call.

**I set a permission mode and everything became read-only.**
The value must be exactly `readonly`, `modify` or `full`. Anything else is treated as `readonly`.

**The assistant is being rate limited.**
There is a limit of 60 requests a minute per token. If you hit it routinely, something is probably retrying in a loop rather than doing 60 real operations.

**The assistant asked me to paste a git or Cloudflare token in the chat.**
Do not. Those go on a paste page the assistant should send you: [A private repository](../05-capabilities/connecting-with-git.md#a-private-repository) · [Cloudflare](../05-capabilities/cloudflare.md).

**My assistant can still do something I removed.**
Your assistant is using a list it loaded earlier. Restart your assistant, then run `pae mcp:tool:list` on your VPS to see the truth.

## Every tool

This is every MCP tool the engine ships: **182** tools, grouped by area. You do not type these names. You describe the work in chat, and the assistant picks the tool.

A **project** is one hosting account. Some tool descriptions still say *user*; that is the project's name, which every other tool takes as `name`.

A new install exposes all of them. Every group in the table below is on by default. The group name in the **Toolset** column is what you put in `MCP_TOOLSETS` if you want to narrow what the assistant can see. How to turn a group off, add one tool, or deny one: [Decide what the assistant may do](#decide-what-the-assistant-may-do). What this engine is actually offering:

```bash
pae mcp:tool:list
```

## Groups

| Group | Toolset | Default | Tools |
|---|---|---|---|
| [Engine summaries](#engine-summaries) | `engine` | On | 2 |
| [Projects](#projects) | `projects` | On | 15 |
| [Domains](#domains) | `domains` | On | 7 |
| [Domain PHP](#domain-php) | `domainphp` | On | 2 |
| [Domain ACME](#domain-acme) | `domainacme` | On | 5 |
| [Domain log files](#domain-log-files) | `domainlogfiles` | On | 2 |
| [SSL certificates](#ssl-certificates) | `sslcertificates` | On | 3 |
| [MySQL databases](#mysql-databases) | `mysqldatabases` | On | 4 |
| [MySQL users](#mysql-users) | `mysqlusers` | On | 6 |
| [MySQL privileges](#mysql-privileges) | `mysqlprivileges` | On | 3 |
| [MySQL server](#mysql-server) | `mysqlserver` | On | 3 |
| [FTP accounts](#ftp-accounts) | `ftpaccounts` | On | 4 |
| [SFTP accounts](#sftp-accounts) | `sftpaccounts` | On | 4 |
| [Cron jobs](#cron-jobs) | `cronjobs` | On | 4 |
| [Files](#files) | `files` | On | 11 |
| [PHP](#php) | `php` | On | 3 |
| [Containers](#containers) | `containers` | On | 5 |
| [App users](#app-users) | `appusers` | On | 9 |
| [Usage](#usage) | `usage` | On | 1 |
| [WP-CLI](#wp-cli) | `wpcli` | On | 1 |
| [Deploy](#deploy) | `deploy` | On | 4 |
| [Proxy rules](#proxy-rules) | `proxyrules` | On | 5 |
| [System](#system) | `system` | On | 11 |
| [Server metrics](#server-metrics) | `servermetrics` | On | 5 |
| [CSF firewall](#csf-firewall) | `csf` | On | 9 |
| [IP management](#ip-management) | `ipmanagement` | On | 6 |
| [ModSecurity](#modsecurity) | `modsecurity` | On | 9 |
| [Lighthouse](#lighthouse) | `lighthouse` | On | 1 |
| [Backup stores](#backup-stores) | `backupcontainers` | On | 6 |
| [Bug reports](#bug-reports) | `bugreports` | On | 1 |
| [Backups](#backups) | `backups` | On | 5 |
| [Tunnels](#tunnels) | `tunnels` | On | 3 |
| [Git](#git) | `git` | On | 10 |
| [Project settings](#project-settings) | `projectsettings` | On | 4 |
| [SSH](#ssh) | `ssh` | On | 1 |
| [Tasks](#tasks) | `tasks` | On | 4 |
| [Secret vault](#secret-vault) | `secretvault` | On | 4 |

## Engine summaries

| Tool | What it does |
|---|---|
| `metrics_latest` | The most recent CPU, RAM, disk I/O and network I/O sample. `metrics_current` is the same reading through the API |
| `project_list_summary` | Every hosting project on this VPS: name, status, and how many domains it has. One call, never paginates |

## Projects

| Tool | What it does |
|---|---|
| `project_clone` | Clone an existing user account |
| `project_create` | Create a new hosting project (async) |
| `project_create_sync` | Create a project and wait for the deploy in the same call. Same body as `project_create` |
| `project_delete` | Delete a user and all associated resources |
| `project_deploy_archive` | Deploy an uploaded zip/tar into ~/project |
| `project_get` | Get a user by username |
| `project_list` | List users (paginated) |
| `project_list_all` | List all users (no pagination) |
| `project_push` | Push project state to a paired staging or live project |
| `project_rebuild` | Rebuild user environment |
| `project_staging` | Create a linked staging mirror of a live project |
| `project_suspend` | Suspend a user |
| `project_unsuspend` | Unsuspend a user |
| `project_update` | Update a user |
| `project_verify_name` | Verify a username is available |

## Domains

| Tool | What it does |
|---|---|
| `domain_create` | Add a domain to a user |
| `domain_delete` | Delete a domain |
| `domain_find` | Get a domain by name (system-wide) |
| `domain_get` | Get a domain |
| `domain_list` | List domains for a user |
| `domain_update` | Update a domain |
| `ssl_cert_request` | Request a Let's Encrypt certificate for a domain |

## Domain PHP

| Tool | What it does |
|---|---|
| `domain_php_version_get` | Get PHP version for a domain |
| `domain_php_version_set` | Set PHP version for a domain |

## Domain ACME

| Tool | What it does |
|---|---|
| `acme_challenge_create` | Create an HTTP-01 ACME challenge |
| `acme_challenge_delete` | Delete a single HTTP-01 ACME challenge |
| `acme_challenge_delete_all` | Delete all HTTP-01 ACME challenges for a domain |
| `acme_challenge_get` | Get a single HTTP-01 ACME challenge |
| `acme_challenge_list` | List HTTP-01 ACME challenges for a domain |

## Domain log files

| Tool | What it does |
|---|---|
| `domain_log_download` | Download a domain log file |
| `domain_log_list` | List log files for a domain |

## SSL certificates

| Tool | What it does |
|---|---|
| `ssl_cert_get` | Get installed SSL cert for a domain |
| `ssl_cert_install` | Install a custom SSL certificate on a domain |
| `ssl_cert_list` | List all installed SSL certs for user domains |

## MySQL databases

| Tool | What it does |
|---|---|
| `mysql_database_create` | Create a MySQL database |
| `mysql_database_delete` | Delete a MySQL database |
| `mysql_database_get` | Get a MySQL database |
| `mysql_database_list` | List MySQL databases for a user |

## MySQL users

| Tool | What it does |
|---|---|
| `mysql_user_change_password` | Change a MySQL user password |
| `mysql_user_create` | Create a MySQL user |
| `mysql_user_delete` | Delete a MySQL user |
| `mysql_user_get` | Get a MySQL user |
| `mysql_user_list` | List MySQL users for a user |
| `mysql_user_rename` | Rename a MySQL user |

## MySQL privileges

| Tool | What it does |
|---|---|
| `mysql_privileges_get` | Get MySQL privileges for a user on a database |
| `mysql_privileges_revoke` | Revoke all MySQL privileges for a user on a database |
| `mysql_privileges_set` | Update MySQL privileges for a user on a database |

## MySQL server

| Tool | What it does |
|---|---|
| `mysql_server_info` | Get MySQL server connection info |
| `phpmyadmin_sso_login` | Consume a phpMyAdmin SSO token (internal use, no bearer auth) |
| `phpmyadmin_sso_token_create` | Create a phpMyAdmin SSO token for a user |

## FTP accounts

| Tool | What it does |
|---|---|
| `ftp_account_create` | Create an FTP account |
| `ftp_account_delete` | Delete an FTP account |
| `ftp_account_list` | List FTP accounts for a user |
| `ftp_account_update` | Update an FTP account |

## SFTP accounts

| Tool | What it does |
|---|---|
| `sftp_account_create` | Create an SFTP account |
| `sftp_account_delete` | Delete an SFTP account |
| `sftp_account_list` | List SFTP accounts for a user |
| `sftp_account_update` | Update an SFTP account |

## Cron jobs

| Tool | What it does |
|---|---|
| `cron_job_create` | Create a cron job |
| `cron_job_delete` | Delete a cron job |
| `cron_job_list` | List cron jobs for a user |
| `cron_job_update` | Update a cron job |

## Files

| Tool | What it does |
|---|---|
| `file_copy` | Copy a file or directory |
| `file_delete` | Delete a file or directory |
| `file_download` | Download a file |
| `file_exists` | Check if a file or directory exists |
| `file_mkdir` | Create a directory |
| `file_move` | Move or rename a file or directory |
| `file_stat` | Get file or directory stats |
| `file_unzip` | Extract a ZIP archive |
| `file_upload` | Upload a file |
| `file_write` | Write content to a file |
| `file_zip` | Create a ZIP archive |

## PHP

| Tool | What it does |
|---|---|
| `php_ini_get` | Get custom PHP INI settings for a user |
| `php_ini_set` | Update custom PHP INI settings for a user |
| `php_version_list` | List available PHP versions on your VPS |

## Containers

| Tool | What it does |
|---|---|
| `app_health_check` | Check that the deployed application answers on its published ports |
| `container_list` | List Docker containers for a user |
| `container_project_action` | Perform a project-level Docker Compose action |
| `container_service_action` | Perform a service-level Docker action |
| `container_service_logs` | Get logs from a Docker service |

## App users

| Tool | What it does |
|---|---|
| `app_info` | Get app info and capabilities |
| `app_install` | Install the app (e.g. run WordPress installer) |
| `app_role_list` | List available app roles |
| `app_sso_login` | Consume an SSO token and redirect into the app (no bearer auth) |
| `app_user_create` | Create an app user |
| `app_user_delete` | Delete an app user |
| `app_user_list` | List app users (e.g. WordPress users) |
| `app_user_reset_password` | Reset an app user password |
| `app_user_sso_create` | Create an SSO token for an app user |

## Usage

| Tool | What it does |
|---|---|
| `project_usage` | Get resource usage for a user |

## WP-CLI

| Tool | What it does |
|---|---|
| `wp_cli_run` | Run a WP-CLI command |

## Deploy

| Tool | What it does |
|---|---|
| `deploy_cancel` | Cancel a running deploy: mark cancelled, kill the subprocess tree, clean up |
| `deploy_log_get` | Poll the deploy log (JSON-lines) from a byte-like line offset |
| `project_inspect` | Inspect a project's deployed application files |
| `source_inspect` | Inspect an application source and report its stack, ports and services |

## Proxy rules

| Tool | What it does |
|---|---|
| `proxy_rule_create` | Create a proxy rule |
| `proxy_rule_delete` | Delete a proxy rule |
| `proxy_rule_get` | Get a proxy rule |
| `proxy_rule_list` | List all proxy rules |
| `proxy_rule_update` | Update a proxy rule |

## System

| Tool | What it does |
|---|---|
| `system_engine_cert_request` | Request the engine's own Let's Encrypt certificate |
| `system_exim_config_get` | Get Exim mail server configuration |
| `system_exim_config_set` | Update Exim mail server configuration |
| `system_info` | Get system information |
| `system_ssl_config_get` | How certificates for project sites are obtained |
| `system_ssl_config_set` | Set how certificates for project sites are obtained |
| `system_test_email_send` | Send a test email via Exim |
| `system_update` | Trigger a system update |
| `system_webserver_change` | Change the active webserver. Not supported: the engine runs nginx-proxy only |
| `system_webserver_config_set` | Update webserver configuration |
| `system_webserver_password_reset` | Reset the webserver panel password |

## Server metrics

| Tool | What it does |
|---|---|
| `metrics_current` | Get current server metrics |
| `metrics_last_12_hours` | Get metrics for the last 12 hours |
| `metrics_last_5_minutes` | Get metrics for the last 5 minutes |
| `metrics_last_hour` | Get metrics for the last hour |
| `metrics_last_hour_averages` | Get last hour metric averages |

## CSF firewall

| Tool | What it does |
|---|---|
| `csf_disable` | Disable CSF firewall |
| `csf_enable` | Enable CSF firewall |
| `csf_restart` | Restart CSF firewall |
| `csf_rule_create` | Add a CSF firewall rule |
| `csf_rule_delete` | Delete a CSF firewall rule |
| `csf_rule_list` | List CSF firewall rules |
| `csf_rule_update` | Edit a CSF firewall rule |
| `csf_status` | Get CSF firewall status |
| `csf_ui_credentials` | Get CSF UI credentials |

## IP management

| Tool | What it does |
|---|---|
| `ip_assign` | Assign an IP address to a user |
| `ip_assigned_list` | List assigned IP addresses |
| `ip_subnet_create` | Add an IP subnet |
| `ip_subnet_delete` | Delete an IP subnet |
| `ip_subnet_list` | List IP subnets |
| `ip_unassign` | Unassign an IP address from a user |

## ModSecurity

| Tool | What it does |
|---|---|
| `modsec_audit_log_download` | Download a ModSecurity audit log file |
| `modsec_audit_log_list` | List ModSecurity audit log files |
| `modsec_audit_log_tail` | Tail a ModSecurity audit log file |
| `modsec_mode_get` | Get ModSecurity mode |
| `modsec_mode_set` | Set ModSecurity mode |
| `modsec_ruleset_configs_set` | Toggle ModSecurity ruleset config files |
| `modsec_ruleset_disable` | Disable a ModSecurity ruleset |
| `modsec_ruleset_enable` | Enable a ModSecurity ruleset |
| `modsec_ruleset_list` | List ModSecurity rulesets |

## Lighthouse

| Tool | What it does |
|---|---|
| `lighthouse_report_create` | Generate a Lighthouse performance report |

## Backup stores

| Tool | What it does |
|---|---|
| `backup_container_create` | Create a backup container |
| `backup_container_delete` | Delete a backup container |
| `backup_container_get` | Get a backup container |
| `backup_container_list` | List backup containers |
| `backup_container_test` | Test backup container connectivity |
| `backup_container_update` | Update a backup container |

## Bug reports

| Tool | What it does |
|---|---|
| `bug_report_create` | File a bug report about a deployed application |

## Backups

| Tool | What it does |
|---|---|
| `backup_create` | Create a project backup |
| `backup_delete` | Delete a project backup |
| `backup_get` | Get a project backup |
| `backup_list` | List backups for a project |
| `backup_restore` | Restore a project backup |

## Tunnels

| Tool | What it does |
|---|---|
| `tunnel_create` | Attach a public tunnel hostname to a domain |
| `tunnel_delete` | Remove a public tunnel hostname |
| `tunnel_list` | List a domain's public tunnel hostnames |

## Git

| Tool | What it does |
|---|---|
| `git_branches` | List git branches |
| `git_change_branch` | Change the tracked git branch |
| `git_commits` | List git commits |
| `git_connect` | Connect a directory to a git remote |
| `git_disconnect` | Disconnect git from a directory |
| `git_pull` | Pull from the git remote |
| `git_push` | Push local git changes |
| `git_revert` | Revert local git changes |
| `git_status` | Git repository status |
| `git_update_credentials` | Update git credentials for a directory |

## Project settings

| Tool | What it does |
|---|---|
| `project_setting_delete` | Clear one project setting |
| `project_setting_get` | Get one project setting |
| `project_setting_list` | List a project's settings |
| `project_setting_set` | Set one project setting |

## SSH

| Tool | What it does |
|---|---|
| `ssh_run` | Run a shell command inside the project container |

## Tasks

| Tool | What it does |
|---|---|
| `task_cancel` | Cancel a queued or running task; kill the work subprocess if it still matches |
| `task_get` | Poll a task status and new log lines after a cursor |
| `task_log_list` | Page of task log lines after a timestamp and/or id cursor |
| `task_log_stream` | Stream task log lines as NDJSON until the task finishes |

## Secret vault

Used when a private repository or a Cloudflare tunnel needs a token. You paste the value on a page the engine sends you, not in the chat: [A private repository](../05-capabilities/connecting-with-git.md#a-private-repository) · [Cloudflare](../05-capabilities/cloudflare.md).

| Tool | What it does |
|---|---|
| `vault_secret_create` | Creates a paste page so you can give the engine a secret without putting it in chat |
| `vault_secret_delete` | Deletes a paste slot |
| `vault_secret_list` | Lists paste slots. The secret itself is never included |
| `vault_secret_status` | Whether a paste slot has a secret yet |

## From the server

The same work without an assistant: [CLI commands](../06-commands/pae-cli.md).
