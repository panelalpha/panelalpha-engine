# Datenstrom Yellow

Flat-file CMS (PHP, no database). Deployed on the engine's `php-plain` platform.

- `PA_DOCROOT=/app`: the entry point is `yellow.php`, reached through the
  repository's own `.htaccess`; without it the image serves an empty docroot (403).
- `content/`, `media/`, `system/` are seeded once into `~/.panelalpha/yellow`
  and bind-mounted back, so pages, settings, users and extensions installed by
  Yellow's own updater survive a redeploy.
- First visit shows Yellow's own installer (name, email, password, language).
