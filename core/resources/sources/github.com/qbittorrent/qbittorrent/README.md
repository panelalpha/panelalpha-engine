# qBittorrent (github.com/qbittorrent/qbittorrent)

BitTorrent client; this runs `qbittorrent-nox` and its Web UI.

## Deploying

Set one project environment variable:

- `QBT_LEGAL_NOTICE=confirm`: accepts qBittorrent's legal notice ("any content
  you share is your sole responsibility"). The image does not start without it.

Without it the deploy fails with:

```
qbittorrent: missing project environment variable QBT_LEGAL_NOTICE. ...
```

Sign in as `admin` with the temporary password qBittorrent prints in the
container log (`A temporary password is provided for this session: ...`), then
set a permanent one under Tools > Options > Web UI. Until you do, a new
temporary password is printed on every restart (upstream behaviour).

## What the recipe does

- `overrides/docker-compose.yml` runs the official
  `qbittorrentofficial/qbittorrent-nox:5.2.4-1` image, Web UI on port 8080.
  `/config` (settings, Web UI password, torrent state) and `/downloads` are on
  the named volumes `config` and `downloads`, kept across redeploys.
- `legal-check` (one-shot, `files/qbt-legal-check.sh`) fails the deploy until
  `QBT_LEGAL_NOTICE=confirm` is set; `ready` makes `compose up -d` wait until
  the Web UI answers.
- The torrenting port 6881 is not published: peers are reached outbound only,
  so the client is not connectable from the outside.
- Downloads count against the account's disk.
