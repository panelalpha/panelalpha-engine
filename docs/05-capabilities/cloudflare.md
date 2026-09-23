# Cloudflare

Cloudflare lets visitors reach a site through Cloudflare's network instead of pointing a DNS record straight at your VPS. The engine opens an outbound connection, a **tunnel**, from the project's container to Cloudflare, and Cloudflare forwards visitors down that tunnel. Cloudflare provides the HTTPS certificate at its own edge, so for that name you do not request a Let's Encrypt certificate here and you do not open the site's port to the internet.

## When you want it

Use Cloudflare when your domain is already managed at Cloudflare and you would rather not point an A record directly at this VPS, or when you want Cloudflare's HTTPS and protection sitting in front of the site.

If you just want your own domain with a padlock and no Cloudflare in the middle, you do not need any of this. Point an A record at your VPS and use Let's Encrypt: [Domains and HTTPS](domains-and-ssl.md).

## Before you start

Three things must be true:

- The project already exists and is serving.
- The project was deployed from a repository, so it runs in its own container. A site on another kind of hosting account cannot use a Cloudflare tunnel.
- The hostname you want is in a zone in your Cloudflare account, and is not already in use on this engine.

You also need a **Cloudflare API token** with exactly two permissions:

- **Account · Cloudflare Tunnel · Edit**
- **Zone · DNS · Edit**

Create it in your Cloudflare dashboard, with the account and the zone selected under Resources. Both permissions are required. The engine's paste page repeats those steps when you open the link.

## What you do, and what the engine does

You do three things:

1. **Create the API token** in Cloudflare, as above.
2. **Save it on the project.** Do not paste the token into the chat.

   ```text
   Save a Cloudflare API token on this project.
   ```

   The assistant sends you a link on the engine. Open it, paste the token, select **Save secret**, then tell the assistant you are done. The token is never shown again, and the link lasts one hour. When that token is saved onto the project, Cloudflare is asked whether it works. A refusal means the token is wrong or missing a permission.

   To keep one Cloudflare token for every new project, instead of pasting it again on each one: [One token for the whole engine](connecting-with-git.md#one-token-for-the-whole-engine).

   <img src="../assets/connect-cloudflare.png" alt="Connect Cloudflare page: paste the API token and select Save secret" style="max-width: 100%; height: auto;">

3. **Attach the hostname.**

   ```text
   Attach shop.example.com to this project through a Cloudflare tunnel.
   ```

From there the engine does the rest for you:

- verifies the token and finds your Cloudflare account,
- creates a tunnel for the project (or reuses the one it already made) and starts the connector inside the project's container,
- finds the Cloudflare zone for the hostname and creates a DNS record there pointing that name down the tunnel,
- routes traffic for the hostname to your site.

## Check it worked

Open `https://shop.example.com/`. You should see your site, served with Cloudflare's certificate. That certificate belongs to Cloudflare, not to your project, and that is correct here.

To see what is attached:

```text
List the tunnel hostnames on this project's domain.
```

## Remove a hostname or the token

To stop serving a name through Cloudflare:

```text
Remove the Cloudflare tunnel hostname shop.example.com from this project.
```

This deletes the DNS record at Cloudflare. The project's own domain is untouched.

To clear the token, remove the hostnames first:

```text
Clear the Cloudflare API token on this project.
```

If tunnel hostnames still exist, clearing the token is refused, so you are not left with DNS records at Cloudflare the engine can no longer clean up.

## Troubleshooting

**"The project has no Cloudflare API token."**
Save the token first, then attach the hostname. Two steps, in that order.

**Saving the token fails.**
The token is missing one of the two required permissions, or it belongs to a different Cloudflare account than the one that owns the zone.

**"That hostname is already in use."**
The name already exists on this engine. Pick a free name in your Cloudflare zone, and do not add it as a normal domain first: attach it to the domain the project already has.

**"Cloudflare tunnels are not supported for this project."**
The site is not running in a container. Deploy it from a repository, or point an A record at your VPS and use Let's Encrypt instead.

**Visitors see a Cloudflare certificate, not my project's.**
Expected. HTTPS terminates at Cloudflare, so the project's own certificate is not the one visitors see.

**The paste link expired or says the link is unknown.**
Ask the assistant for a new one. Links last one hour.

**The assistant asked me to paste the token in the chat.**
Do not. Ask it to send a paste link on the engine instead.

## From the server

Saving a token and attaching or removing tunnel hostnames: [CLI commands](../06-commands/pae-cli.md#domains-and-https).
