# UISP (nico640/docker-unms)

Ubiquiti UISP from the all-in-one `nico640/docker-unms:3.1.65` image.
Closed-source freeware, no licence key needed.

| Service | Role |
|---|---|
| `uisp` | the whole of UISP; HTTPS-only on 443, publishes nothing |
| `setup` | one-shot: completes the first-run setup with the generated admin |
| `app` | nginx, HTTP 80 -> `https://uisp:443` (websockets included) |
| `ready` | ends the deploy once `app` is healthy |

- Admin: `admin`, password in `~/.panelalpha/uisp/admin.env` (see `credentials.txt`).
- Data: `uisp-config` volume (`/config`). Survives redeploys, not account deletion.
- Devices connect to `wss://<domain>:443` (the connection string in UISP settings).
- Not published: NetFlow (UDP 2055), optional.
- On `*.panelalpha.online` test domains the edge strips `Upgrade`
  (panelalpha/engine#170), so the live UI and device websockets only work on a
  real domain or straight to the host; HTTP pages and the REST API are fine.
