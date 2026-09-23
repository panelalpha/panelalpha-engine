Geeftlist
=========

A self-hosted collaborative gift-list manager (PHP / Fat-Free Framework, MariaDB,
Redis). This recipe runs the author's own published image
`nanawel/geeftlist:latest` behind a MariaDB + Redis stack, modelled on upstream's
production guide (`support/docker/production/`).

Getting in
----------
There is **no seeded admin account** — Geeftlist has no admin interface, and the
first person to register is an ordinary user. Open the site and register the
first account for yourself. Registration is left **enabled** because the app is
collaborative (family members you invite must register too); the signup CAPTCHA
is kept on to block bots.

To close registration to the public, set `app__GEEFTER_REGISTRATION_ENABLE` to
`0` in the account's environment variables and redeploy — invitations still work.

Email
-----
Mail is **disabled** (`app__EMAIL_ENABLED=0`) and email confirmation is skipped,
because a fresh account has no SMTP. To enable notifications, set
`app__EMAIL_ENABLED=1` and `app__EMAIL_MAILER_DSN` to a real SMTP DSN.

Data & secrets
--------------
- The database lives in the `geeftlist_db` Docker volume and survives redeploys.
- Uploaded gift images live in `~/.panelalpha/geeftlist/userdata` (survives
  redeploys, reachable over SFTP).
- The DB password and the app signing keys are generated once and stored in
  `~/.panelalpha/geeftlist/geeftlist.env` (0600). They are never rotated, so
  redeploys keep sessions and data intact.

Cron (optional)
---------------
Geeftlist has scheduled jobs (deferred notifications, orphan-image cleanup). This
stack does not run them. If you want them, add a host cron that curls
`https://<your-domain>/cron/run` every minute (the source allows 127.0.0.1 and
the docker bridge ranges by default).
