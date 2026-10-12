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

## The application's login

Many ready-made applications start with an administrator account. The engine makes up the username and a strong password, sets the application up with them, and keeps them, so a rebuild does not change them.

```text
What is the admin login for this project?
```

The assistant answers with the sign-in address, the username and the password. If the application has no generated login, it says so. The password is the one the application started with. If you changed it inside the application later, the engine does not know the new one. The password is never written to the deploy log. An assistant set to `readonly` cannot read it: [How far it may go](../04-connecting-your-ai/your-assistant.md#how-far-it-may-go).

## Rebuilding

A rebuild replays the exact plan from the last successful deploy against the current files.

```text
Rebuild this project.
```

Use it after you change files directly, after you change an environment variable, or when a site has degraded and you want it put back the way it shipped.

A rebuild runs in the background, and so does deploying an uploaded archive. The engine answers the assistant at once and the assistant follows the deploy until it ends, however long the build takes. Asking again while one is still running is refused and points at the one already under way, so a slow deploy is never started twice.

A rebuild does not wipe what the application keeps. For applications with a ready-made recipe, the passwords and keys the engine generated and the files people uploaded stay as they were. A project you set up yourself keeps only the storage you declared for it.

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

### A secret of the project's own

Every project's application is served from `/app`, the same path on every project on the server. An application that builds a salt, a key or a session name from its own install path therefore builds the same one everywhere. Kirby's content salt and Atheos's session cookie name are two examples.

So the engine gives every project's application `PA_INSTANCE_SECRET`: 64 hexadecimal characters, different for every project, and the same on every deploy and rebuild of that project. Use it, or a hash of it, where the application would otherwise use its path. In PHP, read it with `getenv('PA_INSTANCE_SECRET')`. Under Apache it is not in `$_SERVER`.

You cannot set it yourself: names starting with `PA_` belong to the engine. Three kinds of project do not receive it: services from your own Docker Compose file, apps from the general build (`railpack` in the log), and websites made only of files. It changes only if the engine's own key changes.

## Limits

Every project has plan limits: disk, memory, CPU, bandwidth, and how many databases, FTP accounts, subdomains and domains it may have.

```text
Show this project's limits. Raise its memory limit to 2048 MB and rebuild.
```

**The rebuild is not optional.** The new limit is written into the running application when it is deployed, so changing the number without rebuilding changes nothing you can see.

**Every project has a memory limit.** No project may have more than the server's RAM less what is kept for the engine, 512 MB by default; on a 4 GB server that is about 3.3 GB. A project created without a limit gets exactly that maximum. How much memory is free at the moment does not matter: a project is not refused because others are using memory, so projects together can be given more than the server has. Ask for the server's current metrics to see the numbers; they are under `memory_budget`.

To keep more for the engine, or for other things the server runs, set `DEPLOY_ENGINE_MEMORY` (in MB) in the engine's environment; that lowers the maximum for every project. `DEPLOY_PROJECT_MEMORY_DEFAULT` gives projects created without a limit a smaller default.

The application gets a little less than the number you set. The project itself needs some memory to keep running, so a 2048 MB limit leaves the application with about 1792 MB. That is expected.

Memory limits are the ones that bite. A build that runs out of memory fails with a message that looks like a code problem but is not: [Reading errors](../02-getting-started/reading-errors.md).

**Some builds do not use the project's memory limit.** Frontend assets, Java, Rust and PHP dependencies are often built on the server, in a separate build container, before the application starts. When `DEPLOY_BUILD_MEMORY` in `.env-core` is left empty, the default, that container gets 8192 MB. Set or not, it never gets more than half the server's RAM, nor the server's RAM less `DEPLOY_ENGINE_MEMORY` (512 MB by default). Only one such build runs at a time, and if the server still runs out of memory, the build is what the kernel stops first. Setting `DEPLOY_BUILD_MEMORY` fixes the figure for every build on the server, up to that ceiling. The deploy log states the figure, and where it came from, in a line starting `Host build container memory`. If a build like this runs out of memory, raise `DEPLOY_BUILD_MEMORY` (it helps up to that ceiling), or deploy on a server with more RAM.

## Cron jobs

Scheduled commands that belong to the application - clearing a cache, sending a digest, running a queue worker.

```text
List the cron jobs on this project.
Create one that runs /usr/bin/php /home/<user>/script.php every hour, on the hour.
```

Cron jobs run on traditional PHP hosting. A project deployed into its own container (from a repository, an archive, or WordPress set up by the engine) does not run them, and creating one there is refused; schedule the work inside the application instead, for example as a service in its Docker Compose file.

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
You did not rebuild, or the application needs more than the limit you set. See [Limits](#limits). The application gets a little less than the number you chose. If it is the *build* that runs out, check the deploy log for `Host build container memory`: it says whether the build was sized by the server or by `DEPLOY_BUILD_MEMORY`. See [Limits](#limits).

**The assistant says stored secrets cannot be decoded.**
Ask the assistant to list the affected secrets and check the engine's system information. The engine's encryption key, `APP_KEY` in `/opt/panelalpha/shared-hosting/.env-core`, may be invalid or may have changed since the secrets were stored. The project's environment variables, Git token, Cloudflare token, registry login or site password then read as empty, and the next save of the project, a redeploy included, stores them empty. Put the previous `APP_KEY` back before you change or redeploy the project. If you no longer have it, ask the assistant to set each named secret again. The application's database password is the exception: it is kept, and deploys are refused until it can be read, because a new one would lock the application out of its own database.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a project named `tshop`.

**What is on this engine**

```text
List the projects on this engine, with their domain names. Then give me the
short summary view of the same thing.
```

Mode: read. Tools: `project_list` (with `per_page`), `project_list_all` (with `with_domain_names`), `project_list_summary`. Check: the three views agree on the set of projects, and the paged one is paged.

**One project in full**

```text
Show everything about project tshop: domain, template, limits, deploy status.
```

Mode: read. Tools: `project_get`. Check: the domain, the template and the deployment status match what `domain_list` and `deploy_log_get` say.

**Change a limit and make it real**

```text
Show tshop's limits. Raise its memory limit to 2048 MB, its FTP accounts to 3
and its databases to 2, then rebuild.
```

Mode: modify. Tools: `project_get`, `project_update`, `project_rebuild`, `task_get`. Check: the new numbers read back from `project_get`, the rebuild completes, and a value above the server's maximum is refused.

**Usage against limits**

```text
How close is tshop to its limits this month? Include transfer.
```

Mode: read. Tools: `project_usage`. Check: `bandwidth.usage` against `bandwidth.maximum` (null means unlimited), and the log disk use is shown.

**Offline and back**

```text
Suspend tshop. Check that the site no longer opens. Then unsuspend it.
```

Mode: modify. Tools: `project_suspend`, `project_unsuspend`, `project_get`. Check: nothing is deleted, the project reads suspended, and after the unsuspend the site opens again.

**Site password**

```text
Put the password ShopTest-2026 on tshop. Check that the site asks for it.
Then remove it.
```

Mode: modify. Tools: `project_password_set`, `project_password_unset`. Check: while set, an anonymous request to the site is refused and the page says "This site is password protected." After the removal it opens without a prompt. The password is not repeated in the assistant's answer.

**Settings of a project**

```text
List the settings of tshop, read one, change it, then clear it.
```

Mode: full. Tools: `project_setting_list`, `project_setting_get`, `project_setting_set`, `project_setting_delete`. Check: a secret value comes back redacted, and the list says whether a value is present. Clearing a Cloudflare token while tunnels exist is refused unless forced: [Cloudflare](cloudflare.md#remove-a-hostname-or-the-token).

**Generated login**

```text
What is the admin login for tshop?
```

Mode: modify (held back in `readonly`). Tools: `app_credentials_get`. Check: the answer has the sign-in address, the username and the password, or says the application declares none. In `readonly` mode the tool is not offered.

**Clone**

```text
Clone tshop as tshopcopy. List what was not copied.
```

Mode: modify. Tools: `project_clone`, `project_get`, `domain_list`, `mysql_database_list`, `ftp_account_list`. Check: the copy has the same limits and files, a staging-style domain, and no subdomains, FTP or SFTP accounts, or databases.

**Staging and back**

```text
Make a staging copy of tshop called tshopstage. When it is ready, push staging
live. Later, push live back to staging.
```

Mode: modify. Tools: `project_staging`, `project_get` (until the staging copy is completed), `project_push` with `target`, `project_get` (until the push is completed). Check: the pair is linked, a second staging copy of the same project is refused, and a push while another runs is answered "busy".

**Name checks**

```text
Create a project called x with no repository.
```

Mode: modify. Tools: `project_verify_name`, `project_create`. Check: a too-short name is refused with the rule (3 to 15 lowercase letters and digits, starting with a letter), and nothing is created.

**Cron jobs**

```text
On the traditional PHP project tphpsite, list the cron jobs, add one that runs
/usr/bin/php /home/tphpsite/cron.php every hour on the hour, change it to every
30 minutes, then delete it.
```

Mode: full. Tools: `cron_job_list`, `cron_job_create`, `cron_job_update`, `cron_job_delete`. Check: the job is addressed by its `hash`, the schedule reads back as asked, and the same request on a container project (such as `tshop`) is refused with the reason.

**Delete**

```text
Delete the project tshopcopy and everything in it.
```

Mode: full. Tools: `project_delete`, `project_list`. Check: the assistant states that there is no undo and asks first. The project is gone from the list and its address no longer answers.

## From the server

Most of this is available as a `pae` command: [CLI commands](../06-commands/pae-cli.md).
