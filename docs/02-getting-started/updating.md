# Updating the engine

Updating replaces the engine software with a newer version. **The websites you host keep running the whole time** and are not rebuilt or changed.

Update when PanelAlpha releases a fix. If a deploy failed for a reason PanelAlpha has since fixed, updating is how that fix reaches your VPS.

The update restarts the engine, so a connected assistant will disconnect for under a minute and then reconnect.

## From your assistant

If an assistant is connected, you can ask it to run the update. That is the same work as the steps below.

```text
Update this PanelAlpha Engine to the newest version.
```

What this does: the assistant starts the engine's update. The websites you host stay up.

What you should see: confirmation that the update started, then a new version number when it finishes. If an update is already running, the assistant will say so.

You can also check the version first:

```text
What version is this engine on?
```

## From the server

Log in to your VPS as `root`.

### 1. Note your current version

```bash
pae system:version
```

Write it down. If anything goes wrong, this is the version you ask PanelAlpha to put you back on.

### 2. Run the update

```bash
bash /opt/panelalpha/shared-hosting/updater.sh
```

It downloads the newest version, then stops and asks before changing anything:

- **"Detected newer version..."** - there is something newer. Type `y` and press Enter.
- **"Current installation is up to date..."** - you already have the newest version. Type `n`, unless PanelAlpha support asked you to reinstall.

Then it runs on its own for a few minutes and finishes with:

```text
PanelAlpha engine has been successfully updated!
```

While it runs, your AI assistant loses its connection for under a minute and reconnects on its own. The websites you host stay up throughout.

### 3. Check it worked

```bash
pae system:version
```

The number should be higher than the one you noted in step 1. Then open one of your websites in a browser to confirm nothing was disturbed.

## The one warning to watch for

Before it changes anything, the updater backs up the engine's own database and tells you where it went:

```text
Database backed up to /opt/panelalpha/backups/core-db-20260910-141530.sqlite
```

The name ends in `.sqlite`. An engine that still keeps its own data the old way prints the same line ending in `.sql`. Either line means the copy is there.

If instead you see this, stop and read it:

```text
Could not back up the database; continuing without one
```

The backup failed and the update carried on regardless. Everything probably still works, but there is nothing to restore from if a problem appears later. **Contact PanelAlpha before you run the updater again.**

## Troubleshooting

**Support asked me to install a specific version.**
Name it on the end of the command. Check your spelling before pressing Enter: the updater ignores anything it does not recognise instead of complaining, so a typo like `--verison 1.0.22` quietly installs something other than the version you meant.

```bash
bash /opt/panelalpha/shared-hosting/updater.sh --version 1.0.22
```

**"Another instance is already running."**
An update is already in progress; only one can run at a time. Wait for it to finish.

**The version number did not change.**
Either you answered `n`, or the update failed. The update log is in `/opt/panelalpha/log/engine-updates/latest/`.

**The update stopped while asking PanelAlpha for the download.**
You will see `Could not obtain a download token.` or `Invalid download status:`. The engine on this VPS was not replaced. Contact PanelAlpha at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

**My AI assistant lost its connection.**
Expected while the engine restarts. Most reconnect on their own within a minute; if yours does not, restart it. Your token still works and does not need recreating.

**A website broke straight after the update.**
Updates do not touch running websites, so these are usually unrelated. Ask your assistant: *"This site stopped working. Read its deploy log and tell me what the site is serving."*

**I need to go back to the previous version.**
There is no automatic way back, because the update also changed the engine's own database. Contact PanelAlpha with your old version number and your current one.
