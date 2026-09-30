# JupyterLab (github.com/jupyterlab/jupyterlab)

Web-based notebook environment. The repository is the JupyterLab source; its
`docker/Dockerfile` is a contributor dev image with no server command, so the
engine used to build it for ~15 minutes and then serve nothing.

## Deploying

Deploy the Git URL. Open the site: JupyterLab asks for a token.

- Set the project environment variable `JUPYTER_TOKEN` to choose the token, or
- leave it unset and read the generated one from the app log
  (`http://127.0.0.1:8888/lab?token=...`); it changes on every restart.

## What the recipe does

- `overrides/docker-compose.yml` runs `quay.io/jupyter/minimal-notebook:lab-4.6.4`
  (Jupyter Docker Stacks, JupyterLab 4.6.4) on port 8888.
- `/home/jovyan` is on the named volume `jupyter-home`, so notebooks, `pip install --user`
  packages and settings survive redeploys (not account deletion).
- For more libraries switch the image to `scipy-notebook` or `datascience-notebook`
  (same tag scheme), within the account's memory limit.

## Known limitation

Kernels talk to the browser over a websocket. The engine's vhost upgrades it
(`101 Switching Protocols` straight to the host), but the `*.panelalpha.online`
test edge does not (engine#170): there the page loads and kernels start, yet
cells do not run. Use a real domain.
