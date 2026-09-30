# Updating the engine

Updating replaces the engine software with a newer version. **The websites you host keep running the whole time** and are not rebuilt or changed.

Use the same command as [Install](install.md). If Engine is already on this VPS, that command updates it. If not, it installs.

Update when PanelAlpha releases a fix. If a deploy failed for a reason PanelAlpha has since fixed, updating is how that fix reaches your VPS.

The update restarts the engine, so a connected assistant will disconnect for under a minute and then reconnect.

## From the server

Log in to your VPS as `root`.

### 1. Note your current version

```bash
pae system:version
```

Write it down. If anything goes wrong, this is the version you ask PanelAlpha to put you back on.

### 2. Run the update

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
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
curl -fsSL https://get.panelalpha.com/engine | sh -s -- --version 2.0.1
```

**"Another instance is already running."**
An update is already in progress; only one can run at a time. Wait for it to finish.

**The version number did not change.**
The update failed or you already had the newest version. The log is in `/opt/panelalpha/log/engine-updates/`.

**The update stopped while downloading the new version.**
You will see `Could not clone`, followed by the address it tried. The engine on this VPS was not replaced. Run the update again. If it stops at the same point, contact PanelAlpha at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

**My AI assistant lost its connection.**
Expected while the engine restarts. Most reconnect on their own within a minute; if yours does not, restart it. Your token still works and does not need recreating.

**A website broke straight after the update.**
Updates do not touch running websites, so these are usually unrelated. Ask your assistant: *"This site stopped working. Read its deploy log and tell me what the site is serving."*

**I need to go back to the previous version.**
There is no automatic way back, because the update also changed the engine's own database. Contact PanelAlpha with your old version number and your current one.
