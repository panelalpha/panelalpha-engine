# Connecting Grok

## 1. Get the command on your VPS

```bash
pae connect grok
```

Run this on your VPS. It creates a token and prints the exact command, with your address and token already filled in.

## 2. Run that command on your computer

Paste what your VPS printed. Skills first (two commands), then MCP. It looks like this:

```bash
grok plugin marketplace add panelalpha/agent-skills
grok plugin install engine --trust
grok mcp add --transport http panelalpha-engine https://<host>:2011/mcp --header "Authorization: Bearer <token>"
```

The first two commands install create and debug skills from `panelalpha/agent-skills`. `--trust` is required or Grok blocks them. Keep the quotes around the header, and keep the word `Bearer` with a single space after it. `--transport http` is required. Each command is on its own line so the same paste works in bash and in PowerShell.

## 3. Confirm it works

```bash
grok mcp list
```

`panelalpha-engine` should be listed. Then ask it something harmless:

```text
List the projects on this engine.
```

An empty list on a new engine is a success.

## Troubleshooting

**It registered, then will not connect.**
A self-signed certificate on your engine that your computer does not trust. The lasting fix is giving the engine a real certificate: [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate). If you cannot do that yet, copy the certificate to your computer and tell your system to trust it:

```bash
scp root@203.0.113.10:/opt/panelalpha/shared-hosting/crt/server.cert ~/panelalpha-engine.cert
```

If Grok is a Node-based tool on your system, pointing `NODE_EXTRA_CA_CERTS` at that file is enough: [Trust a self-signed certificate](claude-code.md#trust-a-self-signed-engine-certificate). Otherwise add the certificate to your operating system's trust store.

**401 Unauthorized.**
The token was truncated, or it is the wrong type. Assistants need a token from `pae connect`, not `pae api:token:create`.

**405 Method Not Allowed.**
`--transport http` was missing or set to something else.

**Connection times out.**
Port 2011 is not reachable from your computer. Check any firewall between you and your VPS, then confirm the engine is answering with `pae mcp:check` on your VPS itself.

**It connects but can barely do anything.**
The permission settings on your VPS are filtering its abilities. See [Decide what the assistant may do](your-assistant.md#decide-what-the-assistant-may-do).

**I want to start over.**

```bash
grok mcp remove panelalpha-engine
```
