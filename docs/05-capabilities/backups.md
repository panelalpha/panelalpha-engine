# Backups

A backup is a complete snapshot of one project. For a traditional PHP project that is its home folder and one dump of each of its databases. For a container project it is its files, its stored data volumes and its databases.

Two things to know before you rely on this:

1. **Backups run when you ask, or on a schedule you set.** Without a schedule, nothing is backed up by itself: [Scheduled backups](#scheduled-backups).
2. **A traditional PHP backup does not include the project's PHP settings** (version and limits). Those are set per domain: [PHP version and settings](domains-and-ssl.md#php-version-on-traditional-php-hosting).

## First, somewhere to put them

A backup has to be written somewhere, and that somewhere is called a **store**. You set one up once, and every future backup goes into it.

**The engine sets one up for you.** An install creates a local store named `local` in `/opt/panelalpha/backups/local` and marks it the **default**: the store a panel uses when nobody has configured one. So a fresh install can back a project up without anyone creating a store first. An Engine update does the same on a server that has no store at all, and nothing on a server that has one: your stores are kept as they are, and the oldest is the default.

The default is not fixed. `GET /system/info` reports it as `default_backup_container` and `GET /backup-containers` marks it `"default": true`; ask the assistant, or use `pae backup:container:update <store> --default`, to make another store the default. Deleting the default store works like deleting any other; the oldest store left takes the flag over, and `pae backup:container:ensure-default` (which the installer runs) creates `local` again on a server that has no store.

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
when you create or change the store. It refuses the engine's own directory,
`/opt/panelalpha/shared-hosting`, and anything below it, the same way: only the
engine's own files may be written there, so every backup into it would fail. It
also refuses `/home`, `/opt/panelalpha` and `/var/www/html/storage` themselves, which the
engine depends on: use a folder below them. **A store that already sits at one of
these paths refuses every change, a rename included, until you move it** to a
folder such as `/opt/panelalpha/backups/`.

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

When the test fails, the answer says what failed on one line: the kind of store, the host, the folder or bucket and the reason the storage itself gave. Passwords and keys in it are hidden.

## Taking a backup

```text
Create a backup of this project.
```

You get a backup ID, a status and the ID of the task that takes it (`backup_task_id`; a restore and a delete have `restore_task_id` and `delete_task_id`). Wait for `completed`, because a backup of a large site takes a while.

A backup that names no store goes to the default one. On a server that has no default store it is refused with `no_default_container`: create a store, or name one.

A backup, a restore, a push and a staging copy never run on the same project at the same time. The second one is refused as busy, and a scheduled backup tries again.

A backup or restore stops the app while it works and always starts it again, also when it fails. A long backup or restore has one time limit for all its steps, so it cannot hang with the app stopped.

You can back up only the files, or only the databases, and add a note to find the backup again later. On a container project, "databases" also covers the stored data volumes.

```text
Back up this project, databases only, with the note "before the plugin update".
```

## Restoring

```text
List the backups for this project.
Restore last backup onto this project.
```

**A restore overwrites the live project.** Anything created since that backup was taken is gone. Always list the backups and check the date on the one you are about to restore before you confirm it. Your assistant will ask you to confirm; that confirmation is required and not a formality. A backup that is not `completed` cannot be restored, and neither can a protected project: [Protecting](projects.md#protecting).

On a traditional PHP project, a restore replaces the home folder with the backed-up one and puts the old one back if the restore fails. To keep the files that are there and only write the backed-up ones over them, say so. You can also restore only the databases or only the files, if the backup has them. Choosing parts by volume is for container projects only. On a traditional PHP project, a restore that fails puts the databases back as they were, and a restore of a classic WordPress site makes its `wp-config.php` private again.

The parts are chosen with `only` or `exclude`, never both. Each is an object with up to three keys: `files` (true or false), `volumes` (a list of volume names) and `databases` (a list of database names). For example, `only` with `databases` set to the one database name restores only that database.

## Deleting

**Deleting a backup removes its files, and that copy of the project can never be restored.** Deleting a store that still holds backups is refused unless you ask to delete the backups with it, and then they are gone for good. The assistant says what will be lost and asks you to confirm first.

### Into a new project

A backup can also be restored into a **new project**, under a new name and domain, without touching the project it was taken from. The new project is created, the backup's files, volumes and application database are restored into it under its own database prefix, and the application is started on its own domain. The original project keeps running and keeps its backups.

The new project signs in to its own database. Its environment variables and the login a recipe generated come from the original, since the restored data was set up with them. Any file in the restored code that holds the original's database password, such as the configuration Matomo writes during its setup, gets the new project's database login and domain instead. A database that runs in the project's own containers comes back from its volume and belongs to the new project alone.

```text
Restore the newest backup of tshop into a new project called tshop2.
```

Only a backup of a container project can be restored this way. A traditional PHP hosting backup carries an application configuration that names the original project's databases, so restoring it beside the original would leave both projects sharing one database. Use a container project, or the WordPress migration for a traditional PHP site. For the same reason a backup that holds databases besides the project's application database is refused: the new project would still sign in to the original's copies of them.

A restore into a new project runs as a task: the call answers with the new project and a task ID, and the project's `async_status.restore` becomes `completed` or `failed`. A failed one leaves the new project in place, marked failed like a failed deploy, with the reason in its error, so it can be inspected and deleted.

A token limited to some projects can restore only its own projects' backups, and only into a new project name that is in its list.

## Download one part

A backup is stored as separate items, for example the home folder and one dump per database. To take one item off the server:

```text
Download the database dump from the newest backup of this project.
```

The assistant can only read a small piece of a file at a time, so for a large item use the server command: [CLI commands](../06-commands/pae-cli.md#backups).

## Scheduled backups

Each project can have one automatic schedule. You give it a store, a time and how many backups to keep. Without a store a new schedule uses the default one, and a changed schedule keeps the store it has.

```text
Back up this project every night at 03:00 into the store local-main, and keep
the last 14.
```

Times are in UTC, not the server's local time. A schedule can run at most once an hour, and the number to keep is from 1 to 365. Only the backups the schedule made are cleaned up when there are more than that. Backups you took yourself are never deleted by it. If the project is busy at the planned time, the engine tries again for up to an hour, then skips that run. On the server, `backups:run-scheduled` prints what it did for each schedule that was due. A store that a project's enabled schedule uses cannot be deleted; the refusal names those projects. Turning a schedule off is enough to release the store: a disabled schedule holds nothing, and deleting the store removes it.

To look at the schedule, or turn it off:

```text
Show the backup schedule of this project. Then remove it.
```

## Backups and the live sites on the same server

A backup reads the whole project and compresses it, on the same server that serves every site. On a large project that is minutes of CPU and a lot of disk reading, at the same priority as the sites themselves.

You can put it out of the way instead:

```bash
pae configure backups
```

Two numbers. The **CPU priority** is `nice`, 0 to 19: a higher number means the archive waits longer for the CPU and the sites get it first. The **disk priority** is an `ionice` class, either 2 (best-effort) or 3 (idle): idle gives a backup the disk only when nothing else is asking for it. Both are off by default, which is how every engine starts; answer empty or `off` to switch either back off. The next archive runs at the new priority - one already running keeps the priority it started with.

It covers the archives: the `tar` of a project, of a home directory or of a stored volume, and the extraction of a volume archive on restore. Database dumps are not included.

Over the API the same two values are `GET /system/backup-settings` and `PUT /system/backup-settings`, and with an assistant, `system_backup_settings_get` and `system_backup_settings_set`. All three read and write the same stored setting, so they always agree.

## Troubleshooting

**A 404 error when creating a backup.**
The store name or ID is wrong. Ask to list the stores first and use the exact name.

**The backup fails partway on a large site.**
Usually disk space on the destination, or a timeout to a remote store. Check free space on the target, and test the store.

**How do I schedule these?**
Ask the assistant to set a schedule: [Scheduled backups](#scheduled-backups). Do not use a project's own cron jobs for this: those run inside the project and cannot reach the engine's backup system.

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

**Make a store the default**

```text
Make the store local-main the default one.
```

Mode: modify. Tools: `backup_container_update` with `default`. Check: `backup_container_list` marks `local-main` and no longer marks the store that was the default.

**Take a backup and follow it**

```text
Back up tshop into the store local-main. Tell me when it is completed.
```

Mode: modify. Tools: `backup_create` with `container`, `task_get`, `backup_get`. Check: an id and a status come back, and the assistant waits for `completed`. On a traditional PHP project the backup lists a home item and one item per database.

**A backup without naming a store**

```text
Back up tshop. Which store did it go to?
```

Mode: modify. Tools: `backup_create`, `backup_get`, `backup_container_list`. Check: the backup is in the store marked `default` in the list. On a server with no default store the request is refused as `no_default_container`.

**Only files or only databases**

```text
On the traditional PHP project tphpsite, back up only the databases into
local-main with the note "before the update". Then try a backup with neither
files nor databases.
```

Mode: modify. Tools: `backup_create` with `databases`, `files` and `note`, `backup_get`. Check: the backup has database items and no home item, the note shows in `backup_list` and the request with both off is refused as `nothing_to_back_up`.

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

Mode: modify. Tools: `backup_restore` with `confirm`, then with `only` or `exclude` and `delete_existing_files`. Check: the assistant says a restore overwrites the live project and asks first. After the partial restore the files are untouched and the site still serves the application (`app_health_check`). On a traditional PHP project, a volume filter is refused as `volumes_not_applicable`.

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

**A store in a refused place**

```text
Create a backup store called bad-store on this host at /opt/panelalpha/shared-hosting/backups.
```

Mode: modify. Tools: `backup_container_create`. Check: the store is refused with the reason that only the engine's own files may be written there, and nothing is created.

**Restore only some parts**

```text
Restore only the databases from the newest backup of tshop. Then restore
everything except the files.
```

Mode: modify. Tools: `backup_restore` with `only` and with `exclude`. Check: the first restores only the databases and the second restores everything but the files.

**A backup while a push runs**

```text
Back up tshop while a push to its staging copy is running.
```

Mode: modify. Tools: `backup_create`, `project_push`, `task_get`. Check: the backup is refused as busy, and works once the push has finished. After any backup the app is running again. Skip when you cannot start both within the push's run time.

**Restore into a new project**

```text
Restore the newest backup of tshop into a new project called tshop2.
```

Mode: modify. Tools: `backup_restore_into` with `name` (the source project), `id`, `new_name` and `domain`, `task_get`, `project_get`. Check: the call answers 202 with the new project and a `task_id`; poll until `details.async_status.restore` is `completed`; `tshop` still serves and its backup list is unchanged; `tshop2` answers on its own domain.

**Delete a backup**

```text
Delete the oldest backup of tshop.
```

Mode: full. Tools: `backup_delete`, `backup_list`. Check: only that backup is gone, and a folder the delete leaves empty is removed too. Deleting a local store that holds nothing removes its folder.

## From the server

Creating and restoring backups, setting up a store, scheduling and downloading: [CLI commands](../06-commands/pae-cli.md#backups). The schedule and the download have their own commands, `pae project:backup:schedule` and `pae project:backup:download`. A restore into a new project is `pae project:backup:restore-into`.
