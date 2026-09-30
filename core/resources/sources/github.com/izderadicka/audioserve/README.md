# audioserve (github.com/izderadicka/audioserve)

Personal audiobook / audio streaming server with a web client, one Rust binary
on :3000.

## Deploying

Set one project environment variable:

- `AUDIOSERVE_SHARED_SECRET`: the shared secret used to log in (upstream
  recommends 14+ characters; write any `$` as `$$`, compose interpolates it).

Without it the deploy fails with:

```
audioserve: missing project environment variable: AUDIOSERVE_SHARED_SECRET. ...
```

Audio files go into the `audiobooks` volume, mounted read by audioserve at
`/audiobooks`. Any other setting can be set as an `AUDIOSERVE_*` project
environment variable (upstream maps every command-line option to one).

## What the recipe does

- `overrides/docker-compose.yml` runs the official image of the current release
  (`izderadicka/audioserve:latest`, v0.30.1, pinned by digest) with `/audiobooks`
  as its collection and `/home/audioserve` (its `.audioserve` data: server
  secret, collection cache, playback positions) on named volumes kept across
  redeploys.
- The repository's Dockerfile is not built: its release + LTO cargo build and
  test run for ~13 minutes and then ran out of memory at a 2500 MB limit
  (`cannot allocate memory`), and its entrypoint passes no collection and no
  secret, so the binary would exit at start ("Shared secret is None, but no
  authentication is not confirmed").
- `env-check` (same image, one-shot) runs `files/audioserve-env-check.sh`; the
  app starts only once it passes. `ready` makes `compose up -d` wait for the
  web client to answer.
