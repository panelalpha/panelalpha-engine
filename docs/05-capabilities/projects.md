# Projects

A project is one hosting account: one website or application. Everything else on the engine belongs to a project - its domains, its databases, its files, its FTP logins.

This page covers creating, inspecting, rebuilding, copying, suspending and deleting them. Backups have their own page: [Backups](backups.md).

## Creating one

Creating a project from a repository *is* the deploy. It is covered in full on [Connecting with Git](connecting-with-git.md). Creating WordPress without a repository, or looking after WordPress you already host, is on [WordPress](wordpress-and-apps.md).

To see what you already have:

```text
List the projects on this engine.
```

## Inspecting one

Inspecting tells you what a project contains right now, without redeploying anything.

```text
Inspect this project. Tell me the stack, and whether the files have changed
since the last deploy.
```

**That last part is the interesting one.** If the files on disk no longer match what the last deploy recorded, somebody uploaded over FTP or edited a file directly. When a site is behaving unexpectedly and nobody deployed recently, that is the first thing to check.

## Rebuilding

A rebuild replays the exact plan from the last successful deploy against the current files.

```text
Rebuild this project.
```

Use it after you change files directly, after you change an environment variable, or when a site has degraded and you want it put back the way it shipped.

A rebuild judges itself on what the site is actually serving afterwards, not merely on whether the process started. So a rebuild that "succeeded" but left a broken page will be marked `partial` and tell you why. See [What the engine checks](monitoring-and-logs.md#what-the-engine-checks).

## Staging

Staging is a linked copy of a live site, for trying changes where visitors cannot see them.

```text
Make a staging copy of this project.
```

You get a second project with its own hostname. Break it freely. When you are happy:

```text
Push the staging copy live.
```

You can push in either direction between the pair - staging to live to release changes, or live to staging to refresh your test copy with real data.

A project can have **one** staging copy, and a staging copy cannot itself have one.

## Cloning

A clone is an independent copy, with no ongoing link and no later push.

```text
Clone this project.
```

It copies the files, the stored data and the plan limits.

**It does not copy** subdomains, FTP or SFTP accounts, or databases and their users. If your application needs a database, the clone will not have one until you create it. Use clone as a starting template, not as a backup.

## Suspending

Suspending takes a site offline without deleting anything. The usual reason is non-payment.

```text
Suspend this project.
Unsuspend this project.
```

A suspended project is also skipped by the engine's automatic health checks, because an application stopped deliberately is not a fault to report.

## Deleting

```text
Delete this project.
```

**This removes the entire hosting account** - its database records, its configuration, and its home directory with every file in it. There is no undo. Take a backup first if there is any doubt.

## Environment variables

Environment variables are settings your application reads when it starts: a database connection string, an API key, a mode switch.

```text
Set DATABASE_URL to <value> on this project and rebuild.
```

If the assistant cannot change these, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

**The rebuild is not optional.** Almost every application reads these once at startup, so changing the value without restarting the application changes nothing you can see.

Only the keys you name are changed; the rest are left alone.

If your repository commits its own `.env` file, the engine leaves that file exactly as committed. Your environment variables go into a separate file, `.env.panelalpha`, which each service that loads `.env` loads after it, so your values win. Code that reads `.env` straight from disk does not see them, and neither does a build step that reads `.env` while the site is being built, as Vite and Next.js do. Only the running application's environment has them. If you need a value at build time, either stop committing `.env`, or make the build read the value from the environment rather than from the file.

If your application refuses to start because a setting is missing or still has a placeholder value, the engine recognises that specifically and says so: [Reading errors](../02-getting-started/reading-errors.md).

## Limits

Every project has plan limits: disk, memory, CPU, bandwidth, and how many databases, FTP accounts, subdomains and domains it may have.

```text
Show this project's limits. Raise its memory limit to 2048 MB and rebuild.
```

**The rebuild is not optional.** The new limit is written into the running application when it is deployed, so changing the number without rebuilding changes nothing you can see.

The application gets a little less than the number you set. The project itself needs some memory to keep running, so a 2048 MB limit leaves the application with about 1792 MB. That is expected.

Memory limits are the ones that bite. A build that runs out of memory fails with a message that looks like a code problem but is not: [Reading errors](../02-getting-started/reading-errors.md).

## Cron jobs

Scheduled commands that belong to the application - clearing a cache, sending a digest, running a queue worker.

```text
List the cron jobs on this project.
Create one that runs /usr/bin/php /home/<user>/script.php every hour, on the hour.
```

Describe the schedule in plain words and the assistant will work out the timing for you. If the assistant cannot manage cron jobs, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

**This is not a backup schedule.** The engine has no built-in backup schedule; backups run when you ask for them. See [Backups](backups.md).

## Troubleshooting

**"That name is taken" when creating a project.**
The domain you specified is already in use, or is not allowed. Leave the domain out and let the engine assign a name, then attach your domain afterwards: [Looking after your project](../02-getting-started/looking-after-your-project.md).

**"Project already has a staging."**
One staging copy per project. Push to the existing pair, or delete the existing staging copy first.

**"Project is busy".**
A staging copy or a push is already running on this project. Wait for it to finish and try again.

**I cloned a project and the site cannot reach its database.**
Clones do not copy databases. Create the database and user on the clone, then update its environment variables to point at the new one: [Databases](databases.md).

**I changed an environment variable and nothing happened.**
You did not rebuild. See [Environment variables](#environment-variables).

**I raised the memory limit and the application still runs out.**
You did not rebuild, or the application needs more than the limit you set. See [Limits](#limits). The application gets a little less than the number you chose.

## From the server

Most of this is available as a `pae` command: [CLI commands](../06-commands/pae-cli.md).
