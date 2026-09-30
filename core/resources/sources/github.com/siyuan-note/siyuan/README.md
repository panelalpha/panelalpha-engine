# SiYuan

<https://github.com/siyuan-note/siyuan> — personal knowledge management
(Go kernel, web UI).

## Required project environment variable

| Variable | Meaning |
|---|---|
| `SIYUAN_ACCESS_AUTH_CODE` | The lock-screen password protecting the workspace |
| `SIYUAN_ACCESS_AUTH_CODE_BYPASS=true` | Alternative: run without a password (upstream's explicit opt-out) |

In Docker the kernel exits at boot when neither is set
(`kernel/model/conf.go`: *"the access authorization code or a valid OIDC
configuration must be set when deploying via Docker"*). The `env-check`
service fails the deploy with a message naming the variable instead of
leaving a crash-looping container. OIDC is not wired into this recipe.

## What the recipe does

- `overrides/docker-compose.yml`: `b3log/siyuan:v3.8.6` (the image upstream
  builds from the repo's Dockerfile) with `serve --workspace=/siyuan/workspace/`
  on port 6806; the workspace is a named volume, so a rebuild keeps it. A
  no-op `ready` service gates `compose up -d` on `/api/system/version`.
- `files/siyuan-env-check.sh`: the one-shot check above.

The plain deploy builds the repo's Dockerfile, whose frontend build
(`pnpm run build`) is OOM-killed in a 2500 MB account.
