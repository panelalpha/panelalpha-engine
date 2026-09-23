# Domains and HTTPS

Every project has at least one hostname - the address visitors type. This page is about making that a name you own, with a working padlock.

> **Two different certificates, do not confuse them.** Your *sites* have certificates, and *the engine itself* has one for its API and its connection to your AI assistant. This page is about site certificates. The engine's own is on [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate). A common source of confusion is a site working perfectly in a browser while an assistant still cannot connect, or vice versa - different certificates, different problems.

## Adding your own domain

**Point DNS at your VPS first.** Let's Encrypt issues a certificate by checking that it really reaches your VPS at that name. If the name does not resolve here yet, the request fails - and there is nothing the engine can do about it.

1. At your DNS provider, create an **A record** for the name (for example `shop.example.com`) pointing to your VPS's public IPv4 address.
2. Wait for it to take effect. Check with `nslookup shop.example.com` - it should return your VPS's address. This can take minutes or, on some providers, hours.
3. Then ask your assistant:

```text
Add the domain shop.example.com to this project and request a Let's Encrypt
certificate for it.
```

Open `https://shop.example.com/` in a browser. You want a padlock with no warning.

## The name you get if you do not supply one

If you create a project without naming a domain, the engine picks the best public name it can. It tries, in order:

1. **`<project>.<your base domain>`**, if you have configured a base domain for sites. The best option - you control it.
2. **A `panelalpha.online` name**, if your VPS has a public IPv4. Works immediately with no DNS setup from you.
3. **A name derived from the engine's own certificate.**
4. **`<project>.local`** - which resolves nowhere.

**A `.local` name is not a public website.** The application built and started correctly; it simply has no address the world can reach. This is why such deploys are marked `partial`. Attach a domain you control before putting more work into the site.

A `panelalpha.online` name is yours for as long as the project exists, and is released again when you remove it.

## Reach a site through Cloudflare

Instead of pointing a DNS record at this VPS, you can have visitors reach a repository-deployed site through Cloudflare, with Cloudflare providing HTTPS. You save a Cloudflare API token on the project, then ask the assistant to attach the hostname:

```text
Attach shop.example.com to this project through a Cloudflare tunnel.
```

The full process, including the exact token permissions, what the engine does automatically, and how to check and remove it, is on its own page: [Cloudflare](cloudflare.md).

## PHP version, on traditional PHP hosting

Repository deploys pick their PHP version from your `composer.json`, so this does not apply to them. On traditional PHP hosting, you set it per domain. If the assistant cannot change it, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

```text
List the available PHP versions, then set shop.example.com to PHP 8.2.
```

## PHP settings, on traditional PHP hosting

The same limit applies: repository deploys do not use these. On traditional PHP hosting, each domain can have its own PHP settings, such as `memory_limit`.

```text
Show the PHP settings for shop.example.com.
Set memory_limit to 256M on shop.example.com, and keep the other PHP settings
that are already there.
```

Saving replaces the whole set for that domain. If you name only `memory_limit`, the other settings on that domain are removed. Asking to clear them removes the set.

This is not the project's own PHP memory setting. That one belongs to the project, and WordPress in its own container uses it: [Change the PHP version](wordpress-and-apps.md#change-the-php-version).

## Extra routes

The engine already sends HTTP and HTTPS for your project hostnames to the right site. A **proxy rule** is extra routing on that public webserver: send a hostname or a port to a specific destination. Most sites never need one.

```text
List the proxy rules on this engine.
```

Do not add a rule that opens a public port for an application that already has a hostname. Traffic is supposed to arrive at the engine's webserver, which routes it into the right project: [Security](security.md#the-firewall-csf).

## Troubleshooting

**The certificate request fails with a DNS error.**
The A record is not pointing here yet. Confirm with `nslookup shop.example.com` from somewhere other than your VPS. Also check nothing is blocking port 80 - Let's Encrypt uses it to verify ownership, even though your site will run on 443.

**The browser warns that the connection is not private.**
Either DNS is not pointing here yet, or the certificate was never issued. Confirm DNS first, then ask the assistant to request the certificate again.

**The browser trusts my site, but my AI assistant still cannot connect.**
Different certificates. Your site has a good one; the engine itself does not. See [Install](../02-getting-started/install.md#if-connecting-asks-you-to-trust-a-certificate).

**Visitors see a different certificate than the one on the project.**
Normal when the site is reached through a `panelalpha.online` name or through Cloudflare. HTTPS terminates at that layer, so the project's own certificate is not the one visitors see.

**Anything about Cloudflare tunnels or the Cloudflare token.**
Covered on the [Cloudflare](cloudflare.md#troubleshooting) page.

**"Let's Encrypt rate limit."**
Let's Encrypt caps how many new certificates you can get per week. If you have been retrying a failing request, stop, fix the DNS, and wait - retrying burns the allowance without getting you a certificate.

## From the server

Adding hostnames, requesting certificates and attaching tunnels: [CLI commands](../06-commands/pae-cli.md#domains-and-https).
