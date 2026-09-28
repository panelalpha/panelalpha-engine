# Connecting OpenCode

## 1. Get the command on your VPS

```bash
pae connect opencode
```

Run this on your VPS. It creates a token and prints the exact command, with your address and token already filled in.

## 2. Run that command on your computer

Paste what your VPS printed. It looks like this:

```bash
opencode mcp add panelalpha-engine --url https://<host>:2011/mcp --header "Authorization=Bearer <token>"
```

OpenCode's header uses an equals sign, `Authorization=Bearer`, where every other assistant uses a colon and a space. Copy the line your VPS printed. Do not copy a command from another page and change the tool name.

## 3. Confirm it works

```bash
opencode mcp list
```

`panelalpha-engine` should be listed. Then ask it something harmless:

```text
List the projects on this engine.
```

An empty list on a new engine is a success.

## Troubleshooting

**It registered, then will not connect.**
A self-signed certificate on your engine that your computer does not trust. The lasting fix is giving the engine a real certificate: [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate). If you cannot do that yet, copy the certificate to your computer:

```bash
scp root@203.0.113.10:/opt/panelalpha/shared-hosting/crt/server.cert ~/panelalpha-engine.cert
```

A Node-based install reads `NODE_EXTRA_CA_CERTS`: [Trust a self-signed certificate](claude-code.md#trust-a-self-signed-engine-certificate). Otherwise add the certificate to your operating system's trust store.

**401 Unauthorized, and the command looked right.**
Check the header separator first. `Authorization=Bearer <token>` is correct for OpenCode; `Authorization: Bearer <token>` is not. If the separator is right, the token is either truncated or the wrong type. Assistants need one from `pae connect`, not `pae api:token:create`.

**405 Method Not Allowed.**
The connection was set up as something other than HTTP.

**Connection times out.**
Port 2011 is not reachable from your computer. Check any firewall in between, then confirm the engine is answering with `pae mcp:check` on your VPS itself.

**It connects but can barely do anything.**
The permission settings on your VPS are filtering its abilities. See [Decide what the assistant may do](your-assistant.md#decide-what-the-assistant-may-do).

**I want to start over.**

```bash
opencode mcp remove panelalpha-engine
```
