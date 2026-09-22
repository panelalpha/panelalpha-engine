# Looking after your project

Once a project is online, you keep using the same chat. Add a domain, take a backup, make a test copy, or ask why a page looks wrong. You do not switch to a control panel for this.

```text
List the projects on this engine.
```

What this does: the assistant shows every project on this VPS.

What you should see: names and addresses. Older versions called a project a "user"; `username` in output is still the project's name.

## Add your own domain

Point the DNS record at your VPS first, then ask:

```text
Add shop.example.com to this project and request a Let's Encrypt certificate for it.
```

Leave the domain out when you create a project unless it already points here. If you leave it out, the engine picks a public name and tells you what it chose. Full page: [Domains and HTTPS](../05-capabilities/domains-and-ssl.md).

To reach a project through Cloudflare instead of pointing DNS straight at the VPS: [Cloudflare](../05-capabilities/cloudflare.md).

## Take a backup, and restore if it goes wrong

```text
Create a backup of this project.
```

A restore overwrites the live project, so list backups first if you are unsure. Full page: [Backups](../05-capabilities/backups.md).

## Make a test copy

```text
Make a staging copy of this project.
```

You get a second project with its own hostname. Break it freely. When you are happy:

```text
Push the staging copy live.
```

You can also push live to staging to refresh the test copy with real data. A project can have one staging copy. Full page: [Projects](../05-capabilities/projects.md#staging).

## Rebuild after you change files

```text
Rebuild this project.
```

Use this after you push new commits, change files over FTP, or change an environment variable. A rebuild replays the last successful plan: [How a deploy works](how-a-deploy-works.md).

## See why a page is broken

```text
This project does not open properly. Read the deploy log, check what it is
actually serving, and tell me what is wrong.
```

The engine looks from inside the account and names what it found: [Monitoring and logs](../05-capabilities/monitoring-and-logs.md#what-the-engine-checks).

## Build caches

A deploy keeps copies of downloaded packages so the next deploy of that same project does not fetch them again. Once a day the engine deletes those copies for any project that has not deployed in the last 24 hours. A project that deploys every day keeps them. A deploy that is still running is left alone. The next deploy after a cleanup downloads the packages again. You do not have to ask for this. To run it yourself: [CLI commands](../06-commands/pae-cli.md#advanced-and-server-maintenance).

## Other day-to-day work

- **WordPress.** Ask for a new site without bringing your own code, or look after WordPress that is already on this engine: [WordPress](../05-capabilities/wordpress-and-apps.md).
- **A database**, FTP or SFTP, or a scheduled task the application needs: [Databases](../05-capabilities/databases.md) · [Files, FTP and SFTP](../05-capabilities/files-and-access.md) · [Projects](../05-capabilities/projects.md#cron-jobs).
- **Git.** Pull the latest commit, switch branch, or reconnect a private repository: [Connecting with Git](../05-capabilities/connecting-with-git.md).

Not sure what to say? [What to ask](../04-connecting-your-ai/your-assistant.md#what-to-ask) has more examples.
