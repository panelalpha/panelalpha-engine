# code-server (github.com/coder/code-server)

VS Code in the browser, served on :8080.

## Deploying

No variables are required. Upstream's first start writes a random password to
`/home/coder/.config/code-server/config.yaml` inside the container, and the
login page asks for it:

    docker compose -p project exec app cat /home/coder/.config/code-server/config.yaml

Set `PASSWORD` in the project's environment to choose one instead.

## What the recipe does

- `overrides/docker-compose.yml` runs `codercom/code-server:4.139.1`; the
  repository ships no compose file and its source build needs the VS Code
  submodule and a C toolchain.
- `/home/coder` is the named volume `coder-home`: config, extensions and the
  workspace survive redeploys.
