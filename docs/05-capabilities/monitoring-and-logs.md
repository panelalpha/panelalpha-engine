# Monitoring and logs

You can find out whether a site is working, what it is using, and what it printed, without opening a shell inside a container.

The fastest route is to ask, because the assistant reads all of these sources and gives you one answer:

```text
Is this project up? Read its deploy log and tell me what the site is serving.
How much CPU and disk is this host using?
```

There are five sources behind that answer, and it is worth knowing which one answers which question.

## 1. The deploy log - "why did it fail?"

What happened during the last build and start. This is where a failed deploy explains itself, in a plain sentence when the engine recognises the cause.

Covered on [Reading errors](../02-getting-started/reading-errors.md). Open the address the assistant gave you. If it ends in `.local`, nobody on the internet can open it: [Domains and HTTPS](domains-and-ssl.md).

## 2. Is it still serving itself?

**This is the one people do not know exists, and it is the most valuable.**

A deploy log describes one moment in the past. It tells you nothing about the site a month later, after a database filled up, a certificate lapsed, or someone deleted the index page over FTP. Nothing in any deploy log will ever mention those, because the deploy succeeded.

Every six hours the engine opens every deployed application and checks whether it is still serving itself. You get a list of the ones that are not, with what each is serving instead:

```text
Swept 14 project(s): 2 not serving themselves, 2 reported.
```

To ask right now instead of waiting:

```text
Is this project still serving itself correctly?
```

Or for everything:

```text
Sweep every project on this engine and tell me which ones are not serving
themselves properly.
```

Suspended projects are skipped, because a site stopped on purpose is not a fault.

The check, the six-hourly sweep and the deploy log cover projects that run in their own container. Traditional PHP hosting is not checked this way: open its address, and read its domain logs and PHP error log (section 3).

