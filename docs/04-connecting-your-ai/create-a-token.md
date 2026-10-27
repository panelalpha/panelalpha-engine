# Create a token

A **token** is a long password that lets your AI assistant talk to your engine.

If you have just installed the engine and you use Claude Code, you already have one. The installer printed a command that includes it. Paste that command on your computer and you can skip this page: [Connecting Claude Code](claude-code.md).

Use this page when you need a token for a different assistant, a second computer, or a replacement after you revoked one.

Do this on your VPS, logged in as `root`.

## 1. Ask the engine for your assistant's command

```bash
pae connect
```

It shows the assistants. Use the arrow keys and press Enter. For example, to skip the list and name Claude Code:

```bash
pae connect claude
```

The other names are `claude-desktop`, `codex`, `chatgpt-desktop`, `gemini`, `grok`, `opencode`, `vscode`, `cursor`, `windsurf`, `pi`, `hermes` and `openclaw`.

You should see a new token, shown once, and the exact command or prompt to use on your own computer. Copy both now. The engine cannot show the token a second time. If you lose it, create another.

## 2. Check that it works

```bash
pae mcp:check
```

Paste the token step 1 printed when it asks; nothing is shown as you type, so it stays out of your screen and your shell history. From a script, pipe it in instead: `printf %s "$TOKEN" | pae mcp:check --stdin`. It checks that HTTPS on your engine is working, that your token is accepted, and that an assistant can complete the connection.

## Managing tokens later

```bash
pae mcp:token:list
```

Lists your tokens by name and ID. The token values themselves are not shown.

```bash
pae mcp:token:revoke <id>
```

Stops a token working immediately. Use this the moment a token might have leaked. `pae mcp:token:delete <id>` removes it from the list altogether.

## A token limited to some projects

A token made with `pae connect` or `pae mcp:token:create` reaches every project on the engine. Software such as a control panel can instead ask the engine for a token limited to chosen projects. `pae` has no option for this, and `pae mcp:token:list` does not show it.

An assistant on a limited token sees and changes only those projects. A request that names another project is refused, project lists show only its own, and the tools that are about the whole server are not available to it, except reading general engine information (without the server's own name, system, kernel and uptime, and naming no other project) and inspecting a repository. It also cannot change a project's limits or suspend and unsuspend it, because those belong to you. A project it creates by cloning or by making a staging copy is outside its projects until the limit is updated.

The assistant activity log records which project each request named and which token made it. Software can filter it by project, by a list of token ids, and by part of a token name. A request keeps its token id after that token is deleted, so the calls of tokens a user has since removed still show up under their ids.

## Next

Set up your assistant: [pick yours](your-assistant.md#set-your-assistant-up).

If you would rather work from your VPS with no assistant at all, you do not need a token: [CLI commands](../06-commands/pae-cli.md).

## Troubleshooting

**A certificate error from `pae mcp:check`.**
Your engine is using a self-signed certificate. A pass on your VPS does not promise your own computer will connect. The fix belongs on the machine running the assistant: [Trust a self-signed certificate](claude-code.md#trust-a-self-signed-engine-certificate).

**A 401 error from `pae mcp:check`.**
The token was not accepted. Check you copied the whole string with no line break in the middle. Assistants need an MCP token from `pae connect` or `pae mcp:token:create`, not an API token from `pae api:token:create`.

**I want the setup line for every assistant at once.**

```bash
pae mcp:token:create laptop
```

`laptop` is a label so you can tell your tokens apart. This prints the token, then a registration command for each assistant. `--client=claude` prints only one. `--short` prints only the token.

**I need a token for a script or a control panel, not an assistant.**
That is a different kind of token. Create it with `pae api:token:create`. See [CLI commands](../06-commands/pae-cli.md).
