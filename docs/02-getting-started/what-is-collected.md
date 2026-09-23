# Telemetry

Telemetry is reports your engine sends to PanelAlpha about deploys and about projects that later stopped serving themselves. It exists so a failure that shows up on many installs can be fixed in the product, instead of looking like one broken repository. It is on after install, and reports do leave your VPS unless you change that: [How to turn telemetry off](how-to-turn-it-off.md).

```text
Is telemetry on on this engine, and what does it send?
```

What this does: the assistant checks whether reports are being sent, and summarises what a report contains.

What you should see: whether sending is on, and that reports include the public names of your projects, not your source code.

## What it is

A small, automatic record of how a deploy went, plus a later check that the site is still serving itself. Your VPS is identified by a fingerprint, not by name. The public names of your sites are included, because a report has to say which site it is about. Nobody reads a report and replies to you.

Three things it is not:

- **Not visitor analytics.** Nothing about the people who visit your sites is collected.
- **Not crash reporting for the applications you host.** Their errors stay yours.
- **Not a support ticket.** An automatic report does not open a conversation.

There are three kinds of report. **Deploy reports** are sent when a deploy finishes, including a successful one. **Health reports** are sent when the six-hourly check finds a site that is no longer serving itself; only broken ones are reported. **Bug reports** you file yourself, when the engine got something wrong. See [Bug reports](#bug-reports).

## Why it is there

A failed deploy on one server could be that repository. The same failure on thirty servers is a bug in detection, in a framework recipe, or in a known-app config. Without the reports, those two cases look identical, and the product cannot tell which apps to fix next.

The loop is: the engine reports what happened, PanelAlpha sees the shape of it across installs, and a platform fix ships as an update. The engine never edits your application. When *your* repository is the problem, that is still yours to fix: [When a deploy fails](what-happens.md).

## What it gives you

Three things, and none of them is a reply in your inbox.

**A product that improves from what happens on every install.** Repeating detection misses and recipe failures become something PanelAlpha can see. When that becomes a product fix, it arrives as an engine update, not as a reply to your report.

**A check that a site is still serving itself after the deploy already succeeded.** A deploy log is a photograph of one moment. It says nothing about the site a month later, after a database filled up, a certificate lapsed, or someone deleted the index page over FTP. Every six hours the engine opens each deployed application and lists the ones that are not serving themselves any more. It also checks that the public name for the project serves this application, not a different one. That sweep does not run when telemetry is fully off. You can still ask about any site whenever you want: [Monitoring and logs](../05-capabilities/monitoring-and-logs.md). To keep the sweep without sending anything off your VPS: [Keep the checks, send nothing](how-to-turn-it-off.md#keep-the-checks-send-nothing).

**A way to say the engine was wrong.** When a deploy "succeeded" and the engine mishandled the project, nothing automatic will catch it, because from the engine's point of view nothing failed. You file a bug report and the engine attaches the evidence. See [Bug reports](#bug-reports).

## What is sent, and what is not

Your VPS is identified by a fingerprint rather than by name: the engine combines a few facts about the machine and stores a one-way hash of them. The facts themselves never leave your VPS.

The installer also gives the engine a random ID, `APP_UID` in `.env-core`. It is sent in the `X-Engine-App-UID` header with every report and every request to PanelAlpha Connect, so both can tell one installation from another. It is not derived from anything on the machine. A value that is already set is never replaced, so you can choose it before installing.

A report contains what kind of application it was, how long things took, and the project's limits. It also includes the **public website names** the project answers on, such as `shop.example.com`. Those names leave your VPS in readable form. They are the addresses a visitor types, and they are how a report says which site it is about. A failed or partial deploy also includes which stage failed and the end of the deploy log, with sensitive values removed. A successful deploy is reported without that log.

**None of this is sent:**

- Project names, replaced with `<account>`
- Tokens, passwords, secrets, and email addresses found in logs
- Your VPS's own IP address or hostname in readable form
- Private repository names, which are hashed
- Filenames from your projects
- Your source code

Two addresses can identify a person, and only if you give them. One is a contact on a bug report you file yourself. The other is a notification email you set so monitoring can write to you when this engine or a site it checks looks down. Neither is filled in unless you type it.

There is a setting that allows a copy of your source to be attached to a failed deploy. It is **off**, and the only reason to turn it on is if PanelAlpha support asks you to during an investigation. Set it back afterwards.

## An email for alerts

Optional. Monitoring can write to you if this engine, or a site it probes, looks down. The address leaves your VPS.

```bash
pae telemetry:email set you@example.com
```

What this does: stores the address on this engine and sends it to monitoring.

What you should see: `Notification email set to you@example.com`, then a line that preferences were synced.

To read the address currently stored:

```bash
pae telemetry:email get
```

What you should see: the address, or `(not set)`. `pae telemetry:status` shows the same value as `Notify email`. Turning telemetry off tells monitoring to stop those emails: [How to turn telemetry off](how-to-turn-it-off.md).

To see whether sending is on, and what is queued:

```bash
pae telemetry:status
```

What this does: prints whether reports are being sent, and how many are waiting.

What you should see: `Sending reports: yes` on a default install. After `pae telemetry:disable`, `Sending reports: no`. The table also shows `Notify email` and how many site addresses monitoring is asked to probe.

To print one queued report exactly as it would leave your VPS:

```bash
pae telemetry:show
```

What this does: dumps one report from the queue, already redacted.

What you should see: the same JSON that would be posted to PanelAlpha. If the queue is empty: `Nothing queued.`

## Bug reports

Use these when your project is fine and the engine mishandled it. Typical cases: it identified your application as the wrong type, or it served a placeholder over a site that was complete.

Ask your assistant:

```text
File a bug: the engine treated this project wrongly. Here is what happened,
and here is what should have happened instead.
```

Or from the server:

```bash
pae telemetry:bug-report <project>
```

What this does: asks you for a title and a description, then gathers the evidence itself: what it identified your application as, what your site is actually returning, and the end of the last deploy log.

What you should see: `Bug report queued.` It is not sent immediately. It goes out with the next delivery, within five minutes.

Two options are worth knowing:

| Option | What it does |
|---|---|
| `--dry-run` | Shows exactly what would be sent, and files nothing. **Do this once before your first real report.** |
| `--contact you@example.com` | An address support can reply to. Nothing else in a report identifies anyone. |

**Filing is refused entirely if telemetry is switched off**, because nothing would ever deliver it. Turn it back on with `pae telemetry:enable`, or contact PanelAlpha at [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Where reports go

Reports are sent to `monitoring.panelalpha.com`, over HTTPS. If you set a notification email, that address is sent there too.

If that address cannot be reached, reports wait in a queue on your VPS rather than being thrown away.

## The local record

Every deploy is recorded in a log file inside the engine, **whether or not sending is switched on**. Turning telemetry off stops reports being *sent*. It does not stop that file being written.

## Turning it off

[How to turn telemetry off](how-to-turn-it-off.md). The usual command is `pae telemetry:disable`. It takes effect immediately.

## From the server

Status, enable, disable, the notification email, the queue, and filing a bug as `pae` commands: [CLI commands](../06-commands/pae-cli.md#telemetry-and-the-engine-itself).
