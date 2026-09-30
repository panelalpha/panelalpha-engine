# UniFi Voucher Site

Guest Wi-Fi voucher page for UniFi (Node/Express, :3000), compose strategy.

- `overrides/docker-compose.yml` runs `glenndehaan/unifi-voucher-site:8.13.1`
  with the project's env vars from `.env`; upstream's compose hardcodes
  `UNIFI_TOKEN: ''` so nothing could be configured.
- Required: `UNIFI_TOKEN`; plus `UNIFI_SITE_MANAGER_CONSOLE_ID` when
  `UNIFI_SITE_MANAGER` is set. `env-check` fails the deploy naming them.
- Point `UNIFI_IP`/`UNIFI_PORT` at a controller the host can reach; the
  default 192.168.1.1 is the customer's LAN, not the account's.
- Stateless; login password is upstream's default (`AUTH_INTERNAL_PASSWORD`, 0000).
