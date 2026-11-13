# Removing the engine

This removes the engine from your VPS. **It also permanently deletes every project on it** - all files, databases and websites.

There is no undo. Back up anything you want to keep first: [Backups](../05-capabilities/backups.md).

## Remove everything

```bash
bash /opt/panelalpha/shared-hosting/uninstall.sh
```

It asks you to confirm before doing anything. When it finishes, the engine and the `pae` command are gone.

It also removes the two services the installer added for the project homes, `panelalpha-quota` (disk quota) and `panelalpha-home-mounts` (the `nosuid,nodev` mount of `/home`). They are gone from the next boot on. Until you restart the VPS, quota and the mount stay as they are, which is one more reason to restart before you reinstall. The installer does not edit `/etc/fstab`. A quota option that an older version added there stays.

## What stays on the VPS afterwards

Three things are left behind on purpose:

- **Your certificates.** Let's Encrypt limits how many you can request per week, so throwing away working ones would be wasteful if you reinstall.
- **Docker and the firewall.** Installed by the installer, but other things on the VPS may now depend on them. Remove them yourself if you want the machine completely clean.
- **Project files**, but only if something went wrong partway through. After a clean run they are gone.

## Troubleshooting

**I am moving the data elsewhere and want to keep the projects.**
This removes the engine software but leaves the project accounts and their files on the disk. You are left with website files on a VPS with nothing running or managing them, so it is a step in a move rather than a way to pause the engine.

```bash
bash /opt/panelalpha/shared-hosting/uninstall.sh --keep-projects
```

**"No such file or directory."**
The engine is not installed, or a previous uninstall already removed it. Check with `ls /opt/panelalpha`.

**I want to reinstall from scratch.**
Uninstall, **restart the VPS**, then install again. The restart matters: the installer changes how the machine handles web addresses and network access, and restarting clears out anything left over.

**Something is still running afterwards.**
The engine could not remove some projects, usually because it had already stopped before it got to them. Ask PanelAlpha before deleting anything by hand, especially if the VPS runs anything else.
