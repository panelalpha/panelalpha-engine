# PanelAlpha Engine

> **This documentation covers PanelAlpha Engine version 2.0+.** This version of Engine is not yet supported by the PanelAlpha Single Server and Multi Server control panels. They still use PanelAlpha Engine v1.x, and you can find its documentation [here](https://www.panelalpha.com/documentation/panelalpha-engine/v1/).

## What is PanelAlpha Engine?

PanelAlpha Engine is software you install on your VPS to host AI-built and vibe-coded projects, websites and open-source apps you found online.

Once installed, you can connect your own AI agent directly to PanelAlpha Engine and let it handle deployments, maintenance and server management for you. Your server stays organized and under control.

But most importantly, it makes managing your own server/VPS super simple. **You don't have to be a sysadmin to self-host anymore!**

Out of the box, PanelAlpha Engine gives you and your AI everything you need to run real projects in production:

- Deploy any stack from Git or files
- Instant preview URLs with optional password protection (`project:set-password` / `project:unset-password`; API `PUT|DELETE /projects/{username}/password`). Host-wide UX via `SITE_PASSWORD_AUTH_MODE=custom|basic` (nginx-proxy). External monitors should send HTTP Basic credentials — a bare probe gets 401 and is treated as down.
- Staging and Git workflows with separate live and staging environments
- Automatic backups and restore
- External monitoring and built-in visitor statistics
- Project isolation with separate Docker containers
- Firewall and OWASP/WAF protection with per-project access rules
- Domains, SSL, cron, FTP/SFTP, databases, logs
- Easy Cloudflare integration for DNS, Tunnels and caching

All it takes is just [three simple steps to set it up](#getting-started).

<img src="assets/panelalpha-engine.gif" alt="Deploying a project by asking an assistant" style="max-width: 100%; height: auto; margin-top: 0.5em; margin-bottom: 1.5em;">

<!-- TODO screenshot: this GIF should show a full deploy driven from an assistant chat, cropped to the chat window and the returned project address. Keep the frame no wider than the text column so it does not overflow on mobile. -->

## Getting started

### 1. Install on your VPS

You need a **fresh** VPS, `root` access over SSH, and one command. Full requirements and options are on the [Install](02-getting-started/install.md) page; the short version is:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

A few minutes later the engine is running and prints its addresses and a command to connect your assistant. Want a custom name, your own TLS, or a VPS behind NAT? Read [Install](02-getting-started/install.md) first.

### 2. Connect your AI assistant

Connecting happens on **your own computer**, not on your VPS. After install you get a screen like this, with a ready-made command for Claude Code. Copy that command and run it on your computer.

<img src="assets/installer-success.jpg" alt="Installer finished: copy the Claude Code command it printed" style="max-width: 100%; height: auto; margin-top: 1.5em; margin-bottom: 1.5em;">

If you use a different assistant, run this on your VPS. It prints the exact line to run on your computer, with your address and a token already filled in:

```bash
pae connect
```

<img src="assets/pae-connect-picker.png" alt="pae connect on your VPS: arrow keys to choose an assistant, Enter to connect" style="max-width: 100%; height: auto; margin-top: 1.5em; margin-bottom: 1.5em;">

> **Run `pae connect` on your VPS, not on your own computer.**

Each assistant has a setup page with how to check it worked and what usually goes wrong: [Connecting your AI](04-connecting-your-ai/your-assistant.md).

**Before you connect**, decide what the assistant may do. By default it can permanently delete an entire project. `pae configure` on the VPS walks through that, and through the address, the queue, and telemetry: [Configure the engine](02-getting-started/configure-the-engine.md). The settings it writes are listed under [Decide what the assistant may do](04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### 3. Put your project online

Open your assistant's chat and describe what you want. A **project** is one application or website on this VPS. Each one gets its own isolated space, files, address and database.

- **A new WordPress project**

  ```text
  Set up WordPress on a new project on this PanelAlpha Engine.
  ```

- **An application from a public git repository**

  ```text
  Deploy my application https://github.com/org/app on this PanelAlpha Engine.
  ```

- **A private repository.** Tell the assistant it is private. Do not put the access token in the chat. The assistant sends a page where you paste a git token: [Connecting with Git](05-capabilities/connecting-with-git.md).

  ```text
  Deploy https://github.com/org/private-app on this PanelAlpha Engine.
  The repository is private.
  ```

- **A zip of files, not a repository**

  ```text
  Upload this archive into the project and deploy it: /path/to/app.zip
  ```

You should get a web address back. Open the address it gives you. If the deploy failed, or came back "partial": [When a deploy fails](02-getting-started/what-happens.md). What happens during a deploy, in order: [How a deploy works](02-getting-started/how-a-deploy-works.md).

## How to use it

The main way to run PanelAlpha Engine is to connect an AI assistant and describe what you want in chat. You can also run commands on the VPS, or call the same operations from your own software.

- **[Connecting your AI](04-connecting-your-ai/your-assistant.md)**: Connect an assistant after install and drive deployments, maintenance and server management from chat.
- **[CLI commands](06-commands/pae-cli.md)**: `pae` on your VPS over SSH, and the REST API for software you build on top of the engine.
- **[Getting Started](02-getting-started/install.md)**: Install, configure the engine, update, uninstall, how a deploy works, looking after a project, telemetry, and failed deploys.
- **[Supported projects](07-supported-projects/project-types.md)**: Stacks the engine can run, what your repository needs, and how detection works.
- **[Capabilities](05-capabilities/connecting-with-git.md)**: Git, projects, backups, domains, databases, files, WordPress, monitoring, visitor statistics, security and Cloudflare.
