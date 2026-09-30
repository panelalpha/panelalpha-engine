# legit

Minimal git web frontend (Go), deployed on the engine's `go` platform.

- `/var/www/git` (upstream's `repo.scanPath`) is the named volume `legit-repos`.
  Put bare repositories there; legit lists them (it does not traverse subdirs).
- Configuration lives in the `legit-data` volume as `/data/config.yaml`, seeded
  once from the repository's `config.yaml` with `server.name` set to the
  account's domain. Edit it there; it survives redeploys.
- Clone over HTTPS shells out to `git`, which the runtime image does not ship
  (upstream's own `contrib/Dockerfile` runs `FROM scratch` and lacks it too);
  browsing works, `git clone` from the site does not.
