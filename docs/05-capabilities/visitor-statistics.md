# Visitor statistics

The engine counts hits from host access logs. Bandwidth, pages, browsers, operating systems and referrers work as soon as logs are ingested. **Country, continent and region lists stay empty** until you fetch a local City database.

That fetch is a command you run yourself. It is not part of install, it is not on a schedule, and `pae stats:update` does not download it. Visitor IP addresses are looked up on this VPS only. They are never sent to a public geolocation API.

```bash
pae geolocation:database update
```

The first run asks you to accept [DB-IP](https://db-ip.com) terms for **IP to City Lite** (Creative Commons Attribution 4.0). In a script, pass `--accept-terms`. Without a terminal, the command refuses to wait for a prompt: pass `--accept-terms`, or it exits without downloading.

A later run with this month already on disk succeeds without downloading again. Pass `--force` to replace the file anyway.

Confirming terms allows the download. It does **not** replace the CC BY 4.0 duty to show a visible backlink where people see country charts. Engine JSON has no HTML; the command prints `IP Geolocation by DB-IP (https://db-ip.com)` on success so you know the link still belongs on any page that displays those results.

From the server: [CLI commands](../06-commands/pae-cli.md#files).
