# Updating the engine

Updating replaces the engine software with a newer version. **The websites you host keep running the whole time** and are not rebuilt or changed.

Use the same command as [Install](install.md). If Engine is already on this VPS, that command updates it. If not, it installs.

Update when PanelAlpha releases a fix. If a deploy failed for a reason PanelAlpha has since fixed, updating is how that fix reaches your VPS.

The update restarts the engine, so a connected assistant will disconnect for about a minute and then reconnect.

An update installs only a newer release than the one running. This holds for an update started from the engine (the API or your assistant) and for the updater on the server.

- **The newest release is what you run**, the same version from the same commit: it says `Already up to date` and changes nothing.
- **A fix published under the same version number** is installed as an update within the release.
- **The newest release is older than yours:** it refuses and changes nothing. Forcing it is covered under [Troubleshooting](#troubleshooting).

## What an update does to your sites

**The websites keep running.** An update touches only what changed:

- Docker is restarted only when its firewall chains are missing, for example after something cleared the server's firewall rules.
- The webserver for your sites is restarted only when its configuration changed.
- The SFTP service is recreated only when the start-up script a release ships for it changed, so a fix to how uploads are written reaches a server on its own. An SFTP session that is open at that moment ends.
- The engine's own certificate is not requested again unless it is due for renewal.
- While the engine's database is being migrated, its scheduler and background workers wait, so no job runs new code against the old database.
- The engine's own images are downloaded for the version being installed, and a newer build of them for the same version, such as one with operating system security fixes, is downloaded on the next update. An image is built on the server only when none is published for exactly that version's sources.

**Existing projects are brought up to date too.** When a release changes how projects are set up, the update applies it to the projects you already have: CPU limits on running containers, the web server settings of traditional PHP hosting projects, the permissions of `wp-config.php` and clean-up of old address maps. You do nothing. The engine's configuration mounts of traditional PHP hosting projects also become read-only, but each takes effect only when its container is next recreated. To apply it at once, which restarts those sites, run `pae projects:config-mounts:restrict --all --recreate`. If one of these steps fails for some project, the update still finishes and prints a warning that names the command to run again, for example `pae projects:wp-config:restrict --all`.

## If an update fails

An update that fails after it started replacing the engine's files puts the previous release back on its own, and the engine runs the version it ran before. Two limits:

- **Database changes are not undone.** Migrations the new release already ran stay.
- **Only the engine's own files come back.** Anything else the update changed on the server is not restored.

Once the new release is running and has migrated, a later failure leaves the new release in place instead of taking the code back from under its database.

Every update is recorded with the versions it moved between, how it ended and why. The engine information your assistant reads shows the last run. Its result is one of `installed`, `updated`, `up_to_date`, `refused`, `rolled_back` or `failed`.

## Before you update to 2.3

Read this if you update from 2.2 or earlier.

- **A paste page opened before the update must be opened again.** The engine's browser cookie on port 2011 changed, so a paste page for a secret that was already open cannot be saved. Open its link again. If `.env-core` has `SESSION_SECURE_COOKIE=false`, remove that line: with it, browsers refuse the new cookie and no paste page can be saved. [Change a setting](install.md#change-a-setting) shows how.
- **The engine has a new container, the root broker.** It does every step that needs root, so the engine's own container no longer has `sudo` or the Docker socket. It has its own memory cap, 4 GB by default: [Install](install.md#memory-the-engine-keeps-for-itself). How it protects the server: [Security](../05-capabilities/security.md).
- **The engine's scripts and settings files must belong to root and not be writable by anyone else.** The installer sets this. If you changed ownership or permissions by hand, the broker refuses the file and the message names the `chown` and `chmod` that fix it.
- **A command that used `sudo` inside the engine's container now fails**, because that container has no `sudo`. Run it on the VPS as `root`.
- **A hand edit to a project's `docker-compose.yml` under `users/` is refused.** The root broker starts only compose files shaped like the ones the engine writes.
- **Scripts that pass `--username=` or `--user=` to a `pae` command must pass `--project=`.** The old options are removed from those commands. The recovery script that was in `/usr/local/sbin` is gone. Use the recovery command for a stuck project: [The pae command](../06-commands/pae-cli.md). `pae wordpress:command` now exits with the exit code of WP-CLI.
- **A Cloudflare API token needs Zone · Zone · Read and must be scoped to one Cloudflare account.** [Cloudflare](../05-capabilities/cloudflare.md).
- **A new local backup store is refused inside `/opt/panelalpha/shared-hosting`, and at `/home`, `/opt/panelalpha` or `/var/www/html/storage` themselves.** [Backups](../05-capabilities/backups.md).
- **A project whose `panelalpha.yaml` asks for the server's own name or for public ports must be allowed first.** Run `pae system:public-routing:allow <project> --host-domain` for the name or `--ports` for the ports. The install flag `--full-privileges` does this for the panel it deploys.
- **A Railpack project must ask for Railpack again.** The general build is no longer chosen on its own, and the choice is not saved. Each redeploy of a project first deployed with Railpack has to ask for it again. A push to your git host cannot ask, so it replaces such a project with the "Project not configured" page. Before you update, give these projects a `Dockerfile`, a Compose file or a `panelalpha.yaml`: [How detection works](../07-supported-projects/how-detection-works.md). A failed Railpack redeploy keeps the running app online.
- **A project the engine could not recognise answers 404.** Its "Project not configured" page now carries that status and counts as not serving. Software that checks a project's address will see the change.

## Before you update to 2.2

Read this if you update from 2.1 or earlier.

- **Vault references change.** Every vault entry gets a random 24-character key, and a reference is now `vault:<key>`. Old `vault:<id>` references, such as `vault:7`, stop working. After the update, run `pae vault:secret:list` and replace the old references wherever you or your software stored them.
- **git on traditional PHP hosting.** If the server's git is older than 2.37 (Ubuntu 22.04 ships 2.34), a git pull or push on traditional PHP hosting to a host not listed in `EGRESS_ALLOW_HOSTS` is refused with `host_refused`. The installer warns about it. Upgrade git on the server, or list the git host in `EGRESS_ALLOW_HOSTS`: [Install](install.md). Projects in their own container are not affected.
- **Software that calls the engine's API** should check these changes:
  - Project limits: `-1` means unlimited on every limit except memory, and `null` means no limit set. An unlimited project now reports `-1` instead of `null`, and `cpu_limit` and `bandwidth_limit` accept `-1`. A memory limit below 6 MB, or 256 MB for a container project, is refused with `memory_limit_too_small`: [Limits](../05-capabilities/projects.md#limits).
  - Project name check (`POST /projects/verify-new-username`, `project_verify_name`): a taken or unavailable name answers 200 with `valid: false` and a `reason`, not 422.
  - Unzip never replaces or merges: an existing entry of the same name is 409 `destination_exists`.
  - Mail settings (`GET /system/exim-config`) no longer return the SMTP, Mailchannels and SES passwords or the SendGrid token. Each is `null`, with a `<field>_set` flag. Saving an empty value keeps the stored secret; `clear_secrets` names the ones to remove. The user names no longer take a `vault:` reference.
  - The assistant activity log's `token_name` filter finds names that contain the text, ignoring case, instead of an exact match.
  - A token limited to some projects must name the new project (`new_username`) when it clones or makes a staging copy, and the name must be on its list.
  - A staging copy or clone of a WordPress site on traditional PHP hosting without its databases is refused with 422 `wordpress_copy_needs_databases`.
  - Renaming a main domain to a `panelalpha.online` name is refused (`domain_proxy_zone`), as is renaming one that other tunnels forward to (`domain_has_tunnels`).
  - An HTTP proxy rule named after a domain alias, or after a traditional PHP project's domain, is refused.
  - `pae domain:wp-cli` is deprecated in favour of `pae wordpress:command`, and its `domains:wp-cli` alias is removed.

## From the server

Log in to your VPS as `root`.

### 1. Note your current version

```bash
pae system:version
```

Write it down. If anything goes wrong, this is the version you ask PanelAlpha to put you back on.

To see whether there is anything newer without changing anything:

```bash
bash /opt/panelalpha/shared-hosting/updater.sh --check
```

It prints the running version and the newest release, each with its commit, and whether an update is available. `bash /opt/panelalpha/shared-hosting/updater.sh --help` lists every option and starts nothing.

### 2. Run the update

```bash
curl --proto '=https' -fsSL https://get.panelalpha.com/engine | sh
```

It runs on its own for a few minutes and finishes with:

```text
PanelAlpha engine has been successfully updated!
```

While it runs, your AI assistant loses its connection for about a minute and reconnects on its own. The websites you host stay up throughout.

### 3. Check it worked

```bash
pae system:version
```

The number should be higher than the one you noted in step 1. Then open one of your websites in a browser to confirm nothing was disturbed.

## Troubleshooting

**Support asked me to install a specific version.**
Name it after `sh -s --`. Check your spelling before pressing Enter: a typo in the version flag quietly installs something other than the version you meant.

```bash
curl --proto '=https' -fsSL https://get.panelalpha.com/engine | sh -s -- --version 2.0.1
```

**"Update script is already running."**
An update started from the engine (the API or your assistant) is already in progress, and only one can run at a time. Wait for it to finish.

**The version number did not change.**
You already had the newest version (`Already up to date`), the newest release is older than yours (`Refusing to install`), or the update failed. The log is in `/opt/panelalpha/log/engine-updates/`. If you start updates from your assistant, the engine information it reads reports what the last update did, the version it ran on and the version it was installing, and for a run that failed or never finished, the reason and the last lines of the updater's output.

**Support asked me to install an older version, or the same one again.**
The updater refuses both unless you say so:

```bash
bash /opt/panelalpha/shared-hosting/updater.sh --version 2.0.1 --allow-downgrade
bash /opt/panelalpha/shared-hosting/updater.sh --reinstall
```

Setting `PANELALPHA_ALLOW_DOWNGRADE=1` or `PANELALPHA_REINSTALL=1` in the environment of that command has the same effect as the flags.

Going back to an older version does not undo the changes the newer one made to the engine's own database, so only do it when PanelAlpha asks you to.

**The update stopped with `Failed to fetch`, followed by an address.**
The updater downloads only over `https` and stops if it cannot, including after a redirect to a plain `http` address. The engine on this VPS was not replaced. Check that the VPS can reach `get.panelalpha.com` over HTTPS, then run the update again.

**The update stopped with `does not match its signature`, or with `Failed to fetch` an address ending in `.sig`.**
An update started from the engine (through the API or your assistant) checks the installer it downloads against the release-signing key this engine version carries, and runs nothing that does not match. The engine on this VPS was not replaced. Do not work around it: contact PanelAlpha at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

**The update stopped while downloading the new version.**
You will see `Could not clone`, followed by the address it tried. The engine on this VPS was not replaced. Run the update again. If it stops at the same point, contact PanelAlpha at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

**My AI assistant lost its connection.**
Expected while the engine restarts. Most reconnect on their own within a minute; if yours does not, restart it. Your token still works and does not need recreating.

**A website broke straight after the update.**
Updates do not touch running websites, so these are usually unrelated. Ask your assistant: *"This site stopped working. Read its deploy log and tell me what the site is serving."*

**The update failed, and says the previous release was restored.**
The update stopped after it had started replacing the engine's files, so it put the previous release's files back and restarted the engine on them. Your engine runs the version it ran before. Changes the new release made to the engine's own database stay. Run the update again; if it fails at the same point, contact PanelAlpha with the log from `/opt/panelalpha/log/engine-updates/`.

**An update was interrupted, for example the server rebooted in the middle.**
Run the update again. It first puts back the release the interrupted run was replacing, then updates.

**The update says it could not put the previous release back.**
The old files are kept in a folder the message names, and the next update puts them back first. Run the update again.

**An update printed a warning that names a `pae … --all` command.**
One step that brings existing projects up to date failed. The update itself finished. Run the command the warning names on the VPS.

**I need to go back to the previous version.**
There is no automatic way back from an update that succeeded, because the update also changed the engine's own database. Contact PanelAlpha with your old version number and your current one.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Whether a newer release exists is read on the server with `updater.sh --check`, step 1 above.

**What the last update did**

```text
What did the last engine update do? Tell me the version it ran on, the version it installed and how it ended.
```

Mode: read. Tools: `system_info`. Check: the answer gives both versions and a result of updated, up_to_date, rolled_back, refused or failed and, for a failed run, the reason.

**Update and follow it**

```text
Update this engine to the newest release. Wait for it to finish, then tell me whether it worked and which version runs now.
```

Mode: modify. Tools: `system_update`, `system_info`. Check: the assistant waits for the reconnect after the restart, reads the update's result, and does not start a second update while one runs. On an engine already on the newest release the result is up_to_date and nothing changed.

