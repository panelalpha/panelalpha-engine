# Sshwifty (github.com/nirui/sshwifty)

Web SSH and Telnet client. One Go binary: the UI on `:8182`, and a websocket
(`/sshwifty/socket`) over which the **server** dials whatever SSH/Telnet host
the browser names. It is a relay: anyone who can use the socket can open TCP
connections from this account's network.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's source build with
  the official `niruix/sshwifty:0.4.11-beta-release` image.
- `hooks/prepare.sh` generates `SSHWIFTY_SHAREDKEY` once into
  `~/.panelalpha/sshwifty/sharedkey.env` (0600, dir 0700) and never rewrites
  it, so the key survives redeploys.
- The app's entrypoint refuses to start if the key is missing or shorter than
  32 characters: no key means no service, never an open relay.
- `ready` gates `compose up` on the app's health check.

## Using it

Open the site and enter the value of `SSHWIFTY_SHAREDKEY` from
`~/.panelalpha/sshwifty/sharedkey.env` as the password. Then connect to any
SSH/Telnet host with that host's own credentials.

## Security notes

- The shared key is checked on the websocket itself: the socket's AES-GCM
  framing is keyed from it, so a client without the key is disconnected before
  any command runs (`cipher: message authentication failed` in the app log).
- Destinations are not restricted. To pin them, add `SSHWIFTY_PRESETS` (JSON)
  and `SSHWIFTY_ONLYALLOWPRESETREMOTES=yes` to the env file and redeploy.
- To rotate the key: edit `sharedkey.env` and redeploy.
- On `*.panelalpha.online` test names the shared edge strips the websocket
  `Upgrade` header (engine#170), so the page loads and the key is accepted but
  connections fail. On the account's own domain it works.
