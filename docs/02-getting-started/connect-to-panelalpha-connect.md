# Connect to PanelAlpha Connect

PanelAlpha Connect is the customer portal; the engine reaches it at `https://hub.panelalpha.com`. Once your engine is connected to your Connect account, Connect shows its status, version, server capacity, CPU/RAM/disk usage, your applications, usage charts and monitoring events. If you allow it, Connect can also open a one-time admin login into your apps.

A license is optional. You can connect without one and attach a license later in Connect.

## Before you start

- Connect must be able to reach your engine at `https://<your server's public IP>:2011`. Allow inbound TCP 2011 from the internet, or at least from Connect.
- The server must be able to open `https://hub.panelalpha.com` (set `PANELALPHA_HUB` in `.env-core` to use another).
- The server clock must be roughly right.

## Connect

1. In Connect, click **Connect engine** (Licenses page). Pick a license or none, and the permissions.
2. Connect shows a command with a one-time code. Run it on your VPS within 10 minutes:

```bash
pae hub:connect WDJB-MJHT
```

The engine connects straight away and shows what it connected to:

```
Connect code accepted by https://hub.panelalpha.com.

Account: Acme Ltd (code created by j***@acme.com)
Permissions:
  - Read the engine version, host details and resource usage
  - Read the list of applications and their status
  - Read usage statistics
  - Read your projects' backup history
Warning: the license is registered for a different IP address
Connected to Acme Ltd.
Not your account? Disconnect with: pae hub:disconnect
```

Connect then shows "Engine connected". A code works once. If it has expired or been used, create a new one.

**Run only a command you created yourself.** A code connects the engine to the account that created it, without asking. If the account shown is not yours, run `pae hub:disconnect`.

| Option | What it does |
|---|---|
| `--ip=203.0.113.10` | The address Connect should use, when the server's main IPv4 is not the public one. It must be a public address. |
| `--replace` | Connect an engine that is already connected. The old connection is dropped only once the new one works. |

### Install and connect in one step

Pass the code to the installer and the new engine connects as soon as it is up:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh -s -- --hub-code WDJB-MJHT
```

On a server that already runs an engine, the same command only connects it; add `--update-engine` to update the engine first. It works together with `--repo`. An engine that is already connected is left as it is: connect it elsewhere with `pae hub:connect CODE --replace`.

A code lasts ten minutes from when you create it, and a fresh install takes a few of them, so create the code just before you start the installer. If the connect fails, the engine is still installed: the installer prints why, and you connect with a new code and `pae hub:connect CODE`.

## What Connect can do on your server

Connect gets an API token limited to a handful of read-only routes: the engine version and host details, CPU/RAM/disk usage and its history, the project list with each project's status, and each project's backup history. It works out monitoring events itself from what it reads. It cannot deploy, change settings, read files or credentials, or use the assistant (MCP) interface.

Admin login into apps is a separate permission, off unless it was ticked when the code was created. With it, Connect may also list an app's users and ask for a one-time login link. Every call Connect makes is recorded; `pae hub:status` shows the last ones.

Connect replaces its token every week by itself, and the token expires after 30 days if it stops doing so.

## Check and disconnect

```bash
pae hub:status
```

Shows the account, address, permissions, when the token expires, and the last 20 calls Connect made (admin logins highlighted).

```bash
pae hub:disconnect
```

Revokes Connect's access immediately, then tells Connect. It works even when Connect cannot be reached.

## When it does not work

| Message | What to do |
|---|---|
| `The Hub could not reach https://…:2011` | Open inbound TCP 2011, or pass the right public address with `--ip`. |
| `… is not a public address` | The server's main address is private (NAT). Pass the public one with `--ip`. |
| `That code is not valid any more` | The code expired, was cancelled or was already used. Create a new one in Connect. |
| `The code expired` | Create a new code in Connect and run the command within 10 minutes. |
| `Denied on the Hub.` | The code was cancelled in Connect. Create a new one. |
| `This engine is already connected to …` | Run the command again with `--replace` to move it. |
| `The certificate on :2011 changed during connect` | Run the command again. |

When the engine's certificate is renewed with a new key, the engine tells Connect before it starts serving the new certificate. If Connect still reports a certificate problem, create a new code in Connect and run `pae hub:connect CODE --replace`.
