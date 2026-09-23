# Visitor statistics

The engine counts visits and transfer from the webserver's access logs for each hostname. Ask in chat. You do not download a log file to answer how much traffic a site had.

```text
How many visitors did shop.example.com have between 2026-09-01 and 2026-09-07?
```

```text
How much data did this project transfer between 2026-09-01 and 2026-09-30, by day?
```

```text
Break down shop.example.com's visitors by pages and browsers for September 2026.
```

Hits and visits follow the dates you name. Unique visitors, how long a visit lasts, and every breakdown are counted by calendar month. A range that sits inside a month still reports that whole month for those. Breakdowns are pages, browsers, operating systems, referrers, countries, continents, or regions.

Transfer counts every response, including crawlers, so it is higher than the visitor chart for the same days. Only traffic logged after you update is counted. Older logs are left out.

Country, continent, and region lists stay empty until a City database is on this VPS. That download is not part of install and it is not on a schedule. Visitor addresses are looked up on this VPS only. They are never sent to a public geolocation service.

Showing country charts still needs a visible credit: `IP Geolocation by DB-IP (https://db-ip.com)`. The download command prints that line when it succeeds, so you know the link belongs on any page that displays those results.

## From the server

Visitor counts, transfer, and the City database: [CLI commands](../06-commands/pae-cli.md#files).
