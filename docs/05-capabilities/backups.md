# Backups

A backup is a complete snapshot of one project: its files, its stored data volumes, and its databases.

Two things to know before you rely on this:

1. **Backups only run when you ask.** The engine has no built-in schedule. If you need nightly backups, you schedule them yourself from outside the engine.
2. **Only repository-deployed projects can be backed up.** Traditional PHP hosting accounts answer "not supported".

## First, somewhere to put them

A backup has to be written somewhere, and that somewhere is called a **store**. You set one up once, and every future backup goes into it.

| Kind | Where the files go |
|---|---|
| `local` | A folder on this VPS |
| `s3` | An S3-compatible bucket |
| `ftp` | A folder on an FTP server |
| `ftps` | Same, over TLS |
| `sftp` | A folder on an SSH server |

If the assistant cannot create a store, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

A `local` store writes from inside the engine container, so its folder must be
under a path the engine persists on the host -- `/home`, `/opt/panelalpha` or
the engine's own storage volume (`/var/www/html/storage`). Anywhere else (a plain `/var/backups`, say) lives in
the container's writable layer and is lost the next time that container is
recreated, while the backup rows still point at it; the engine refuses such a
location when you create the store.

```text
Create a backup store on this host at /opt/panelalpha/backups.
```

**A store on the same VPS as the engine does not protect you from losing the VPS.** It is fine for "I am about to do something risky and want to be able to undo it". For real protection against disk failure or the VPS being lost, use a remote store.

To check the engine can actually reach a remote store before you depend on it:

```text
Test that backup store.
```

## Taking a backup

```text
Create a backup of this project.
```

You get a backup ID and a status. Wait for `completed` - a backup of a large site takes a while.

## Restoring

```text
List the backups for this project.
Restore last backup onto this project.
```

**A restore overwrites the live project.** Anything created since that backup was taken is gone. Always list the backups and check the date on the one you are about to restore before you confirm it. Your assistant will ask you to confirm; that confirmation is required and not a formality.

## Troubleshooting

**"Not supported."**
This is a traditional PHP hosting account, not a repository-deployed project. Backups do not run on those.

**A 404 error when creating a backup.**
The store name or ID is wrong. Ask to list the stores first and use the exact name.

**The backup fails partway on a large site.**
Usually disk space on the destination, or a timeout to a remote store. Check free space on the target, and test the store.

**How do I schedule these?**
The engine will not do it for you. Use `cron` on your VPS to call `pae project:backup:create`. Do not use a project's own cron jobs for this - those run inside the project and cannot reach the engine's backup system.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a repository project named `tshop`. A **store** is what the tools call a backup container.

**A local store**

```text
Create a backup store called local-test on this host at /var/backups/test.
Test that it works, then list the stores.
```

Mode: modify. Tools: `backup_container_create` with `driver` local, `backup_container_test`, `backup_container_list`, `backup_container_get`. Check: the test passes, and the store is in the list.

**A remote store**

```text
Create a backup store called s3-test on an S3 bucket, and test it. Do not ask
me to type the access keys into this chat.
```

Mode: modify. Tools: `backup_container_create` with `driver` s3 and `credentials`, `backup_container_test`. Check: the assistant does not ask for the keys in the chat, and the test passes. `ftp`, `ftps` and `sftp` are the other drivers, and each is tested the same way before it is relied on.

**Change or remove a store**

```text
Rename the store local-test to local-main, point it at /var/backups/main, then
delete the store s3-test.
```

Mode: full. Tools: `backup_container_update`, `backup_container_delete`. Check: the changes read back from `backup_container_get`, and the deleted store is gone.

**Take a backup and follow it**

```text
Back up tshop into the store local-main. Tell me when it is completed.
```

Mode: modify. Tools: `backup_create` with `container`, `task_get`, `backup_get`. Check: an id and a status come back, the assistant waits for `completed`, and a traditional PHP project answers "not supported".

**List and inspect**

```text
List the backups of tshop with their dates and sizes, and show the newest one.
```

Mode: read. Tools: `backup_list`, `backup_get`. Check: the dates are right, so the assistant can pick the correct one before a restore.

**Restore all, then only part**

```text
Restore the newest backup onto tshop. Then restore only the database from an
older one, leaving the files as they are.
```

Mode: modify. Tools: `backup_restore` with `confirm`, then with `only` or `exclude`. Check: the assistant says a restore overwrites the live project and asks first. After the partial restore the files are untouched and the site still serves the application (`app_health_check`).

**Delete a backup**

```text
Delete the oldest backup of tshop.
```

Mode: full. Tools: `backup_delete`, `backup_list`. Check: only that backup is gone.

## From the server

Creating and restoring backups, and setting up a store: [CLI commands](../06-commands/pae-cli.md#backups).
