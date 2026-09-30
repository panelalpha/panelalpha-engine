# Gogs (github.com/gogs/gogs)

Self-hosted Git service. `main` is 0.15.0+dev, which removed the `/install`
page and exits at start without `custom/conf/app.ini`
("custom config \"/data/gogs/conf/app.ini\" not found").

The recipe runs `gogs/gogs:0.14.3` (current release) on port 3000 with `/data`
on the named volume `gogs-data`. The first visit shows Gogs's installer; pick
SQLite3 (or a database of your own) and set the Application URL to the site's
https address. The built-in SSH server listens inside the container only; clone
over HTTPS.
