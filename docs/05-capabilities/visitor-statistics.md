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

```text
Which HTTP status codes did shop.example.com answer with yesterday?
```

Hits and visits follow the dates you name. Unique visitors, how long a visit lasts, and every breakdown are counted by calendar month. A range that sits inside a month still reports that whole month for those. Breakdowns are pages, browsers, operating systems, referrers, countries, continents, or regions.

The status-code breakdown is the exception: it follows the dates you name and gives requests and bytes per HTTP status, so a spike of 502s shows on the day it happened. 200 and 304 share one row, because the logs are counted that way.

A date range can span at most ten years.

Transfer counts every response, including crawlers, so it is higher than the visitor chart for the same days. Only traffic logged after you update is counted. Older logs are left out.

Country, continent, and region lists stay empty until a City database is on this VPS. That download is not part of install and it is not on a schedule. Visitor addresses are looked up on this VPS only. They are never sent to a public geolocation service.

Showing country charts still needs a visible credit: `IP Geolocation by DB-IP (https://db-ip.com)`. The download command prints that line when it succeeds, so you know the link belongs on any page that displays those results.

## More requests to try

These need a site with some traffic. Mode is read for all of them ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)).

**How many came**

```text
How many visitors did shop.example.com have between 2026-09-01 and 2026-09-07?
```

Tools: `domain_visitors`. Check: unique visitors, hits and visits per day come back. A period with no data is zeros, not an error.

**Where from, with what**

```text
Break down shop.example.com's visitors by pages and by browsers for September
2026. Then by countries.
```

Tools: `domain_visitors_breakdown` with `dimension`. Check: the monthly nature of the numbers is mentioned. Countries are empty until the City database is stored, and the assistant says so rather than inventing a list.

**Status codes**

```text
Which HTTP status codes did shop.example.com answer with yesterday?
```

Tools: `domain_visitors_breakdown` with `dimension` status_codes. Check: hits and bytes per status, with 200 and 304 shared in one row.

**Transfer**

```text
How much data did shop.example.com transfer between 2026-09-01 and 2026-09-30,
by day? Then the same for the whole project tshop.
```

Tools: `domain_bandwidth`, `project_bandwidth` with `group_by`. Check: values are bytes, the project total is the sum of its domains, and transfer is higher than the visitor view because robots are included.

## From the server

Visitor counts, transfer, and the City database: [CLI commands](../06-commands/pae-cli.md#files).
