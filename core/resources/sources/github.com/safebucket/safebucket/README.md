# Safebucket (github.com/safebucket/safebucket)

File sharing platform: a Go API that serves its own web UI on :8080, with
uploads and downloads going directly between the browser and S3-compatible
storage through presigned URLs.

## Deploying

Nothing is required to start. Optional project environment variables:

- `SAFEBUCKET_ADMIN_EMAIL` (default `admin@safebucket.io`)
- `SAFEBUCKET_ADMIN_PASSWORD` (default `ChangeMePlease`, upstream's lite
  default). Safebucket re-applies the admin from these on every start.

## What the recipe does

- `overrides/docker-compose.yml` follows upstream's `deployments/local/lite`:
  `ghcr.io/safebucket/safebucket:0.7.5` with SQLite, in-memory cache and
  events, filesystem notifier and activity log; `rustfs/rustfs:1.0.0` as the
  bucket, created with its CORS rule by a one-shot `amazon/aws-cli` job.
- An nginx gateway (`files/panelalpha/safebucket-nginx.conf`) is the only
  published port: `/safebucket/` (the path-style presigned URLs, Host kept
  for the signature) goes to RustFS, everything else to the app, so the
  external S3 endpoint is the site's own address.
- `hooks/prepare.sh` generates the token and MFA secrets and the RustFS keys
  once into `~/.panelalpha/safebucket/`.
- App data (SQLite, notifications, activity) and objects are on named
  volumes.
