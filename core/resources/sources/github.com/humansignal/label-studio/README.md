# Label Studio (github.com/HumanSignal/label-studio)

Web-based data labeling and annotation tool (Django/uwsgi).

## Deploying

Nothing to set. Open the site and sign up; the first account is an ordinary
user of a fresh instance (Label Studio's own first-run behaviour).

## What the recipe does

- `overrides/docker-compose.yml` runs `heartexlabs/label-studio:1.23.1` on port
  8080 with `/label-studio/data` (SQLite, uploads, the generated secret key) on
  the named volume `labelstudio-data`, kept across redeploys.
- `LABEL_STUDIO_HOST` and `CSRF_TRUSTED_ORIGINS` are the public URL.
- `ready` makes `compose up -d` wait for `/health`.
- The repository is not built: develop's `uv.lock` pins a `label-studio-sdk`
  commit that GitHub no longer serves, and the repo compose bind-mounts
  `./mydata`.
