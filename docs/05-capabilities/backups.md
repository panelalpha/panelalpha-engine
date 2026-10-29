# Backups

A backup is a complete snapshot of one project. For a traditional PHP project that is its home folder and one dump of each of its databases. For a container project it is its files, its stored data volumes, and its databases.

Two things to know before you rely on this:

1. **Backups run when you ask, or on a schedule you set.** Without a schedule, nothing is backed up by itself: [Scheduled backups](#scheduled-backups).
2. **A traditional PHP backup does not include the project's PHP settings** (version and limits). Those are set per domain: [PHP version and settings](domains-and-ssl.md#php-version-on-traditional-php-hosting).

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
under one of the three paths the engine persists on the host: `/home`,
`/opt/panelalpha` or the engine's own storage volume (`/var/www/html/storage`).
Anywhere else (a plain `/var/backups`, say) lives in the container's writable
layer and would be lost the next time that container is recreated, while the
backup rows still point at it, so the engine refuses such a location with a 422
when you create or change the store.

Keep local stores under `/opt/panelalpha/backups/`. Avoid a folder directly in
`/home`: every project's home is `/home/<project name>`, so `/home/backups`
would be the home of a project named `backups`.

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

You get a backup ID and a status. Wait for `completed` - a backup of a large site takes a while. A backup waits for any deploy running on the project, and the other way round.

You can back up only the files, or only the databases, and add a note to find the backup again later. On a container project, "databases" also covers the stored data volumes.

```text
Back up this project, databases only, with the note "before the plugin update".
```

## Restoring

```text
List the backups for this project.
Restore last backup onto this project.
```

**A restore overwrites the live project.** Anything created since that backup was taken is gone. Always list the backups and check the date on the one you are about to restore before you confirm it. Your assistant will ask you to confirm; that confirmation is required and not a formality.

On a traditional PHP project, a restore replaces the home folder with the backed-up one and puts the old one back if the restore fails. To keep the files that are there and only write the backed-up ones over them, say so. You can also restore only the databases or only the files, if the backup has them. Choosing parts by volume is for container projects only.

The parts are chosen with `only` or `exclude`, never both. Each is an object with up to three keys: `files` (true or false), `volumes` (a list of volume names) and `databases` (a list of database names). For example, `only` with `databases` set to the one database name restores just that database.

## Download one part

A backup is stored as separate items, for example the home folder and one dump per database. To take one item off the server:

```text
Download the database dump from the newest backup of this project.
```

The assistant can only read a small piece of a file at a time, so for a large item use the server command: [CLI commands](../06-commands/pae-cli.md#backups).

## Scheduled backups

Each project can have one automatic schedule. You give it a store, a time, and how many backups to keep.

```text
Back up this project every night at 03:00 into the store local-main, and keep
the last 14.
```

Times are in UTC, not the server's local time. A schedule can run at most once an hour, and the number to keep is from 1 to 365. Only the backups the schedule made are cleaned up when there are more than that. Backups you took yourself are never deleted by it. If the project is busy at the planned time, the engine tries again for up to an hour. A store that a schedule uses cannot be deleted until the schedule is removed or pointed elsewhere.

To look at the schedule, or turn it off:

```text
Show the backup schedule of this project. Then remove it.
```

## Troubleshooting

**A 404 error when creating a backup.**
The store name or ID is wrong. Ask to list the stores first and use the exact name.

**The backup fails partway on a large site.**
Usually disk space on the destination, or a timeout to a remote store. Check free space on the target, and test the store.

**How do I schedule these?**
Ask the assistant to set a schedule: [Scheduled backups](#scheduled-backups). Do not use a project's own cron jobs for this - those run inside the project and cannot reach the engine's backup system.

**"Project is busy: a backup is running" or "a restore is running".**
A backup, a restore and a deploy do not run on the same project at the same time. Wait for the one that is running, then try again.

**"Choose files or databases to back up."**
Both were switched off. Leave at least one on.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a repository project named `tshop`. A **store** is what the tools call a backup container.

**A local store**

```text
Create a backup store called local-test on this host at /opt/panelalpha/backups/test.
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
Rename the store local-test to local-main, point it at
/opt/panelalpha/backups/main, then delete the store s3-test.
```

Mode: full. Tools: `backup_container_update`, `backup_container_delete`. Check: the changes read back from `backup_container_get`, and the deleted store is gone.

**Take a backup and follow it**

```text
Back up tshop into the store local-main. Tell me when it is completed.
```

Mode: modify. Tools: `backup_create` with `container`, `task_get`, `backup_get`. Check: an id and a status come back, and the assistant waits for `completed`. On a traditional PHP project the backup lists a home item and one item per database.

**Only files or only databases**

```text
On the traditional PHP project tphpsite, back up only the databases into
local-main with the note "before the update". Then try a backup with neither
files nor databases.
```

Mode: modify. Tools: `backup_create` with `databases`, `files` and `note`, `backup_get`. Check: the backup has database items and no home item, the note shows in `backup_list`, and the request with both off is refused as `nothing_to_back_up`.

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

Mode: modify. Tools: `backup_restore` with `confirm`, then with `only` or `exclude`, and `delete_existing_files`. Check: the assistant says a restore overwrites the live project and asks first. After the partial restore the files are untouched and the site still serves the application (`app_health_check`). On a traditional PHP project, a volume filter is refused as `volumes_not_applicable`.

**Download one item**

```text
List the items of the newest backup of tshop and download the database dump.
```

Mode: read. Tools: `backup_get`, `backup_item_download` with `item`, `offset` and `length`. Check: the item is returned in pieces of at most 49152 bytes with a `next_offset`, and a backup that is not completed answers `backup_not_completed`.

**A backup schedule**

```text
Back up tshop every night at 03:00 into local-main and keep the last 7. Show
the schedule, then try every 10 minutes, then remove it.
```

Mode: modify to set it, full to remove it. Tools: `backup_schedule_set` with `container`, `cron` and `keep_last`, `backup_schedule_get`, `backup_schedule_delete`, `backup_list` with `kind` scheduled. Check: the schedule reads back in UTC, the 10-minute one is refused as `cron_too_frequent`, and scheduled backups are listed with `kind` scheduled.

**Delete a backup**

```text
Delete the oldest backup of tshop.
```

Mode: full. Tools: `backup_delete`, `backup_list`. Check: only that backup is gone.

## From the server

Creating and restoring backups, setting up a store, scheduling and downloading: [CLI commands](../06-commands/pae-cli.md#backups). The schedule and the download have their own commands, `pae project:backup:schedule` and `pae project:backup:download`.
