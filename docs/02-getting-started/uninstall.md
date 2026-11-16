# Removing the engine

This removes the engine from your VPS. **It also permanently deletes every project on it** - all files, databases and websites.

There is no undo. Back up anything you want to keep first: [Backups](../05-capabilities/backups.md).

## Remove everything

```bash
bash /opt/panelalpha/shared-hosting/uninstall.sh
```

It asks you to confirm before doing anything. When it finishes, the engine and the `pae` command are gone.

Projects go first, through the engine itself, so each one loses its containers, volumes and files the way a deleted project does. Only then does anything that would stop the engine run, whatever else you asked for on the command line.

It also removes the two services the installer added for the project homes, `panelalpha-quota` (disk quota) and `panelalpha-home-mounts` (the `nosuid,nodev` mount of `/home`). They are gone from the next boot on. Until you restart the VPS, quota and the mount stay as they are, which is one more reason to restart before you reinstall. The installer does not edit `/etc/fstab`. A quota option that an older version added there stays.

What else it takes with it:

- the files the installer left in `/opt/panelalpha`: its own script, and, when no panel is installed on the VPS, the wrapper that downloaded it, the install id and the lock file, which the panel's installer reads too;
- the engine's logs and the directories it uses while unpacking archives and restoring backups (`/home/.panelalpha-*-stage`), and its build caches in `/var/cache`;
- the three Docker networks it made;
- the Docker Hub mirror entry it added to `/etc/docker/daemon.json`, and only that entry. A running Docker keeps using it until it restarts, which the restart below does for you.

Two more things are removed only when you ask, because both go beyond the engine:

```bash
bash /opt/panelalpha/shared-hosting/uninstall.sh --remove-sysbox --purge-images
```

`--remove-sysbox` uninstalls the Sysbox runtime and with it the containers that run on it, the service user and the `daemon.json` settings that only Sysbox needs. Containers on any other runtime are left alone. `--purge-images` removes the Docker images of the engine: the ones its stack runs, the base images it builds for projects, and the ones it pulls for itself. Without that flag they stay, and the uninstaller lists them so you can remove them yourself.

## What stays on the VPS afterwards

A few things are left behind on purpose:

- **Your certificates.** Let's Encrypt limits how many you can request per week, so throwing away working ones would be wasteful if you reinstall.
- **Docker and the firewall.** Installed by the installer, but other things on the VPS may now depend on them. Remove them yourself if you want the machine completely clean, or pass `--remove-docker`.
- **Project files**, but only if something went wrong partway through. After a clean run they are gone.
- **Docker images** and other images from elsewhere, unless you pass `--purge-images`. Nothing that was not installed by the engine is removed in any case.
- **Backups**, if there are any in `/opt/panelalpha/backups`: the engine's own update backups and the projects' backups in the default local store. They are listed when they are there; delete them yourself when you no longer need them. Empty store directories go.

## Troubleshooting

**I am moving the data elsewhere and want to keep the projects.**
This removes the engine software but leaves the project accounts and their files on the disk. You are left with website files on a VPS with nothing running or managing them, so it is a step in a move rather than a way to pause the engine.

```bash
bash /opt/panelalpha/shared-hosting/uninstall.sh --keep-projects
```

**"No such file or directory."**
The engine is not installed, or a previous uninstall already removed it. Check with `ls /opt/panelalpha`.

**I want to reinstall from scratch.**
Uninstall, **restart the VPS**, then install again. The restart matters: the installer changes how the machine handles web addresses and network access, and restarting clears out anything left over, including the Docker Hub mirror entry the uninstaller removed from `daemon.json`.

**Something is still running afterwards.**
The engine could not remove some projects. Its message says whether it fell back to deleting an account by hand, and it lists the containers, volumes and home directories it left. Ask PanelAlpha before deleting anything by hand, especially if the VPS runs anything else.
