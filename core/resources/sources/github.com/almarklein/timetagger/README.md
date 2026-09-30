# TimeTagger (github.com/almarklein/timetagger)

Self-hosted time tracker (Python/uvicorn); each user's records are an SQLite
file under the data directory.

## Deploying

1. Generate a login at https://timetagger.app/cred: it gives `user:$2a$08$...`.
2. Set it as the project environment variable `TIMETAGGER_CREDENTIALS`, writing
   every `$` as `$$` (the value passes through compose interpolation; a hash
   whose salt starts with a letter is otherwise cut short). Several users:
   `alice:$$2a...,bob:$$2a...`.
3. Deploy, open the site and log in.

Without it the deploy fails with:

```
timetagger: TIMETAGGER_CREDENTIALS is not set. Generate "user:hash" at https://timetagger.app/cred ...
```

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/almarklein/timetagger:v26.1.3`
  on port 8080 with `/root/_timetagger` on the named volume `timetagger-data`
  (kept across redeploys).
- `credentials-check` (same image, one-shot) runs
  `files/timetagger-credentials-check.sh`; the app starts only once it passes.
