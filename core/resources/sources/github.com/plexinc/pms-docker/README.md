# Plex Media Server (github.com/plexinc/pms-docker)

Closed-source freeware media server; runs free, a Plex Pass is optional. The
repository is Plex's image packaging (Dockerfile + s6 scripts that install the
binary) and three compose *templates* with `<placeholders>`.

## Deploying

1. Get a claim token at https://plex.tv/claim (signed in to your Plex
   account). It is valid for **4 minutes** and works once.
2. Create the project with `env_vars: {"PLEX_CLAIM": "claim-..."}`, or set it
   on an existing project and rebuild.
3. Open `https://<domain>/web` and sign in with the same Plex account.

Without a claimed server the deploy fails with:

```
plex: the server is not claimed. Get a claim token at https://plex.tv/claim (valid 4 minutes), set it as the project's PLEX_CLAIM environment variable and redeploy.
```

and with an expired/used token:

```
plex: plex.tv did not accept PLEX_CLAIM (claim tokens expire after 4 minutes and work once). ...
```

Once claimed, the token lives in `Preferences.xml` on the `plex-config`
volume; later redeploys ignore the (expired) PLEX_CLAIM. If the server is ever
removed from the account, Plex clears its token and the next deploy asks for a
fresh claim.

## What the recipe does

- `overrides/docker-compose.yml`
  - `pms`: `plexinc/pms-docker:1.43.4.10903-e5521bd8c` (current release; the
    `latest`/`public` tags download the binary at start). Volumes:
    `plex-config:/config` (prefs, token, library DB, metadata),
    `plex-transcode:/transcode`, `plex-media:/data` (library root; add
    libraries under `/data/...`). `ADVERTISE_IP=https://<domain>:443` is
    published to plex.tv so Plex apps find the server at its public address.
    Not published; no GPU, no DLNA/GDM (UDP) ports.
  - `app`: nginx in front, the only published port (32400).
  - `plex-claim`: one-shot gate, passes once `/identity` says `claimed="1"`.
  - `ready`: no-op so `compose up -d` returns only after the gate.
- `files/plex-front.conf`: the front proxy (see below).
- `hooks/prepare.sh`: writes `plex-trusted-proxy.conf` with
  `set_real_ip_from <account default gateway>` (read from `/proc/net/route`),
  the hop the engine's proxy connects from, so Plex still sees the visitor's
  real address.

## Why the front proxy

Measured on 705f250a with the stock engine vhost and Plex alone:

- Plex honours X-Forwarded-For: an anonymous `X-Forwarded-For: 127.0.0.1`
  through the domain logged `Using X-Forwarded-For: 127.0.0.1 as remote
  address` / `(Loopback)`. It stayed 401 only because Plex also treats a
  Host it does not recognise (the domain) as non-local.
- Hosts Plex recognises (verified, peer on its subnet): IP literals,
  `[::1]`, `localhost`/`localhost.`, its container name, `*.plex.direct`,
  and an empty Host. From a container on the engine bridge,
  `Host: 172.18.0.2` + `X-Forwarded-For: 127.0.0.1` got **200** on
  `/library/sections` and `/:/prefs`, and `POST /myplex/claim` went through
  to `servers.plex.tv/api/claim/exchange` (403 only because the token was
  fake). Tenants cannot reach another account's port (PA-TENANT-EGRESS drops
  172.25.0.0/24), so this needs host-level access, but it shows what "local"
  unlocks.

The front replaces X-Forwarded-For with its own resolved address, maps every
recognised-as-local Host to `plex.invalid`, and returns 403 for
`/myplex/claim`. After it, the same probes are 401/403 in every combination.

Inside the account, `docker compose -p project exec pms curl
http://127.0.0.1:32400/...` is loopback and fully trusted - the equivalent of
Plex's "SSH tunnel to localhost" setup path.

## Not verified here

A real claim (needs a plex.tv account): sign-in through `/web`, library
browsing with the owner's token, playback. Verified: the gate's three paths
(unclaimed, rejected token, stubbed `claimed="1"`), anonymous rejection,
persistence of a library created through the loopback API across rebuilds.
