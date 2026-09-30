# Automad

Flat-file CMS. The repository is the development tree (its `package-lock.json`
does not match `package.json`, so the engine's `npm ci` fails), so the recipe
replaces the compose file with the official image of the current release,
`automad/automad:2.0.0-beta.59` (nginx + php-fpm on :80).

- First start: the image runs `composer create-project automad/automad` into `/app`.
- `/app` is a named volume: pages, shared files, config and users survive redeploys.
- Automad's own first-run setup is left as upstream ships it.
- The image's php-fpm pool is static (60 workers), hence `mem_limit: 768m`.
- Behind the TLS proxy Automad's canonical/feed URLs say `http://`; set `AM_SERVER`
  in `config/config.php` (dashboard or file) to `https://<domain>` if that matters.
