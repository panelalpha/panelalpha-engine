# Connecting Cursor

Cursor is an editor. It has no install command like Claude Code, so the engine prints two ways to add it. A prompt you paste into a Cursor Agent chat does both the plugin and the connection. Or you add the connection yourself and then install the plugin. Pick one path.

The **plugin** is `engine` from [panelalpha/agent-skills](https://github.com/panelalpha/agent-skills). It teaches Cursor how to create a site and how to debug one that failed. It does not put your engine address or token into Cursor. That is a separate MCP connection.

## 1. Get both on your VPS

```bash
pae connect cursor
```

Run this on your VPS. It creates a token and prints a prompt, a configuration block, and the plugin step, with your address and token already filled in.

## 2. Add the engine to Cursor

Do this on your own computer. Use the prompt, or both of the steps after it.

### Paste the prompt into Cursor

Open a Cursor **Agent** chat, not Ask, and paste the sentence your VPS printed. That installs the plugin and adds the connection.

It looks like this:

```text
Add the GitHub marketplace panelalpha/agent-skills and install engine. Then add an MCP server named panelalpha-engine to ~/.cursor/mcp.json (all projects, not a project file) with url=https://203.0.113.10:2011/mcp and header Authorization: Bearer <token>. If that file already has other servers, add panelalpha-engine alongside them. Confirm panelalpha-engine is connected, then list the projects on this engine.
```

**Copy the prompt your VPS printed, not the one above.** `203.0.113.10` is a placeholder address used throughout this guide.

What this does: Cursor installs `engine` from `panelalpha/agent-skills`, then writes the engine into `~/.cursor/mcp.json` so it is available in every project.

What you should see: it installs the plugin, edits that file, then lists the projects on this engine. An empty list on a new engine is a success.

If Cursor asks for permission to write `~/.cursor/mcp.json`, allow it. That is the user file. A file inside a project risks committing your token to a repository.

If the plugin does not install from the chat, use **Install the plugin** below.

### Or add the connection yourself

Open **Settings**, then **Tools & MCP**, then **New MCP Server**. Cursor opens a configuration file for you to fill in. Paste the block your VPS printed.

It looks like this:

```json
{
  "mcpServers": {
    "panelalpha-engine": {
      "url": "https://203.0.113.10:2011/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

Use `~/.cursor/mcp.json` so the engine is available in every project. Putting the file inside a project risks committing your token to a repository.

If the file already has other servers in it, add `panelalpha-engine` alongside them rather than replacing the whole file.

Then install the plugin.

### Install the plugin

This is the `engine` plugin from `panelalpha/agent-skills`. Do this after adding the connection yourself, or when the prompt did not install it.

Open **Customize**, then **Plugins**. Import `https://github.com/panelalpha/agent-skills` and install `engine`. Reload the window.

The plugin adds the create and debug skills. It does not connect to your engine on its own. The connection is the prompt or the configuration block above.

## 3. Confirm it works

Open **Settings**, then **Tools & MCP**. `panelalpha-engine` should be listed, and it should show the abilities it picked up rather than an error.

Then ask it something harmless:

```text
List the projects on this engine.
```

An empty list on a new engine is a success.

## Troubleshooting

**It registered, then will not connect.**
A self-signed certificate on your engine that your computer does not trust. Cursor is built on Node, so the fix is the same as Claude Code: [Trust a self-signed certificate](claude-code.md#trust-a-self-signed-engine-certificate).

Cursor is normally launched from a desktop icon, so it will not see a certificate setting you added to `~/.bashrc` or `~/.zshrc`. Either start Cursor from a terminal where the setting is active, or set it at the operating-system level.

The lasting fix is giving the engine a real certificate: [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate).

**It wrote `.cursor/mcp.json` inside the project instead of `~/.cursor/mcp.json`.**
Move the `panelalpha-engine` entry to the user file and delete it from the project file. If that project file was committed, revoke the token: see **I committed my token to a repository** below.

**The plugin with create and debug skills did not install.**
Open **Customize**, then **Plugins**, import `https://github.com/panelalpha/agent-skills`, and install `engine`. Reload the window.

**`panelalpha-engine` does not appear at all after editing the file.**
The file has a syntax error, most often a missing or extra comma when adding to an existing config. Paste it into any JSON checker. Cursor tends to skip a broken file silently rather than complain.

**It appears but shows an error or no abilities.**
Restart Cursor first. A stale connection from before the edit looks exactly like this. If it persists, run `pae mcp:check` on your VPS.

**401 Unauthorized.**
The token is truncated, or it is the wrong type. Assistants need a token from `pae connect`, not `pae api:token:create`.

**It connects but can barely do anything.**
The permission settings on your VPS are filtering its abilities. See [Decide what the assistant may do](your-assistant.md#decide-what-the-assistant-may-do).

**I committed my token to a repository.**
Revoke it immediately on your VPS, then create a new one and add it again with `pae connect cursor`:

```bash
pae mcp:token:list
pae mcp:token:revoke <id>
pae connect cursor
```
