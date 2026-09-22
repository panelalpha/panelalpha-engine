# Castopod

<https://code.castopod.org/adaures/castopod> — tracker issue
[#1374](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1374).

A podcast hosting platform: CodeIgniter 4.7 over MySQL, a Vite/Tailwind
frontend, an admin area at `/cp-admin`, ActivityPub federation, and an RSS feed
per podcast at `/@<handle>/feed.xml`. The default branch is `develop`
(2.0.0-next.3); there is no release tag that matches it.

## What the account gets

| | |
|---|---|
| Public site | `https://<domain>/` |
| Admin | `https://<domain>/cp-admin` |
| Sign in | `https://<domain>/cp-auth/login` |
| Feed | `https://<domain>/@<handle>/feed.xml` |
| Health | `https://<domain>/health` (JSON; 200 or 503 with what is wrong) |
| Install wizard | `https://<domain>/cp-install` — **404**, closed at deploy time |

The instance owner's username, password and email are in
`~/.panelalpha/castopod/admin-credentials` (0600, inside a 0700 directory).

## Where the data lives

Everything that has to outlive a redeploy is in `~/.panelalpha/castopod`, which
the compose override bind-mounts at `/data`:

```
~/.panelalpha/castopod/media/     every episode's audio, every cover, every
                                  avatar. public/media is a symlink to /data/media.
~/.panelalpha/castopod/analytics.salt      the salt listener IPs are hashed with
~/.panelalpha/castopod/admin-credentials   the generated instance owner, 0600
```

The database is the account's own MySQL database (`database: mysql`), so it is
in the panel, in phpMyAdmin and in the account's backup.

A redeploy clears and re-clones `~/project` (engine#173). Nothing in the list
above is in it, and the prepare hook rebuilds the symlink on the way back.
`~/project/.env` holds the database password and the salt and is written 0600 by
the install stage, inside the container, *after* the engine has taken its
world-readable `.env.default` copy of the checkout — that copy is of a
placeholder with nothing secret in it.

## What an operator can change

The panel's `env_vars` are merged over the `.env` the prepare hook leaves, and
the install stage keeps whatever it finds there. Two are read by name:

* `CP_ADMIN_GATEWAY` / `CP_AUTH_GATEWAY` — the URL prefixes for the admin area
  and the auth routes. Defaults `cp-admin` and `cp-auth`.

Anything else Castopod reads from `.env` (SMTP, S3 media storage, the REST API,
Redis) can be added to `~/project/.env` by hand; the install stage rewrites only
the keys it owns.

## Not supported on this platform

* **Scheduled tasks.** Upstream's own image runs supercronic beside the
  application for the analytics rollups and the fediverse outbox. There is no
  cron on a hosting account, so those do not run. The site, the admin area,
  publishing and the feed are unaffected.
* **Video clips.** `Modules\MediaClipper` shells out to ffmpeg, which is not in
  the shared PHP base image. Audio, artwork and the feed do not need it.

## Measured

A clean deploy on the test host, from an empty account:

* deploy `completed`, `serving: ok`, HTTP 200
* signed in over public HTTPS with the generated credential
* created a podcast (1500×1500 PNG cover), published it
* uploaded a **14,367,328-byte MP3** as an episode and published it — the
  upload is what the php.ini file exists for; PHP's compiled-in
  `post_max_size` is 8M, and the base image loads no php.ini (engine#185)
* fetched `/@patest2/feed.xml`: HTTP 200, `application/xml`, one `<item>`,
  `<enclosure url="…/audio/@patest2/episode-one.mp3" length="14367328"
  type="audio/mpeg">`
* fetched that enclosure URL back: 200, 14,367,328 bytes, byte-identical to
  what was uploaded
* `/cp-install` → 404
* every byte of it under `~/.panelalpha/castopod/media`, nothing in the checkout

engine#170 applies: the multipart POSTs all fail through the
`*.panelalpha.online` tunnel edge and succeed against the account's own address
(`--resolve <domain>:443:<host ip>`).
