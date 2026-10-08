# Connecting Cursor

`pae connect cursor` prints an install link for the MCP connection and a separate prompt for the `engine` plugin. Open the link on your computer to add the connection, then install the plugin.

The **plugin** is `engine` from [panelalpha/agent-skills](https://github.com/panelalpha/agent-skills). It teaches Cursor how to create a site and how to debug one that failed. It does not put your engine address or token into Cursor. That is a separate MCP connection.

## 1. Get the connection on your VPS

```bash
pae connect cursor
```

Run this on your VPS. It creates a token and prints an install link with your address and token already filled in, a manual configuration block, and a plugin-only prompt.

## 2. Add the engine to Cursor

Do this on your own computer, where Cursor is installed.

### Open the install link

Open the `cursor://anysphere.cursor-deeplink/mcp/install?...` link your VPS printed. If your terminal does not make it clickable, copy the complete link into your browser's address bar and allow it to open Cursor.

Cursor opens its MCP settings and asks you to install `panelalpha-engine`. Review the server address and confirm **Install**. The connection is added to your user configuration, `~/.cursor/mcp.json`, so it is available in every project. Other MCP servers remain in the file. If `panelalpha-engine` already exists, confirming the install replaces that entry with the new address and token.

The link uses Cursor's [MCP install-link format](https://prod.cursor.com/docs/mcp/install-links): a server name and a base64-encoded JSON configuration containing the URL and Authorization header.

**Keep the link private.** Base64 does not encrypt the token. Do not paste the link or the manual configuration into an Agent chat, commit it to a repository, or share it with someone else.

Then install the plugin below.

### Or add the connection manually

Open **Settings**, then **Tools & MCP**, then **New MCP Server**. Cursor opens a configuration file for you to fill in. Use the block your VPS printed.

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

Use your printed address and token; `203.0.113.10` is a placeholder. Use `~/.cursor/mcp.json` so the engine is available in every project. Putting the file inside a project risks committing your token to a repository.

If the file already has other servers in it, add `panelalpha-engine` alongside them rather than replacing the whole file.

Then install the plugin.

### Install the plugin

Open a Cursor **Agent** chat and paste the plugin-only prompt your VPS printed:

```text
Add the GitHub marketplace panelalpha/agent-skills and install engine.
```

This prompt installs only the create and debug skills. It contains no engine address or MCP token. The MCP connection is added through the install link or manual configuration above.

You can also open **Customize**, then **Plugins**. Import `https://github.com/panelalpha/agent-skills` and install `engine`. Reload the window.

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
