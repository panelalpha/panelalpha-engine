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

## The firewall

The installer sets up ufw as the server's firewall. Incoming connections are refused, except on the ports the engine serves: SSH, 80 and 443 for the sites, 2011 for the engine itself, 21 and 30000-30009 for FTP, and 2222 for SFTP. Those rules are marked as the engine's, and your assistant cannot change or remove them. Outgoing connections are allowed.

```text
Show the firewall status and its rules.
```

You can list rules, add and remove them, and enable, disable or reload the firewall through your assistant. A rule allows or denies a port, an address, or both. A deny rule goes above every allow rule, so blocking an address works even on a port that is open to everyone. If the assistant cannot do this, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

```text
Block 203.0.113.7 from this server, both ways.
```

A rule applies to one direction, incoming or outgoing, unless you ask for both. A rule in both directions blocks (or allows) traffic from the address and to it, as one rule.

ufw keeps one rule for the same ports, addresses and direction. A new rule, or an edit, that matches what a rule already there matches is refused, whatever its action or comment, and the answer names that rule: change or delete it instead. A rule is also refused when ufw would store it as one with a rule already there: an incoming rule from an address beside an outgoing one to it, or a host deny beside a published-port deny, with the same action, ports and comment, is stored exactly like one rule in both directions, or one deny for both. Change the rule already there instead. To match any address, leave the source or destination out; `0.0.0.0/0` and `::/0` are refused. A rule's comment is one line without `'`. ufw would read a few comments as part of the rule, so they are refused with the reason: one that is only `in`, `out`, `log` or `log-all`, and, on a published-port rule or an incoming deny, `delete` or one with `in` or `out` followed by more words. A comment that starts with `by Fail2Ban` is refused too: that is how a ban is told apart, and deleting a ban also lifts it in fail2ban. An edit keeps such a comment only on a rule that has it already, and on a deny from an address only if that rule is a ban from the same address. The engine opens the port a proxy rule listens on, other than 80 and 443. If you already have a rule for that port from any address, such as a deny, it is left as it is, and the engine's log names it.

There are two kinds of rule. **Host rules** cover the server's own ports, such as SSH and the sites on 80 and 443: `ufw allow 22/tcp` on the server. **Published-port rules** cover the ports Docker publishes for a container, such as the engine's own 2011, FTP and SFTP: `ufw route allow proto tcp to any port 8080` on the server. On its own, ufw does not see published ports at all; the engine sends their traffic through ufw's route rules, and drops whatever those do not allow. Neither kind reaches the other: allowing 22 on the host does not open a container published on 22, and a published-port rule does not open a host port.

A published-port rule names the port inside the container, not the one on the server. A container started with `-p 28081:8080` is opened by a rule for 8080. The engine's own published ports have published-port rules marked as the engine's, which your assistant cannot change or remove.

```text
List the published-port rules. Allow 203.0.113.7 to reach published container port 8080.
```

Through the assistant or the API, an allow is a host rule unless you ask for published ports. A deny covers both kinds, as a ban does: blocking an address closes SSH and the sites, and the engine's 2011, FTP and SFTP and every published container port with them. It is listed once, as a rule for both, and deleting it lifts it everywhere. Ask for a deny on published ports only if that is all you want blocked. A deny you write with `ufw deny` on the server covers the host only; add `ufw route deny` for published ports.

A server updated from an engine without published-port rules keeps working: each allow rule that covered a port a container published at the time, and each incoming deny rule, gets a matching published-port rule, and the update log names each one.

fail2ban watches the logins the server takes: SSH, SFTP, FTP, and the engine's own API on 2011, where a request with a wrong or expired token counts as a failed login. After 5 failed attempts in 10 minutes (10 for the API, since a client with a stale token retries), an address is banned for an hour, and for longer each time it comes back. A ban closes every port to that address, published ports included. It is listed as one deny rule for both; delete it to lift the ban early.

Trust the addresses that must never be locked out: your office, your monitoring, the panel that drives this engine. A trusted address is never banned, and trusting it lifts a ban it has now. It is not an allow rule: the firewall rules still apply to it.

```text
Trust 203.0.113.7, that's our office. Show me the trusted addresses.
```

The firewall keeps a log of what it refused and whom it banned, newest first:

```text
What did the firewall block in the last hour? Was 203.0.113.7 banned?
```

A blocked entry is a connection refused because no rule allowed it, on any port, the ones Docker publishes included. The firewall writes at most a few of these a minute, so a flood shows as a sample. A connection dropped by a deny rule you added is not logged.

A server that ran CSF before is moved to ufw on its next engine update. Its allow and deny lists become ufw rules, and its configuration is saved in `/var/backups/panelalpha-csf-<date>.tgz`. The addresses it trusted are never banned by fail2ban. If CSF had been switched off, ufw stays off too. CSF's own automatic login blocks are not carried over.

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

Your own rules go in a third ruleset, `custom`, one rule file for the whole server. The assistant can write them from a description:

```text
Add a ModSecurity rule that blocks every request to /xyz with a 403, and switch the custom ruleset on.
```

The webserver checks the rules before they are saved. A rule it cannot parse is refused with the parser's message, and the rules already in place keep working. A misspelt operator such as `@beginWith` is refused too: ModSecurity itself would accept it and read it as a regular expression, so the rule would not do what it says. The check also runs the rules once: rules that would switch ModSecurity off or to detection-only for every site, stop the rules around them or the reading of request bodies, or deny an ordinary request, are refused, and so is `SecRuleEngine` or `ctl:ruleEngine` anywhere in them, since the mode is a setting of its own. A `skipAfter` must name a `SecMarker` that comes after it in your rules, and `skip` is refused: either could otherwise skip rules that are not yours. Each directive goes on its own line. Rule ids must be between 1100000 and 1199999, which keeps them apart from the OWASP set and the engine's own rules. A rule that parses can still block too much, so test it on one site first.

You can read the mode, switch rulesets on and off, write your own rules, and read the audit log. If the assistant cannot, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

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
