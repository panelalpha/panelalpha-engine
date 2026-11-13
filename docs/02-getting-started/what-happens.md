# When a deploy fails

Your site did not come up. Maybe the build stopped with an error, maybe it built but the application never started, maybe it started but the page is wrong.

This page is what to do about it, in order.

**One thing to know first:** the engine never edits your code. It tells you what went wrong, in plain language. Changing your application is either your job, or your AI assistant's - never something happening silently on your VPS.

## Step 1: ask your assistant

Nine times out of ten, this is the whole answer:

```text
That deploy failed. Read the deploy log and tell me what went wrong.
```

The assistant reads the log, the error, and what the site is actually serving. It can then fix a lot of it directly - a wrong version number, a missing setting, a bad start command - and try again.

If you would rather see it yourself, or you have no assistant connected:

```bash
pae project:deploy:log <project>
```

## Step 2: read the one sentence

When the engine recognises what went wrong, the log contains a plain sentence saying so. Not a stack trace - an actual sentence, like:

> This project needs PHP 8.2, but it was built with PHP 8.1

> The build ran out of memory

> The repository was not found

The full technical output is still there underneath if anyone needs it. Start with the sentence.

Each one also has a short code and a list of what to do next, shown by your assistant and by `pae project:deploy:log`. A refusal, for example when the engine's container runtime is stopped, comes with the fix. [Codes and hints](reading-errors.md#codes-and-hints).

Common sentences, and what to do about each, are on [Reading errors](reading-errors.md). If the sentence in your log is not on that page, still start from it: the page covers the usual cases, not every wording the engine can produce.

## Step 3: work out whose problem it is

This is the fork in the road, and getting it right saves a lot of time.

**Your repository is wrong.** Missing `package.json` at the top level, no `start` script, a private repository with no access token, a dependency that cannot install. Fix the repository and deploy again. Most failures are here, and this is good news - you can fix it now.

**Your VPS ran out of something.** Out of disk, out of memory, or hitting a download rate limit. Raise the project's limits or free up space: [Limits](../05-capabilities/projects.md#limits).

**The engine got it wrong.** Your repository is fine and the engine mishandled it - it identified your application as the wrong kind, or served a placeholder page over a site that was complete. This one you report. See [Step 5](#step-5-tell-panelalpha-the-engine-got-it-wrong).

## Step 4: if the project deployed but looks wrong

A deploy that says "success" can still leave a broken page. That is not a build failure and it will not appear in the error list - the build genuinely worked.

A project the engine could not recognise serves its "Project not configured" page with a 404 and counts as not healthy, so it does not end as a plain success.

The engine checks what your site is actually serving and can usually name the problem exactly: [What the engine checks](../05-capabilities/monitoring-and-logs.md#what-the-engine-checks).

## Step 5: tell PanelAlpha the engine got it wrong

There are two different things that reach PanelAlpha, and they are not the same.

**Automatic reports.** If telemetry is on, the engine sends an anonymous summary of the failure. It lets PanelAlpha see that a kind of failure is happening across many installs. You do not have to do anything, and nobody replies to you.

**A bug report you file.** For when the engine believes it did the right thing and did not. Nothing automatic will catch that, because from the engine's point of view nothing failed.

```text
File a bug: the engine treated this project wrongly. Here is what happened,
and here is what should have happened instead.
```

You describe the problem. The engine attaches the evidence itself: what it identified your application as, what your site is actually returning, the last deploy, and the end of that deploy's log. If the assistant cannot file it, use the VPS command below.

From the server instead:

```bash
pae telemetry:bug-report <project>
```

It asks you for a title and a description, gathers the same evidence, and queues the report. Add `--contact you@example.com` if you want a reply, or `--dry-run` to see exactly what would be sent without sending it.

**If telemetry is switched off, no report can be filed at all.** Turn it back on with `pae telemetry:enable`, or contact PanelAlpha directly at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

What a report contains and what is stripped out of it: [Telemetry](what-is-collected.md#bug-reports).

## Step 6: the fix arrives as an update

When PanelAlpha fixes something on their side - better recognition of a framework, a repaired build recipe - it reaches your VPS through an engine update. [Updating](updating.md).

## What the engine will never do

- Change your source code to make a build pass
- Open a support ticket in your name
- Modify your application on its own

It does retry a few situations by itself during the same deploy, such as running low on disk. That is a retry, not an edit to your code.

## A failed create leaves nothing behind

If a project fails while being created, the engine deletes the account it had just started making. You are not left with a half-built project to clean up, and the name is free to use again.