How to read the result: [What the engine checks](#what-the-engine-checks).

To see whether anything is running on a project right now, such as a deploy, a push or a restore, ask for its tasks:

```text
Is anything running on tshop right now?
```

A push counts as a task, so it shows up here too. By default you see the tasks your own token started, plus the ones the engine started itself. A token limited to some projects sees only those projects.

## What the engine checks

Two separate questions, and the difference between them is the useful part.

**Is it alive?** Did anything at all answer.

**Is it serving your application?** Is what answered actually your site. This is the question a simple up/down check cannot answer: a placeholder page and your real homepage both return "OK" with a page of content. The engine can tell them apart.

When the answer is no, it names what it found instead. The common ones:

| What the engine reports | What is actually happening |
|---|---|
| `placeholder` | PanelAlpha's own placeholder page. Your application is not being served. |
| `no_application` | Nothing was deployed into the web directory. |
| `default_page` | The webserver's stock "It works!" page. |
| `framework_default` | Your framework's blank new-project page. The app runs, but nothing is set up at `/`. |
| `missing_entry` | No front page found: there is no index file, or the webserver cannot read it. |
| `directory_listing` | A list of files instead of a page. |
| `error_page` | Your application is running and returning an error. |
| `php_error` | A fatal PHP error is on the page. |
| `blank_page` | Your application answered `/` with an empty page. In PHP this is usually a fatal error the application hides; its container output or debug mode shows it. |
| `database_error` | The application is up but cannot reach its database. |
| `dev_server` | A development server is running, not a production build. Fix your start command. |
| `no_root_route` | Nothing is set up at `/`. |

Less often you will also see:

| What the engine reports | What is actually happening |
|---|---|
| `misconfigured_host` | The application is rejecting the domain it is being served on (Django `ALLOWED_HOSTS`, or Rails host authorisation). |
| `dependency_unreachable` | The application is up but cannot reach a service it depends on, such as a database or cache. |
| `php_source` | The browser is being sent PHP source instead of a rendered page. The document root is wrong. |
| `unclaimed_install` | The site sends visitors to the application's own installer, and nobody has finished it yet. Whoever opens it first can create the administrator account, so finish it yourself straight after the deploy. The deploy still counts as successful. |

The `no_root_route` case is worth calling out: on a backend API it is not a fault. If your application is only ever called at `/api/...`, an empty `/` is a correct observation about a site that is working perfectly.

Ask from chat:

```text
This site does not open properly. Read the deploy log, check what the site is
actually serving, and tell me what is wrong.
```

## 3. Domain logs - "what are visitors doing?"

The webserver's access and error logs for a hostname. Not the deploy log - these are the ongoing record of requests hitting the site.

```text
Show me the error log for shop.example.com.
```

On a project that runs in its own container, what the application itself printed, such as a stack trace or a worker's errors, is in its container output. Ask for a time window when you know roughly when it went wrong:

```text
Show me what the app container of shop printed between 14:00 and 14:30 today.
```

The assistant reads up to 5000 lines at a time, and can limit them to a period such as the last two hours.

**PHP errors.** On traditional PHP hosting, every project keeps its own PHP error log, one file for all its domains. It holds the last errors your PHP code raised, such as a fatal error behind a blank page.

```text
Show me the last 100 lines of the PHP error log of tshop.
```

The assistant can read up to 5000 lines and limit them to a period. The file is also in the list of log files for the project's domains. It is rotated at 10 MB, and the last three older copies are kept. On a project in its own container there is no separate file: the assistant gets what the app container printed, as above. A project that existed before the update starts writing the log after the update, once its PHP restarts or you change a PHP setting on it.

Use the webserver logs for 404s and 500s that only some visitors hit. For how many people came, and how much data they transferred, ask for the counts: [Visitor statistics](visitor-statistics.md). If the assistant cannot read the logs, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

## 4. Usage - "is it running out of something?"

Two different questions here.

**The VPS as a whole:**

```text
How much CPU, memory and disk is this host using?
```

Samples are kept over several time windows, from the last few minutes to the last twelve hours, so you can tell a spike from a trend.

**One project against its plan:**

```text
How close is this project to its limits?
```

Covers storage, the disk its container logs take, domains, subdomains, FTP and SFTP accounts, and databases. Storage counts the project's files and also its own Docker data (images, build cache and database data), which is often the larger part. Disk use is measured in the background every 15 minutes, so a file you just added can take up to that long to show. Before the first measurement it is measured when you ask. To change a limit: [Limits](projects.md#limits).

**From inside a project.** If you run a monitoring app or agent in a project (Zabbix, Netdata, node_exporter and the like), two files in it describe the whole server, not the project:

| File | Inside a project it shows |
|---|---|
| `/proc/meminfo` | the server's total memory, not the project's limit |
| `/proc/loadavg` | the server's load, which includes every other project |

Linux does not scope these two to a container, so an agent that reads them reports the server's numbers as the project's. The project's own figures are in its cgroup files. Point the agent at these:

| File | What it is |
|---|---|
| `/sys/fs/cgroup/memory.max` | the memory limit, in bytes |
| `/sys/fs/cgroup/memory.current` | the memory in use now, in bytes |
| `/sys/fs/cgroup/cpu.stat` | CPU time used (`usage_usec`) |

In an app container, `memory.max` reads `max` unless that service sets a limit of its own. The project's plan limit still applies to it.

## 5. Lighthouse - "is it fast?"

An optional performance and quality report against a site the engine hosts. Only the engine's own domains, their aliases and tunnel hostnames are accepted. Any other address, including an IP address, is refused. If the site redirects somewhere that is not one of those domains, no report comes back.

```text
Run a Lighthouse report on this site.
```

Entirely separate from deploying. A deploy never waits for one. If the assistant cannot run one, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

## Troubleshooting

**The deploy log is empty.**
The project is probably still being created. Wait a minute. If it stays empty, ask for an older deploy by its ID.

**The health check reports nothing, but I know sites are broken.**
It only reports applications it could actually reach. Suspended projects and projects it cannot open are skipped rather than reported. Ask about the specific project directly - the answer for that one will say why it was skipped.

**A site is fine in my browser but reported as not serving itself.**
The engine checks from inside the account, so it is seeing something you are not - often a cached page in your browser, or a difference between what the proxy serves and what the application returns. Trust the engine's answer and ask it what specifically failed.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a project named `tshop`.

**The server**

```text
How is this server doing? Give me CPU, memory and disk now, the last five
minutes, the last hour and the last 12 hours, and the hourly averages.
```

Mode: read. Tools: `metrics_latest`, `metrics_current`, `metrics_last_5_minutes`, `metrics_last_hour`, `metrics_last_12_hours`, `metrics_last_hour_averages`, `system_info`. Check: the numbers are consistent between views, and the memory budget is shown.

**One site, is it itself?**

```text
Is tshop serving the application itself, or something else?
```

Mode: read. Tools: `app_health_check` with `timeout` and `attempts`. Check: the answer separates "something answers" from "it is the application", and says what to do when it is not.

**Everything at once**

```text
Sweep every project on this engine and tell me which ones are not serving
themselves properly.
```

Mode: read. Tools: `project_list_summary`, `app_health_check` per project. Check: a suspended project is skipped, and each failure is paired with what it serves instead.

**Web logs**

```text
List the log files of shop.example.com on tshop, then show me the end of the
error log.
```

Mode: read. Tools: `domain_log_list`, `domain_log_download` with `offset` and `length`. Check: the answer is an excerpt, not the whole file, and the log is the site's, not the deploy log.

**PHP errors**

```text
Show the last 50 lines of the PHP error log of tshop, only from the last hour.
```

Mode: read. Tools: `php_error_log_get` with `lines` (up to 5000) and `since`. Check: on traditional PHP hosting the lines come from the project's own log, for all its domains. On a container project the same request returns the app container output.

**What the app printed**

```text
Show what the app container of tshop printed between 14:00 and 14:30 today, at
most 200 lines.
```

Mode: read. Tools: `container_list`, `container_service_logs` with `since`, `until`, `lines`. Check: the lines are inside the window and the service name comes from `container_list`.

**Restart pieces**

```text
Restart just the app service of tshop. Then stop the whole project and start it
again.
```

Mode: modify. Tools: `container_service_action`, `container_project_action`, `container_list`. Check: after each action the containers are running again, and the site answers.

**Is it fast?**

```text
Run a Lighthouse report on https://shop.example.com/ for desktop, summary only.
Then try https://example.org/.
```

Mode: modify. Tools: `lighthouse_report_create` with `desktop_preset`, `summary`. Check: the first returns scores. The second is refused, because only the engine's own domains, aliases and tunnel hostnames are accepted. An IP address is refused the same way.

**Report a problem**

```text
File a bug report about tshop: the site shows a blank page after deploy.
Severity: medium. Attach the deploy log and health, and show me exactly what
will be sent before you send it.
```

Mode: modify. Tools: `bug_report_create` with `attach_log` and `attach_health`. Check: the answer carries the redacted report, no `.env` values or tokens are in it, and the status says accepted and queued, not delivered.

**What is running**

```text
List the tasks that are running on tshop. Then list the latest ten, newest first.
```

Mode: read. Tools: `task_list` with `name`, `status` active, `since`, `task_get`. Check: only tasks of tshop come back, a push appears as a task, and a token limited to some projects cannot ask for every project's tasks.

**Tasks**

```text
List the log lines of the latest task for tshop after the last line you saw.
```

Mode: read. Tools: `task_get` with `after_id`, `task_log_list` with `since`. Check: only newer lines come back, even when several share one second.

## From the server

Deploy logs, health checks and site logs as `pae` commands: [CLI commands](../06-commands/pae-cli.md).
