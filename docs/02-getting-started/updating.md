# Updating the engine

Updating replaces the engine software with a newer version. **The websites you host keep running the whole time** and are not rebuilt or changed.

Use the same command as [Install](install.md). If Engine is already on this VPS, that command updates it. If not, it installs.

Update when PanelAlpha releases a fix. If a deploy failed for a reason PanelAlpha has since fixed, updating is how that fix reaches your VPS.

The update restarts the engine, so a connected assistant will disconnect for under a minute and then reconnect.

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

### 2. Run the update

```bash
curl --proto '=https' -fsSL https://get.panelalpha.com/engine | sh
```

It runs on its own for a few minutes and finishes with:

```text
PanelAlpha engine has been successfully updated!
```

While it runs, your AI assistant loses its connection for under a minute and reconnects on its own. The websites you host stay up throughout.

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

**"Another instance is already running."**
An update is already in progress; only one can run at a time. Wait for it to finish.

**The version number did not change.**
The update failed or you already had the newest version. The log is in `/opt/panelalpha/log/engine-updates/`. If you start updates from your assistant, the engine information it reads reports a run that never finished as failed, with the reason and the last lines of the updater's output, instead of leaving it looking as if it is still running.

**The update stopped with `Failed to fetch`, followed by an address.**
The updater downloads only over `https` and stops if it cannot, including after a redirect to a plain `http` address. The engine on this VPS was not replaced. Check that the VPS can reach `get.panelalpha.com` over HTTPS, then run the update again.

**The update stopped while downloading the new version.**
You will see `Could not clone`, followed by the address it tried. The engine on this VPS was not replaced. Run the update again. If it stops at the same point, contact PanelAlpha at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

**My AI assistant lost its connection.**
Expected while the engine restarts. Most reconnect on their own within a minute; if yours does not, restart it. Your token still works and does not need recreating.

**A website broke straight after the update.**
Updates do not touch running websites, so these are usually unrelated. Ask your assistant: *"This site stopped working. Read its deploy log and tell me what the site is serving."*

**I need to go back to the previous version.**
There is no automatic way back, because the update also changed the engine's own database. Contact PanelAlpha with your old version number and your current one.
