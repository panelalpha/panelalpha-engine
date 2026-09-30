# Datasette (github.com/simonw/datasette)

Explore and publish SQLite databases through a web UI and JSON API.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's build (its
  Dockerfile needs a `VERSION` build argument and listens on 127.0.0.1) with
  the official `datasetteproject/datasette:0.65.5` image on port 8001.
- Serves `/data/data.db`, created empty on first start, from the named volume
  `data` (kept across redeploys). Put further SQLite files in that volume and
  add them to `command:` to publish them.
- A no-op `ready` service makes `compose up` wait until `/-/versions.json`
  answers.

Datasette has no login of its own; anything in the published databases is
readable by anyone who can reach the site.
