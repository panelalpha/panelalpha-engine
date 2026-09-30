# fx (github.com/rikhuijzer/fx)

A Twitter/Bluesky-like micro-blogging service (Rust, SQLite), on port 3000.

## Deploying

1. Set the project environment variable `FX_PASSWORD` to the admin password
   you want. Optionally set `FX_USERNAME` (default `admin`).
2. Deploy, open the site and log in at `/login` to write posts.

Without `FX_PASSWORD` the deploy fails with:

```
fx: FX_PASSWORD is not set. Set the project environment variable FX_PASSWORD ...
```

## What the recipe does

- `overrides/docker-compose.yml` runs `rikhuijzer/fx:1.6.3` (the current
  release) with `FX_DOMAIN` set to the site's host and the SQLite database
  in `/data` on the named volume `fx-data` (kept across redeploys).
- `password-check` (busybox, one-shot) refuses an empty `FX_PASSWORD`; the
  app starts only once it passes.
