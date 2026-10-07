# MinusPod (github.com/ttlequals0/minuspod)

Removes ads from podcast episodes: Whisper transcribes, an LLM finds the ads,
the cleaned feed is served back. Flask/gunicorn on :8000, SQLite under
`/app/data`, web UI at `/ui/` (upstream answers 404 at `/`).

## Deploying

The app starts with no settings. Optional project environment variables:

| Variable | Purpose |
|---|---|
| `MINUSPOD_SETUP_TOKEN` | Upstream requires a password before the API serves; the first one can only be set from the container itself or with this token in the `X-MinusPod-Setup-Token` header. Set it, set the password, then remove it. |
| `ANTHROPIC_API_KEY` / `OPENROUTER_API_KEY` / `OPENAI_BASE_URL` + `OPENAI_API_KEY` + `OPENAI_MODEL`, `LLM_PROVIDER` | Ad detection provider (can also be set in the UI). |
| `MINUSPOD_MASTER_PASSPHRASE` | Encrypts provider keys stored in the database. |
| `WHISPER_BACKEND=openai-api`, `WHISPER_API_BASE_URL`, `WHISPER_API_KEY`, `WHISPER_API_MODEL` | Remote transcription; local CPU Whisper (`WHISPER_MODEL`, default `tiny`) is slow. |
| `MINUSPOD_ALLOW_PUBLIC_PROCESSING` | Upstream default `false`. |

## What the recipe does

- `overrides/docker-compose.yml` replaces upstream's GPU compose with
  `ttlequals0/minuspod:2.97.36-cpu` on port 8000, `/app/data` on the named
  volume `minuspod-data` (kept across redeploys).
- No `cap_drop`/`cap_add`: the engine keeps upstream's `cap_drop: ALL` but
  strips its `cap_add`, which leaves the entrypoint unable to
  chown the data directory or drop to uid 1000.
- `MINUSPOD_TRUSTED_PROXY_COUNT=1` so login lockout and rate limits see the
  visitor's address (the last, proxy-added X-Forwarded-For hop) instead of the
  proxy's.
