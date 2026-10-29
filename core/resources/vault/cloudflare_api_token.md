### Where to get a Cloudflare API token

Create one at **[My Profile → API Tokens](https://dash.cloudflare.com/profile/api-tokens)**
→ *Create Token* → **Create Custom Token**, with these two permissions:

- **Account** → Cloudflare Tunnel → **Edit**
- **Zone** → DNS → **Edit**

To purge the cache or change the security level from the engine, add two more:

- **Zone** → Cache Purge → **Purge**
- **Zone** → Zone Settings → **Edit**

Under **Account Resources** select the account, and under **Zone Resources**
the zone the tunnel will be on.

Paste the token below. It is stored **encrypted** on this server, used to create
and remove tunnel hostnames for your projects, and never shown back to anyone —
including the assistant that sent you here.
