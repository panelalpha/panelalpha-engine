<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Your AI agent running<br>your server. Under Control.</h1>

<h3>
Open source. Self-hosted. Easy for anyone, not just sysadmins.
</h3>

<h3>
<a href="#three-simple-steps-to-set-it-up"><b>Get started</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Documentation</b></a> ·
<a href="#talk-to-us-on-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#things-you-can-just-ask-for">AI control</a> ·
<a href="#everything-you-need-for-production-out-of-the-box">Capabilities</a> ·
<a href="#telemetry">Telemetry</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<b>English</b>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <a href="README.nl.md">Nederlands</a>
· <a href="README.es.md">Español</a>
· <a href="README.fr.md">Français</a>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#step-1-install-engine-on-your-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="One-line install"></a>
<a href="#step-1-install-engine-on-your-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Supported OS"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-182_tools-6f42c1" alt="182 MCP tools"></a>
<a href="#license"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Join Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Deploying a project by asking an assistant" width="760"></p>

</div>

---

## What is PanelAlpha Engine?

PanelAlpha Engine is software you install on your VPS to host AI-built and vibe-coded projects, websites and open source apps you found online. Once installed, you can connect your own AI agent directly to PanelAlpha Engine and let it handle deployments, maintenance and server management for you. Your server stays organized and under control. 

**But most importantly, it makes managing your own server/VPS super simple**. You don't have to be a sysadmin to self-host anymore! 

Out of the box, PanelAlpha Engine gives you and your AI everything you need to run real projects in production:

- Deploy any stack from Git or files
- Instant preview URLs with optional password protection
- Staging & Git workflows with separate live and staging environments
- Automatic backups & restore
- External monitoring & built-in visitor statistics
- Project isolation with separate Docker containers
- Firewall & OWASP/WAF protection with per-project access rules
- Domains, SSL, cron, FTP/SFTP, dDatabases, logs
- Easy Cloudflare integration for DNS, Tunnels and caching

## Why this needs to exist

AI has broken software creation out of its old limits. More people can turn ideas into working products, small teams can build much more than before, and open source is booming with projects worth making your own. What has not changed nearly as much is the work required to run it yourself. Most self-hosted tools still expect you to understand and manage Docker, a webserver, certificates, databases, backups, a firewall, and the updates that follow.

**PanelAlpha Engine** makes managing software in production as easy as AI made building it.

- **One command, once.** Then you talk to the AI assistant you already use.
- **You ask in plain words.** The engine builds the project, starts it, gives it HTTPS, and keeps it backed up.
- **The AI never gets root.** It works through the engine, inside rules you set.

