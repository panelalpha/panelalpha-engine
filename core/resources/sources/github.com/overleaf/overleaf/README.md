# Overleaf (github.com/overleaf/overleaf)

Overleaf Community Edition: collaborative LaTeX editing and compiling
(`sharelatex/sharelatex` image, MongoDB, Redis).

## Deploying

No project environment variables are needed. After the deploy, open
`/launchpad` on the site and create the first admin account there; that
admin then invites or creates the other users.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's compose with
  `sharelatex/sharelatex:6.3.0`, `mongo:8.2.12` and `redis:6.2`, without the
  Server Pro settings (sandboxed compiles need the Docker socket). The data
  directory, the database and Redis live on the named volumes `data`, `mongo`
  and `redis`, kept across redeploys.
- MongoDB runs as upstream's single-node replica set `overleaf` (Overleaf needs
  transactions), initialised on the first start by the repository's own
  `bin/shared/mongodb-init-replica-set.js`.
- `hooks/prepare.sh` generates `OVERLEAF_INVITE_TOKEN_SECRET` and
  `OVERLEAF_SESSION_SECRET` once into `~/.panelalpha/overleaf/` (0600). The
  image refuses to start without the first, and changing it invalidates every
  invite already sent. Without the second the image signs session cookies
  with a secret it generates inside each new container, so every rebuild
  logged everyone out.
- `OVERLEAF_SITE_URL` is the project's public address.
- `ready` makes `compose up -d` wait until Overleaf answers `/status`.

## Why MongoDB 8.2

The repository pins `mongo:8.0`. On Linux 6.19 and newer, MongoDB 8.0 below
8.0.35 and 8.3 below 8.3.14 exit at start ("Linux kernel versions 6.19 and newer
has a known incompatibility with this version of MongoDB", SERVER-121912), and
no such image is published yet. Overleaf 6.x refuses MongoDB below 8.0, so 7.0
is not an option. `mongo:8.2.12` starts on these kernels.

Data written by 8.2 upgrades forward to 8.3, so the recipe moves to 8.3.14 once
it is published. It cannot go back to 8.0 without first lowering the feature
compatibility version (`setFeatureCompatibilityVersion`) on an 8.x server.
