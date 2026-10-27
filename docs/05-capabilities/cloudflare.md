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

You also need a **Cloudflare API token**. A tunnel needs these two permissions:

- **Account · Cloudflare Tunnel · Edit**
- **Zone · DNS · Edit**

If you also want to purge the cache or change the security level from the engine, add these two to the same token:

- **Zone · Cache Purge · Purge**
- **Zone · Zone Settings · Edit**

Create it in your Cloudflare dashboard, with the account and the zones selected under Resources. The engine's paste page repeats those steps when you open the link. A token with only the first two drives tunnels and DNS; the cache and security endpoints answer that the token is missing a permission until you add the other two.

## What you do, and what the engine does

You do three things:

1. **Create the API token** in Cloudflare, as above.
2. **Save it on the project.** Do not paste the token into the chat.

   ```text
   Save a Cloudflare API token on this project.
   ```

   The assistant sends you a link on the engine. Open it, paste the token, select **Save secret**, then tell the assistant you are done. The token is never shown again, and the link lasts one hour. When that token is saved onto the project, Cloudflare is asked whether it works. A refusal means the token is wrong or missing a permission.

   To paste one Cloudflare token once and use it on several projects: [A token shared by several projects](connecting-with-git.md#a-token-shared-by-several-projects).

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

## Purge the cache and change the security level

Cloudflare caches your site at its edge. After a content change you can ask it to drop what it holds, and you can raise or lower how aggressively it screens visitors.

- **Purge everything.** Clears the whole cache for the zone the domain is in. The next visitor gets a fresh copy from the engine. Refused while another project on this engine has a name in the same zone (the project's own staging copy does not count): purge single files then.
- **Purge single files.** Clears up to 30 URLs, for example the stylesheet and the page that changed. Each URL must be on one of the project's own names: its domains, their aliases or its tunnel hostnames.
- **Security level.** One of `off`, `essentially_off`, `low`, `medium`, `high`, or `under_attack`. `under_attack` is the "I am under attack" mode: every visitor waits a few seconds before the site answers. Set it back to a normal level when the attack is over. The level applies to the whole zone, so it is refused while another project on this engine has a name in the same zone (the project's own staging copy does not count); change it in the Cloudflare dashboard then.

Each action names one of the project's domains or aliases, and works on the Cloudflare zone that exact name is in: an alias in another zone acts on that zone, not on the main domain's.

The engine reads the current value first, so a page can show the level before you change it. The same page reports whether the domain's DNS record is proxied through Cloudflare; if it is not, visitors are going straight to the engine and the cache is not in the way.

These actions use the project's Cloudflare API token, and only a project deployed from a repository into its own container can hold one, as for tunnels. Traditional PHP hosting cannot use them. They are refused until the token has **Zone · Cache Purge · Purge** and **Zone · Zone Settings · Edit**. A refusal names the permission the call needed: reading the status needs Zone Settings Read and Zone DNS Read, which the **Edit** permissions above include.

## Check it worked

Open `https://shop.example.com/`. You should see your site, served with Cloudflare's certificate. That certificate belongs to Cloudflare, not to your project, and that is correct here.

To see what is attached:

```text
List the tunnel hostnames on this project's domain.
```

## Remove a hostname or the token

A hostname stays until you remove it, unless you gave it a lifetime when you attached it ("for 7 days", from 5 minutes to 30 days). The engine then removes it, including the DNS record at Cloudflare. For a short-lived address without a Cloudflare account, ask for a preview address instead: [A preview address](domains-and-ssl.md#a-preview-address).

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

**A cache purge or security-level change says Cloudflare is rate-limiting the token, or could not be reached.**
Nothing was changed. Wait a few minutes and try again. A missing permission is named instead, and a request Cloudflare refused for another reason carries Cloudflare's own message.

**Visitors see a Cloudflare certificate, not my project's.**
Expected. HTTPS terminates at Cloudflare, so the project's own certificate is not the one visitors see.

**The paste link expired or says the link is unknown.**
Ask the assistant for a new one. Links last one hour.

**The assistant asked me to paste the token in the chat.**
Do not. Ask it to send a paste link on the engine instead.

## More requests to try

These need a Cloudflare account with a zone and a token with the two permissions named above. **Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)).

**Save the token without pasting it**

```text
Save a Cloudflare API token on tshop. Send me the page to paste it, do not ask
for it here.
```

Mode: modify. Tools: `vault_secret_create`, `project_setting_set`, `project_setting_get`. Check: the token is verified against Cloudflare when stored, the account it belongs to comes back, and a later read shows the token redacted.

**Attach, check, remove**

```text
Attach shop.example.com to tshop through a Cloudflare tunnel. Show the tunnel
hostnames on that domain. Then remove the hostname.
```

Mode: full. Tools: `tunnel_create` with `provider` cloudflare, `tunnel_list`, `tunnel_delete`. Check: the site opens through Cloudflare, and after the delete the DNS record and ingress rule are gone.

**The token cannot be cleared under live tunnels**

```text
Clear the Cloudflare token on tshop while a tunnel hostname still exists.
```

Mode: full. Tools: `project_setting_delete`. Check: the call is refused, and the assistant offers removing the hostnames first. `force` is used only when asked for.

## From the server

Saving a token and attaching or removing tunnel hostnames: [CLI commands](../06-commands/pae-cli.md#domains-and-https).
