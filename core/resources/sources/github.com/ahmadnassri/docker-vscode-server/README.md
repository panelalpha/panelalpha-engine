# VS Code Server (github.com/ahmadnassri/docker-vscode-server)

Microsoft's VS Code CLI running `code serve-web`: the full editor in the
browser, on :8000.

## Deploying

No variables are required. Open the site to get the editor. Upstream starts
the server with `--without-connection-token`: there is no login, and anyone
who can reach the site gets the editor and its terminal.

## What the recipe does

- `overrides/docker-compose.yml` replaces upstream's compose, whose services
  are all behind profiles, with `ahmadnassri/vscode-server:2.1.0`.
- `server-data`, `user-data`, `cli-data` and `extensions` under
  `/root/.vscode` are named volumes, kept across redeploys. On the first start
  the CLI downloads the VS Code server build into `cli-data`.
