# LinkStack (github.com/linkstackorg/linkstack)

A link-in-bio page with a user and admin panel, served by Apache on :80.

## Deploying

No variables are required. The first visit opens LinkStack's own setup wizard
(`/installer`): choose SQLite or MySQL and create the admin account there.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's image
  `linkstackorg/linkstack` (v4.8.6, pinned by digest: upstream publishes only
  `latest`).
- `/htdocs` is the named volume `linkstack-htdocs`: the application, its
  `.env` and the SQLite database survive redeploys. Updating means changing
  the image and letting LinkStack's own updater migrate the volume.
- `FORCE_HTTPS=true`: TLS ends at the engine's proxy, and without it the pages
  reference `http://` assets.
