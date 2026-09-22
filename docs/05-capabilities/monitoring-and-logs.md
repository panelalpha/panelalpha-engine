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

How to read the result: [What the engine checks](#what-the-engine-checks).

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
| `database_error` | The application is up but cannot reach its database. |
| `dev_server` | A development server is running, not a production build. Fix your start command. |
| `no_root_route` | Nothing is set up at `/`. |

Less often you will also see:

| What the engine reports | What is actually happening |
|---|---|
| `misconfigured_host` | The application is rejecting the domain it is being served on (Django `ALLOWED_HOSTS`, or Rails host authorisation). |
| `dependency_unreachable` | The application is up but cannot reach a service it depends on, such as a database or cache. |
| `php_source` | The browser is being sent PHP source instead of a rendered page. The document root is wrong. |

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

Use these for 404s and 500s that only some visitors hit. For how many people came, and how much data they transferred, ask for the counts: [Visitor statistics](visitor-statistics.md). If the assistant cannot read the logs, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

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

Covers storage, domains, subdomains, FTP and SFTP accounts, and databases. To change a limit: [Limits](projects.md#limits).

## 5. Lighthouse - "is it fast?"

An optional performance and quality report against a site the engine hosts.

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

## From the server

Deploy logs, health checks and site logs as `pae` commands: [CLI commands](../06-commands/pae-cli.md).
