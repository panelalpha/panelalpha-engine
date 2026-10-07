# Example voting app (github.com/dockersamples/example-voting-app)

Docker's sample: `vote` (Python, the site), `result` (Node), `worker` (.NET
console, Redis to PostgreSQL), Redis and PostgreSQL.

- `overrides/docker-compose.yml` is upstream's `docker-compose.images.yml`: the
  published `dockersamples/examplevotingapp_*` images. The repository's own
  `docker-compose.yml` builds the `dev` targets with bind-mounted sources.
- `vote` publishes port 8080 and is the site. `result` runs but is not
  published; the repository ships no way to serve two sites from one project.
- Health checks are inline (`redis-cli ping`, `pg_isready`) instead of the
  repository's `./healthchecks` scripts, so nothing is bind-mounted.