[**Join the Discord**](https://discord.gg/9twHWR7xGX). That is the main place to ask, show what you deployed, and talk to the people who build this.

---

## Three simple steps to set it up

### Step 1: Install Engine on your VPS

You need a **fresh** server running Debian 12/13 or Ubuntu 22.04/24.04/26.04, with at least 2 GB RAM and 1 CPU, and you log in as `root` over SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

That is it. Your server is ready. Custom name, your own TLS certificate, or a server behind NAT: [Install options](docs/02-getting-started/install.md).

### Step 2: Connect your AI agent

At the end of the install the engine asks which assistant you use and prints the exact command to run on your own computer. To connect another one later, run this on the server:

```bash
pae connect
```

Run `pae connect` on the engine server, not on your laptop. The line it prints is what you run on your computer.

<div align="center">

<a href="docs/04-connecting-your-ai/claude-code.md"><img src="https://github.com/claude.png" width="46" alt="Claude Code"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/cursor.md"><img src="https://github.com/cursor.png" width="46" alt="Cursor"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/codex.md"><img src="https://github.com/openai.png" width="46" alt="Codex"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/gemini-cli.md"><img src="https://github.com/google-gemini.png" width="46" alt="Gemini CLI"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/vs-code-copilot.md"><img src="https://skillicons.dev/icons?i=vscode" width="46" alt="VS Code / Copilot"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/grok.md"><img src="https://github.com/xai-org.png" width="46" alt="Grok"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/opencode.md"><img src="https://opencode.ai/apple-touch-icon-v3.png" width="46" alt="OpenCode"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/windsurf.md"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/windsurf-white.svg"><img src="docs/assets/windsurf-black.svg" width="46" height="46" alt="Windsurf"></picture></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/pi.md"><img src="https://pi.dev/logo-auto.svg" width="46" alt="Pi"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/hermes.md"><img src="docs/assets/hermes.png" width="46" height="46" alt="Hermes"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/openclaw.md"><img src="https://github.com/openclaw.png" width="46" alt="OpenClaw"></a>

<sub><b>Claude Code&nbsp; · &nbsp;Cursor&nbsp; · &nbsp;Codex&nbsp; · &nbsp;Gemini CLI&nbsp; · &nbsp;VS Code / Copilot&nbsp; · &nbsp;Grok&nbsp; · &nbsp;OpenCode&nbsp; · &nbsp;Windsurf&nbsp; · &nbsp;Pi&nbsp; · &nbsp;Hermes&nbsp; · &nbsp;OpenClaw</b></sub>

</div>

Using something else? Any assistant that speaks MCP will work: [connecting other assistants](docs/04-connecting-your-ai/other-mcp-clients.md).

A token with default permissions can delete a whole project. You can hand out a read-only one instead, or take single tools away: [decide what the assistant may do](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Step 3: Done. Ask for what you want

Open your assistant's chat and say it the way you would say it to a person:

```text
deploy github.com/anna/invoicer to my server
```

> Done. Available at `invoicer.panelalpha.online`

Every project gets a free `panelalpha.online` address the moment it exists. When you are ready, ask for your own domain and the certificate comes with it.

Prefer to do it from the server yourself? [Deploy straight from git](docs/README.md#3-put-your-project-online).

---

## Things you can just ask for

No commands to learn, and no magic phrases either. These are examples of the level of detail worth giving:

| You say | What happens |
|:---|:---|
| `Deploy github.com/org/app on this engine.` | A project is created, the stack is detected, the app is built, started and given an address with HTTPS. |
| `Add shop.example.com to this project and get a certificate for it.` | The domain is attached and Let's Encrypt issues a certificate that renews itself. |
| `This site does not open. Read the deploy log and fix what you can.` | Your assistant reads the log, checks what the site actually serves, changes your code and tries again. |
| `Push it to staging first.` | A linked copy with its own address. Push it live when you are happy, in either direction. |
| `Install WordPress here, admin user anna.` | WordPress installed and ready, with WP-CLI available for everything after that. |
| `Create a MySQL database for this project and a user for it.` | Database, user and privileges, without you touching SQL. |
| `Roll back to yesterday's backup.` | The backup is restored, and the current state is saved first, just in case. |
| `Only let our office reach this internal tool.` | A firewall rule limiting that project to the addresses you name. |
| `Set up a Cloudflare tunnel for n8n.mydomain.com.` | DNS and the tunnel configured, which is also how you serve a site from a server behind NAT. |
| `How much traffic did we get last week?` | Usage, logs and limits for that project, and the server as a whole. |

More worked examples: [what to ask](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). The complete list of what your assistant can reach: [182 tools](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">One VPS. Multiple projects. Works with anything.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Your own tools, AI-built projects or open source software you found online. It does not matter: PanelAlpha Engine just runs it. A few applications get extra care because the general approach would get them wrong: WordPress, Matomo, phpBB, Magento and Passbolt. See the <a href="docs/07-supported-projects/project-types.md">full list</a>.
</p>

---

## Don't let AI turn your server into a mess

Giving AI full root access means open ports, random settings, and one project affecting another. PanelAlpha Engine sets the rules:

- **Less room for mistakes.** The AI works through the engine, not directly on the server.
- **A clear structure.** Projects, accounts and domains stay organized.
- **Project isolation.** Each one runs in its own Docker container, with its own limits on disk, memory and CPU, and only the ports it needs open.

What the assistant may do is something you decide before you connect it. [Decide what the assistant may do](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Security](docs/05-capabilities/security.md)

---

## Everything you need for production, out of the box

Deploy is only the beginning. Ongoing management is there from the first install:

- **See it online right away.** A free `panelalpha.online` hostname on every project, plus your own domains when you want them. Certificates come from Let's Encrypt and renew themselves. [Domains and HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Build on staging. Go live when ready.** A linked copy to break freely, then push live. You can push the other way too, to refresh the test copy with real data. [Projects](docs/05-capabilities/projects.md#staging)
- **Built-in monitoring.** Server metrics, per-site logs, Lighthouse reports on demand, and a check every six hours that each site is still serving itself rather than a blank page. [Monitoring and logs](docs/05-capabilities/monitoring-and-logs.md)
- **All the essentials covered.** Backups on the server and off it, SSL, domains, databases, FTP and SFTP logins, cron jobs and logs. [Backups](docs/05-capabilities/backups.md) · [Databases](docs/05-capabilities/databases.md) · [Files and access](docs/05-capabilities/files-and-access.md)
- **A safe space per project.** Docker isolation, a firewall, and ModSecurity with the OWASP rules. One broken app cannot take the others down. [Security](docs/05-capabilities/security.md)
- **Cloudflare in one connection.** DNS, tunnels and caching across your projects, including a site on a server behind NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## When a deploy fails, and how the engine gets better at it

Most first deploys work. The ones that do not are usually a repository problem. Most tools leave you with a stack trace. Here the path is:

- **You get a sentence, not a trace.** "This project needs PHP 8.2, but it was built with PHP 8.1." "The build ran out of memory." The full output is still underneath if anyone wants it.
- **Your assistant takes it from there.** It reads the log, looks at what the site is actually serving, fixes what is fixable in your code and deploys again. That work runs on the AI subscription you already pay for, not on API tokens.
- **The engine learns from it.** An anonymous report goes back to PanelAlpha. One failure on your server might be your repository. The same failure on thirty servers is a bug in detection or in a framework recipe, and that becomes a fix in the next update.
- **When the engine is the one that got it wrong**, say so and it collects the evidence: ask your assistant to file it, or run `pae telemetry:bug-report <project>`.

The whole procedure: [when a deploy fails](docs/02-getting-started/what-happens.md) · [what the error means](docs/02-getting-started/reading-errors.md).

---

## Telemetry

Telemetry is on after install. Anonymous reports about deploys and later health checks leave the server unless you turn them off. They include the public names of your sites.

- **Sent:** what kind of application it was, how long it took, the project's limits, and for a failure the stage that broke plus a redacted log tail. A health report only when a site later stops serving itself. A bug report only when you file one.
- **Never sent:** your source code, project names, tokens, passwords, your server's IP or hostname, private repository names, or anything about the people who visit your sites.
- **See a report before you decide:** `pae telemetry:status` and `pae telemetry:show`.

To stop sending:

```bash
pae telemetry:disable
```

What is collected, what is not, and every way to turn it off: [what is collected](docs/02-getting-started/what-is-collected.md) · [how to turn it off](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Why do I even need PanelAlpha Engine?</b></summary>

Because an application is not finished when the code is finished. Your project still needs somewhere to run, not to mention everything required to keep it healthy, reachable and safe. PanelAlpha Engine gives your AI assistant a proper way to handle that entire layer for you. You ask for the outcome you want, and the engine turns it into controlled server operations without giving the AI unrestricted access to the machine.

If you already know Docker, reverse proxies, firewalls and server logs, that knowledge still matters. PanelAlpha is not trying to hide the infrastructure from you or lock you out of it. It gives you a cleaner way to operate it, automate the repetitive parts and let AI take on real work without surrendering control. You can go as deep as you want when something deserves your attention, and skip the routine when it does not.
</details>

<details>
<summary><b>Do I need my own server?</b></summary>

Yes. PanelAlpha Engine is a tool for AI agents to manage *your* server. You need a fresh Debian or Ubuntu VPS with at least 2 GB RAM and 1 CPU, that you log into as `root` over SSH. You start by running the Engine installation on that VPS yourself. [What you need](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Which AI agents work with PanelAlpha Engine?</b></summary>

Any assistant that can connect to an MCP server with a token. There are step-by-step pages for [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code and Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) and [OpenClaw](docs/04-connecting-your-ai/openclaw.md), plus [anything else that speaks MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>What projects can I deploy with PanelAlpha Engine?</b></summary>

The goal is a universal tool for any project. Static sites, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, a plain `Dockerfile` or a Compose file. Your own tools, projects you built with AI, and open source software you found online. See [project types](docs/07-supported-projects/project-types.md), and if you want to check before committing, ask your assistant to inspect the repository first.
</details>

<details>
<summary><b>Is it free? What do I pay for?</b></summary>

PanelAlpha Engine is free and open source under the Apache 2.0 license. You pay for your server and your AI subscription, both of which you already have. Debugging a failed deploy runs on your assistant's subscription, not on API tokens.
</details>

<details>
<summary><b>Can I limit what my assistant is allowed to do?</b></summary>

Yes, and it is worth doing before you paste a token. You can give it a read-only token, allow changes but not deletions, expose only some areas, or deny individual tools such as `project_delete`. [Decide what the assistant may do](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>What is reported if a deploy fails?</b></summary>

An anonymous summary: the kind of application, the stage that broke, a redacted log tail, and the public names of the sites. Never your source, project names, credentials or your server's identity. You can print a queued report with `pae telemetry:show`, and turn the whole thing off. Full detail: [Telemetry](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Can I use PanelAlpha Engine for shared hosting?</b></summary>

Yes. Every project is a separate account with its own domains, databases, files and limits. Hosting providers have been running thousands of sites on it in production.
</details>

<details>
<summary><b>The engine got my project wrong. What now?</b></summary>

File a bug report and it gathers the evidence itself. Ask your assistant to file it, or run `pae telemetry:bug-report <project>` on the server. Use `--dry-run` first if you want to see exactly what would be sent.
</details>

---

## Talk to us on Discord

**Discord is the main place to reach us.** Ask a question, show us what you deployed, share an idea, or simply drop by and see what we are working on. We're right there in the middle of those conversations, and what you raise there can turn into the next thing we build, fix or rethink.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Talk to us on Discord, the main place to reach the PanelAlpha team" width="760">
</a>
</div><br>

Not really into Discord? [Our forum](https://community.panelalpha.com/) is just as open.

## Your server is one command away

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Security

An engine host is a single-purpose machine. The installer replaces the resolver and the firewall, so treat it that way.

Found a vulnerability? Please disclose it **privately**, never in a public issue. See [`SECURITY.md`](SECURITY.md), or use [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## License

PanelAlpha Engine is open source under the [Apache License 2.0](LICENSE).

## Come build with us

Want to get involved? [`CONTRIBUTING.md`](CONTRIBUTING.md) will get you started. For the deeper contributor conventions and testing workflow, head to [`AGENTS.md`](AGENTS.md).
