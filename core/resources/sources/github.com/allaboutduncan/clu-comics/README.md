# Comic Library Utilities (CLU)

Flask/gunicorn comic library manager on :5577, compose strategy.

- `overrides/docker-compose.yml` runs `allaboutduncan/comic-utils-web:v6.5.3`;
  upstream's compose is a hand-edit template (placeholder paths, docker.sock,
  an undeclared `config-volume`) that Compose rejects.
- Named volumes: `config` (settings + SQLite), `data` (library), `downloads`
  (folder monitoring), `cache` (thumbnails).
- Optional settings come from the project's env vars via `.env`.
- No login until users or `CLU_USERNAME`/`CLU_PASSWORD` are set (upstream default).
