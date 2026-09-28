# Connecting other assistants

Use this page when your assistant is not in the `pae connect` list. On your VPS, `pae connect` still creates a token. You then put four values into whatever settings screen your assistant uses.

On the **server that runs the engine**, run:

```bash
pae connect
```

It shows the assistants that have their own pages. Use the arrow keys, press Enter, and it prints the line for yours. Run or paste that **on your own computer**, not on your VPS.

Assistants with their own pages: [Connecting your AI](your-assistant.md#set-your-assistant-up). More on tokens: [Create a token](create-a-token.md).

Any assistant that can connect to an MCP server with a token will work, whether or not it is on that list.

## Setting up an assistant that is not listed

Four values, which go into whatever settings screen or config file your assistant uses:

| Field | Value |
|---|---|
| Server URL | `https://<host>:2011/mcp` |
| Transport | HTTP, sometimes labelled "streamable HTTP". Not stdio. |
| Authorization | A header: `Authorization: Bearer <token>` |

`<host>` is your engine's address, from the `MCP URL` line the installer printed. `<token>` comes from `pae connect` on your VPS.

It should list `panelalpha-engine` among its connected servers. Then ask it something harmless:

```text
List the projects on this engine.
```

An empty list on a new engine is a success.

## Troubleshooting

**I cannot tell whether the engine or the assistant is at fault.**
On your VPS, run `pae mcp:check`. If it passes, the engine is fine and the problem is in your assistant's configuration. If it fails, fix that first. This check cannot tell you whether *your computer* trusts the engine's certificate, because your VPS already trusts its own.

**It registered, then will not connect.**
A self-signed certificate on your engine that your computer does not trust. Fix it on your computer, not on the engine.

- **Node-based assistants** (Claude Code, Claude Desktop, Cursor, VS Code, Gemini CLI, Windsurf) read `NODE_EXTRA_CA_CERTS`. The steps are on [Trust a self-signed certificate](claude-code.md#trust-a-self-signed-engine-certificate).
- **Pi** can point at the certificate with `caFile` on your VPS entry: [Connecting Pi](pi.md).
- **Hermes** is not Node. Add the certificate to your operating system's trust store: [Connecting Hermes](hermes.md).
- **Anything else** needs the certificate added to your operating system's trust store.

The lasting fix is giving the engine a real certificate: [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate).

**401 Unauthorized.**
Either the token is a REST API token rather than an MCP token (check you used `pae connect`, not `pae api:token:create`), or it got truncated when you copied it. Create a fresh one and copy carefully.

**405 Method Not Allowed.**
Your assistant is not using HTTP transport. It needs HTTP, not an SSE-only or stdio connection.

**Connects, but has very few abilities.**
The permission settings on your VPS are filtering them. Run `pae mcp:tool:list` on your VPS: [Decide what the assistant may do](your-assistant.md#decide-what-the-assistant-may-do).

**Connection times out.**
Port 2011 is not reachable from your computer. Check any firewall between you and your VPS, and confirm the address is right with `pae mcp:check` on your VPS itself.
