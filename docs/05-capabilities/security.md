# Security

The engine host is a privileged machine by design. The engine controls every container on it, and the installer replaces the firewall and the domain-name resolver. Treat your VPS as single-purpose and do not run anything else on it.

This page covers an optional password on a project, the firewall, the web application firewall, and extra IP addresses you can assign to a project.

## Password protection

A project stays open until you set a password on it. Nothing asks for one on its own. Once you do, that password covers every address on that project. It does not cover the engine's own address, the one your assistant connects to.

```text
Set a password on this project so visitors have to type it before they see the site.
```

What you should see: the next visit shows a page with a password box. The words on that page are "This site is password protected." A correct password is remembered until the browser is closed. Setting a new password asks those visitors again.

```text
Remove the password from this project.
```

The site opens with no prompt.

The password box is the usual prompt. The whole engine can use the browser's own login window instead. Both accept the same password. In the browser window the name beside the password is ignored. That choice is one setting, `SITE_PASSWORD_AUTH_MODE`: `custom` is the page, and it is the default, and `basic` is the browser window. Changing a setting: [Install](../02-getting-started/install.md#change-a-setting).

A monitor that checks the site without sending the password gets "not allowed" and reports the site as down. Give the monitor the password.

Let's Encrypt can still confirm you control the domain. That check is not behind the password.

A Cloudflare tunnel on the project is checked the same way. This is enforced on the webserver a normal install uses.

## The most important setting on this page

**A token with default permissions can permanently delete an entire project and everything in it.**

Before you paste a token into any AI assistant, decide what that token is allowed to do, including which areas it can see: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

This is not really about AI. Any client holding that token has the same power. But an assistant is the one you will hand a token to most casually, so it is where the decision actually gets made.

Revoke a token the moment you suspect it has leaked:

```bash
pae mcp:token:list
pae mcp:token:revoke <id>
```

Revoking stops it working immediately while keeping it in the list, so you keep a record of what existed.

## The firewall (CSF)

CSF is the firewall installed alongside the engine.

```text
Show the CSF firewall status.
```

You can list rules, add and remove them, and enable, disable or restart the firewall through your assistant. If it cannot, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

> **Do not open a public port for an application.** It is a natural instinct and it is the wrong move here. Applications deliberately do not publish public ports - traffic is supposed to arrive at the engine's webserver, which routes it into the right sandbox. Opening a port bypasses that, and with it the routing, the HTTPS termination, and the isolation. If a site is unreachable, the cause is almost never the firewall: [What the engine checks](monitoring-and-logs.md#what-the-engine-checks). Extra HTTP routing belongs as a proxy rule, not a firewall hole: [Extra routes](domains-and-ssl.md#extra-routes).

## Extra IP addresses

If this VPS has more than one public IPv4 address, you can assign one to a project so that project's sites bind to it.

```text
List the IP addresses and subnets on this engine.
```

Most single-address servers never need this. If the assistant cannot manage addresses, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

## ModSecurity

ModSecurity is a web application firewall on the public webserver. It inspects incoming requests and blocks ones matching known attack patterns.

```text
Show me the ModSecurity audit log. A legitimate form submission was blocked.
```

Two rulesets ship: `owasp-crs`, the OWASP Core Rule Set, and `panelalpha-wordpress`, which stops an anonymous visitor from reading WordPress login names through `?author=1` or the REST users list. Both are off until you switch them on, and neither does anything while ModSecurity itself is off. A WordPress site on this engine is only covered once both are on.

You can read the mode, switch rulesets on and off, and read the audit log. If the assistant cannot, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

**When a legitimate request gets blocked**, that is a false positive and it is a tuning problem, not a broken deploy. The audit log names the specific rule that fired. Turn off that rule, not the whole firewall. Ask the assistant to find it:

```text
Read the ModSecurity audit log and tell me which rule blocked this request.
```

Disabling ModSecurity entirely to fix one form is a large step backwards for a small problem.

## Keep the engine updated

Security fixes reach your VPS through engine updates. An engine nobody has updated in a year is running a year-old version of everything it installed. [Updating](../02-getting-started/updating.md).

## Reporting a vulnerability

**Disclose privately. Do not open a public issue.**

Use [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

A failing deploy is not a vulnerability. That goes through [What happens when a deploy fails](../02-getting-started/what-happens.md).

## From the server

The project password, and the ModSecurity audit log: [CLI commands](../06-commands/pae-cli.md#security).
