# Connecting Claude Code

Claude Code is the one assistant the engine sets up for you. At the end of install it prints the exact commands, with your address and a token already filled in. Run those commands **on your own computer**.

Those commands install a small plugin. The plugin connects Claude Code to your engine and teaches it how to create a site and how to debug one that failed.

## 1. Run the commands your engine printed

If you still have the installer output, copy those two commands. They look like this:

```bash
claude plugin marketplace add panelalpha/agent-skills
claude plugin install engine@panelalpha --config "server_url=https://203.0.113.10:2011/mcp" --config "api_token=<token>"
```

**Copy the commands your VPS printed, not the ones above.** `203.0.113.10` is a placeholder address used throughout this guide. Each command is on its own line so the same paste works in bash and in PowerShell.

If you no longer have that output, create a fresh pair on your VPS:

```bash
pae connect claude
```

Then run what it prints on your computer.

## 2. Confirm it works

```bash
claude mcp list
```

`panelalpha-engine` should show as connected. Then ask Claude Code something read-only:

```text
List the projects on this engine.
```

An empty list on a fresh engine is a success.

## Troubleshooting

### Trust a self-signed engine certificate

Skip this if your engine has a real certificate. You will know because Claude Code connects without extra steps.

**Symptom.** Claude Code registers the server without complaint, then fails when it actually tries to connect:

```text
Failed to reconnect to panelalpha-engine: DEPTH_ZERO_SELF_SIGNED_CERT at https://203.0.113.10:2011
```

Registering only writes a setting to a file, so it cannot fail. The first real connection is where the certificate gets checked.

**Fix this on the machine running Claude Code, not on the engine.**

**1. Copy the engine's certificate to your computer.**

```bash
scp root@203.0.113.10:/opt/panelalpha/shared-hosting/crt/server.cert ~/panelalpha-engine.cert
```

Use your engine's real address.

**2. Tell Node about it.**

```bash
export NODE_EXTRA_CA_CERTS="$HOME/panelalpha-engine.cert"
```

This lasts only as long as the terminal window. To make it permanent, add it to your shell's startup file. `~/.bashrc` for bash, `~/.zshrc` for zsh:

```bash
echo 'export NODE_EXTRA_CA_CERTS="$HOME/panelalpha-engine.cert"' >> ~/.bashrc
```

**3. Open a new terminal and start Claude Code from it.**

The setting only reaches programs started after it was set. An already-running Claude Code will not pick it up.

**4. Check.**

```bash
claude mcp list
```

`panelalpha-engine` should now show as connected.

> **Do not use `NODE_TLS_REJECT_UNAUTHORIZED=0`.** It switches off certificate checking for every Node program in that terminal, not just for your engine.

A real certificate removes all of the above, for every computer you ever connect:

```bash
pae ssl:engine-cert:request --domain panel.example.com
```

See [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate). Once done, you can remove `NODE_EXTRA_CA_CERTS`.

Note that `pae mcp:check` passing on your VPS proves nothing about your own computer. The VPS already trusts its own certificate.

**It registers, then fails to connect, but without that exact message.**
Usually the same cause with different wording. Check the setting is visible in the terminal you start Claude Code from:

```bash
echo "$NODE_EXTRA_CA_CERTS"
```

Empty output means Claude Code never saw the certificate: you set it in a different terminal, or did not open a new one afterwards.

**401 Unauthorized.**
The token is wrong, or it is an API token rather than an MCP token. Create one with `pae connect claude`, not `pae api:token:create`.

**The engine appears twice in the tool list.**
You still have an old `claude mcp add` connection named `panelalpha` alongside the plugin (`panelalpha-engine`). Remove the old one first:

```bash
claude mcp remove panelalpha
```

Then run the plugin command again.

**Connected, but Claude cannot do much.**
The permission settings on your VPS are filtering abilities out. Check with `pae mcp:tool:list` on your VPS: [Decide what the assistant may do](your-assistant.md#decide-what-the-assistant-may-do).
