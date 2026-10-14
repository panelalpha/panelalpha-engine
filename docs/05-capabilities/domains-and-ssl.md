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
2. **A `panelalpha.online` name**, if your VPS has a public IPv4. Works immediately with no DNS setup from you. Skipped when the engine is set to give new projects its own names instead (below).
3. **A name derived from the engine's own certificate.**
4. **`<project>.local`** - which resolves nowhere.

**A `.local` name is not a public website.** The application built and started correctly; it simply has no address the world can reach. This is why such deploys are marked `partial`. Attach a domain you control before putting more work into the site.

A `panelalpha.online` name is yours for as long as the project exists, and is released again when you remove it.

The server operator can switch the `panelalpha.online` step off for every new project. With the engine setting `default_tunnel` set to `none`, a project created without a name gets step 1 or step 3 above, and nothing is registered at PanelAlpha Online. The value `panelalpha`, or no value, keeps the order above. A request that names `panelalpha` or `none` itself always wins over the setting. Change it with `pae settings:set default_tunnel none`, or with `pae configure address`: [CLI commands](../06-commands/pae-cli.md#configuring-the-engine).

## Extra names for an app that needs them

Some applications answer on a few fixed names next to their main one, for example an API on its own hostname. On a `panelalpha.online` name, a project can add a few sibling names beside its own, such as `api-shop.panelalpha.online` next to `shop.panelalpha.online`. Each one has a trusted certificate, reaches the same site, and arrives with the name the visitor typed, so the application can tell them apart:

```text
Add api-shop.panelalpha.online as an extra name for shop.panelalpha.online.
```

This works for applications that let you choose those names (an `API_DOMAIN` setting or similar). An application that insists on a name *under* its own, such as `api.shop.panelalpha.online`, cannot get one on `panelalpha.online`. Give it a domain of your own, point its DNS at this server, and add the extra names (`api.shop.example.com`, ...) to the project as well, as addon domains, each with its own certificate.

## A preview address

To show a site before its domain points here, ask for a preview address. It is a `panelalpha.online` name that opens the site over HTTPS, and it stops working by itself after 24 hours unless you ask for another time (5 minutes up to 30 days):

```text
Give shop a preview address that lasts 3 days.
```

If you do not name the address, the engine picks one that starts with `preview-`. The site answers as itself, so an application tied to its real domain, WordPress for example, does not send visitors off to that domain. The browser stays on the preview name.

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

This is not the project's own PHP settings. Those belong to the project and, like these, apply only to traditional PHP hosting: [Change the PHP version](wordpress-and-apps.md#change-the-php-version).

## Extra routes

The engine already sends HTTP and HTTPS for your project hostnames to the right site. A **proxy rule** is extra routing on that public webserver: send a hostname or a port to a specific destination. Most sites never need one.

```text
List the proxy rules on this engine.
```

Do not add a rule that opens a public port for an application that already has a hostname. Traffic is supposed to arrive at the engine's webserver, which routes it into the right project: [Security](security.md#the-firewall).

Two rules cannot serve the same hostname on the same port: the second one is refused. A rule that belongs to a project can only send traffic to that project's own app, named by the project's name. The engine refuses any other destination: another project, an address, the engine's own services (its database server, its API, its image registries) or the server itself. Its hostname, if it has one, must be one of the project's own domains or aliases: a project cannot answer for another project's site, on any port. A rule that belongs to the engine (a system rule) is yours as the server's administrator and may point anywhere the server reaches, the engine's own services included. Its port is opened in the firewall like any other, so a system rule to the database server puts that database on the internet.

## Removing and renaming a domain

A project's main domain cannot be deleted. To change it, ask for it to be renamed. If the rename fails part way, the engine puts the routing back as it was. A subdomain remembers the domain it belongs to, so the domain above it cannot be removed while the subdomain exists: remove the subdomain first.

## Mail from a domain

Mail a site sends ends up in spam when its domain does not vouch for the server that sent it. The engine lists the SPF, DKIM, DMARC and MX records a domain needs for mail sent through this host, and checks which of them are published:

```text
Check the mail DNS records for shop.example.com.
```

Each record comes back as ok, missing, wrong or unknown, with the value to publish where the engine knows it. If the mail relay rewrites senders to its own sender domain, the records belong to that domain, and the answer says so.

The engine does not sign mail itself. A DKIM signature comes from a relay (SendGrid, Amazon SES, MailChannels or your own SMTP server) that signs for the domain. Give the assistant the relay's DKIM selector to check that record too.

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

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Replace `shop.example.com` with a name whose DNS already points at the test engine. Examples assume a project named `tshop`.

**Add a domain and get the padlock**

```text
Add the domain shop.example.com to tshop and request a Let's Encrypt
certificate for it.
```

Mode: modify. Tools: `domain_create`, `ssl_cert_request`, `ssl_cert_get`. Check: the domain is listed, the certificate is installed, and `https://shop.example.com/` opens with no warning.

**Dry run before the real request**

```text
Try the certificate request for shop.example.com against Let's Encrypt staging
first, without installing anything. Then do the real one.
```

Mode: modify. Tools: `ssl_cert_request` with `staging` or `dry_run`. Check: the dry run installs nothing, and the real request follows only after it passes. A name that does not resolve here fails with a DNS reason, and the assistant stops retrying (rate limit).

**Domain details and search**

```text
List the domains of tshop. Show the details of shop.example.com. Which project
owns the name www.shop.example.com, if any?
```

Mode: read. Tools: `domain_list`, `domain_get`, `domain_find`. Check: `domain_find` answers for the whole engine, not one project.

**Redirects, document root, aliases**

```text
On shop.example.com: force HTTPS, add the alias www.shop.example.com, and
redirect all other requests to https://www.shop.example.com.
```

Mode: modify. Tools: `domain_update` with `force_https_redirect`, `aliases`, `redirect_enabled`, `redirect_url`. Check: a request over plain HTTP is redirected, and the new setting reads back from `domain_get`.

**Subdomain and addon domain**

```text
Add the subdomain blog.shop.example.com to tshop, and the separate domain
second-example.com as an addon domain, without requesting certificates yet.
```

Mode: modify. Tools: `domain_create` with `type`, `parent_domain`, `no_ssl`. Check: both are listed with the right type, and neither has a certificate yet (`ssl_cert_list`).

**Certificates in one view**

```text
Which domains of tshop have a certificate, who issued it, and when does it
expire?
```

Mode: read. Tools: `ssl_cert_list`, `ssl_cert_get`. Check: one row per domain, with the expiry date.

**Your own certificate**

```text
Install my own certificate on shop.example.com. The certificate, the key and
the chain are the files in /project/certs of tshop.
```

Mode: modify. Tools: `file_download`, `ssl_cert_install` with `cert`, `key`, `ca`, `ssl_cert_get`. Check: the key is not echoed back in the answer, and `ssl_cert_get` shows the new certificate.

**Name under panelalpha.online, with sibling names**

```text
Give tshop a public panelalpha.online name with a trusted certificate, then add
api-tshop as an extra name beside it. Show me both.
```

Mode: modify. Tools: `tunnel_create` with `provider` panelalpha, `tunnel_list`. Check: both names open over HTTPS, and removing the extra name with `tunnel_delete` releases it so the label can be taken again.

**Preview address**

```text
Give tshop a preview address. Then show its tunnels, and when the preview
stops working.
```

Mode: modify. Tools: `tunnel_create` with `provider` panelalpha, `purpose` preview and optionally `ttl_minutes`, `tunnel_list`. Check: the name starts with `preview-`, the answer gives `valid_until` about 24 hours ahead when no lifetime was named, and the site opens on that name without redirecting to its own domain.

**HTTP-01 challenges by hand**

```text
For shop.example.com, create an HTTP-01 challenge with token test-token and
content test-content. List them, read that one, delete it, then delete all of
them.
```

Mode: full. Tools: `acme_challenge_create`, `acme_challenge_list`, `acme_challenge_get`, `acme_challenge_delete`, `acme_challenge_delete_all`. Check: each call reads back, and `acme_challenge_list` is empty at the end.

**Mail DNS**

```text
Check the mail DNS records for shop.example.com. My relay's DKIM selector is
s1.
```

Mode: read. Tools: `domain_mail_dns_get` with `check` and `dkim_selector`. Check: each of SPF, DKIM, DMARC and MX is reported as ok, missing, wrong or unknown, with the value to publish where the engine knows it. The engine does not claim to sign mail itself.

**PHP version and settings (traditional PHP hosting)**

```text
List the PHP versions available. Set shop.example.com to PHP 8.2, show its PHP
settings, set memory_limit to 256M while keeping the others, then read the
project's own PHP settings.
```

Mode: modify. Tools: `php_version_list`, `domain_php_version_get`, `domain_php_version_set`, `domain_php_directives_get`, `domain_php_directives_set`, `php_ini_get`, `php_ini_set`. Check: saving the directives with only `memory_limit` would drop the others, so the assistant sends the full set. On a container project the project-level PHP settings answer is a refusal with the reason.

**Extra routes**

```text
List the proxy rules on this engine. Add one that sends port 8081 to the
project tshop's app, change it to point at port 3000, and delete it.
```

Mode: full. Tools: `proxy_rule_list`, `proxy_rule_create`, `proxy_rule_get`, `proxy_rule_update`, `proxy_rule_delete`. Check: a rule that belongs to a project can only point at that project's own app, and any other destination is refused. The rule's port is opened in the firewall and removed again with the rule.

**Remove a domain**

```text
Remove www.shop.example.com from tshop.
```

Mode: full. Tools: `domain_delete`, `domain_list`. Check: the domain is gone and the main domain is untouched. Asking to delete the main domain, or a domain that still has subdomains, is refused with the reason.

## From the server

Adding hostnames, requesting certificates and attaching tunnels: [CLI commands](../06-commands/pae-cli.md#domains-and-https).
