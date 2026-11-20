# JupyterLab (github.com/jupyterlab/jupyterlab)

Web-based notebook environment. The repository is the JupyterLab source; its
`docker/Dockerfile` is a contributor dev image with no server command, so the
engine used to build it for ~15 minutes and then serve nothing.

## Deploying

Deploy the Git URL. Open the site: JupyterLab asks for a token. It is the
login the engine generates (`JUPYTER_TOKEN`, a token only, no username): read
it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
Set the project environment variable `JUPYTER_TOKEN` to choose it yourself.

## What the recipe does

- `overrides/docker-compose.yml` runs `quay.io/jupyter/minimal-notebook:lab-4.6.4`
  (Jupyter Docker Stacks, JupyterLab 4.6.4) on port 8888.
- The engine's token reaches the server as `env_file`
  (`../.panelalpha/app-credentials.env`); JupyterLab reads `JUPYTER_TOKEN`
  itself. It is the same on every start and the log shows it as `token=...`.
  Without it the server made a new random token on every start, printed only
  to the log.
- A password set with the login page's "Setup a Password" form is hashed into
  `~/.jupyter/jupyter_server_config.json` on the volume and kept; the token
  still signs in beside it.
- `/home/jovyan` is on the named volume `jupyter-home`, so notebooks, `pip install --user`
  packages and settings survive redeploys (not account deletion).
- For more libraries switch the image to `scipy-notebook` or `datascience-notebook`
  (same tag scheme), within the account's memory limit.

## Known limitation

Kernels talk to the browser over a websocket. The engine's vhost upgrades it
(`101 Switching Protocols` straight to the host), but the `*.panelalpha.online`
test edge does not: there the page loads and kernels start, yet
cells do not run. Use a real domain.
