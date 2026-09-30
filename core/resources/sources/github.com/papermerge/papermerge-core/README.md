# Papermerge DMS (github.com/papermerge/papermerge-core)

Document management for scanned documents: FastAPI core, auth server and
React UI behind nginx on :80, PostgreSQL for metadata.

## Deploying

1. Set the project environment variable `PAPERMERGE__AUTH__PASSWORD` (the
   password of the first login). Optionally `PAPERMERGE__AUTH__USERNAME`
   (default `admin`).
2. Deploy, open the site and log in.

Without it the deploy fails with:

```
papermerge: PAPERMERGE__AUTH__PASSWORD is not set. ...
```

The user is created once, on the first start; changing the variable later does
not change the password.

## What the recipe does

- `overrides/docker-compose.yml` runs `papermerge/papermerge:3.5.3` with
  `postgres:16-alpine`; the database and `/var/media/pmg` (uploaded documents)
  are named volumes kept across redeploys.
- `password-check` (one-shot) runs `files/papermerge-password-check.sh`; the
  app starts only once it passes. `ready` waits for the core API to answer.
- OCR, search and S3 workers are not started (optional upstream services).
