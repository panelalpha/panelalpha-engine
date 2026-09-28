# Connecting OpenClaw

OpenClaw registers the engine through its own command-line tool. `--transport streamable-http` is required.

## 1. Get the command on your VPS

```bash
pae connect openclaw
```

Run this on your VPS. It creates a token and prints the exact command, with your address and token already filled in.

## 2. Run that command on your computer

Paste what your VPS printed. Two lines: the skills plugin, then MCP. It looks like this:

```bash
openclaw plugins install engine --marketplace panelalpha/agent-skills
openclaw mcp add panelalpha-engine --url https://<host>:2011/mcp --transport streamable-http --header "Authorization: Bearer <token>"
```

The first line installs create and debug skills from the marketplace. Keep the quotes around the header, and keep the word `Bearer` with a single space after it. `--transport streamable-http` is required. Without it, OpenClaw tries SSE or stdio, which this engine does not speak.

## 3. Confirm it works

```bash
openclaw mcp doctor panelalpha-engine --probe
```

`panelalpha-engine` should connect and list tools. Then ask it something harmless:

```text
List the projects on this engine.
```

An empty list on a new engine is a success.

## Troubleshooting

**It registered, then will not connect.**
A self-signed certificate on your engine that your computer does not trust. Add the certificate to your operating system's trust store. Copy it first:

```bash
scp root@203.0.113.10:/opt/panelalpha/shared-hosting/crt/server.cert ~/panelalpha-engine.cert
```

The lasting fix is giving the engine a real certificate: [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate).

**401 Unauthorized.**
The token was truncated, or it is the wrong type. Assistants need a token from `pae connect`, not `pae api:token:create`.

**405 Method Not Allowed.**
`--transport streamable-http` was missing or set to something else.

**Connection times out.**
Port 2011 is not reachable from your computer. Check any firewall between you and your VPS, then confirm the engine is answering with `pae mcp:check` on your VPS itself.

**It connects but can barely do anything.**
The permission settings on your VPS are filtering its abilities. See [Decide what the assistant may do](your-assistant.md#decide-what-the-assistant-may-do).

**I want to start over.**

```bash
openclaw mcp unset panelalpha-engine
```
