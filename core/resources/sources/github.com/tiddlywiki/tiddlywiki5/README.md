# TiddlyWiki 5 (github.com/tiddlywiki/tiddlywiki5)

Non-linear personal web notebook, served by TiddlyWiki's own Node.js server.

## What the recipe does

- The repository has no start script; Railpack runs `node ./boot/boot.js`,
  which exits at once. `overrides/docker-compose.yml` follows upstream's
  Node.js install instead, on `node:24-alpine`:
  - `install` (one-shot) runs `npm install tiddlywiki@5.4.1` (the current
    release) into the `tw` volume, once per version; a failure fails the deploy.
  - `app` creates `/data/wiki` from the `server` edition on first start
    (`--init server`) and serves it with `--listen host=0.0.0.0 port=8080`.
    `/data` is the named volume `data`, kept across redeploys; every edit is
    saved there as a `.tid` file.
  - `ready` makes `compose up` wait until `/status` answers.
- Upgrading: change `TIDDLYWIKI_VERSION`.

The server accepts anonymous reads and edits unless credentials are added to
the `--listen` line (`credentials=users.csv readers=... writers=...`); that is
left as upstream ships it.
