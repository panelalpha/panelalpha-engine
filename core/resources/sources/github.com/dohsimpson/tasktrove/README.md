# TaskTrove

To-do manager (Next.js) from upstream's published image
`ghcr.io/dohsimpson/tasktrove:v0.12.4`, the same shape as upstream's
`selfhost/docker-compose.yml`. The monorepo checkout itself is not built.

- Tasks are JSON files in `/app/data` (named volume `data`); automatic backups
  go to `/app/backups` (named volume `backups`). Both survive redeploys.
- Upstream leaves authentication off unless `AUTH_SECRET` is set; the recipe
  does not change that.
- To move to a newer release, bump the image tag in
  `overrides/docker-compose.yml`.
