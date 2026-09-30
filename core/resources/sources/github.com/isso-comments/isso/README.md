# Isso (github.com/isso-comments/isso)

Commenting server, a Disqus replacement: a site embeds
`<script src="https://<domain>/js/embed.min.js">` and its visitors' comments
are stored here.

## Deploying

Nothing is required. Set `ISSO_HOST` to the address of the site that embeds
the comments (e.g. `https://blog.example.com`); without it only this
domain's own `/demo/` page may post. `ISSO_TRUSTED_PROXIES` (one proxy IP)
makes Isso read the visitor's address from X-Forwarded-For; unset, as
upstream ships it, every visitor behind the same proxy shares one rate limit
(2 new comments a minute).

`/` answers 400 by design (it needs `?uri=`); `/demo/` is the app's own demo
page and `/info` its status. The admin page (`/admin/`) is disabled, as
upstream ships it.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's test compose with
  `ghcr.io/isso-comments/isso:0.14.0`, the SQLite database on the `isso-db`
  volume, and a no-op `ready` service that holds the deploy until `/info`
  answers.
- `files/isso.cfg` is mounted as `/config/isso.cfg`. Isso expands `${VAR}`
  in config values itself, so the compose file only passes the variables in.
