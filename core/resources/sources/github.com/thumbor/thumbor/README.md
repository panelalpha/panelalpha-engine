# Thumbor (github.com/thumbor/thumbor)

An HTTP image service: cropping, resizing, filters and optimisation on demand,
on port 8888. There is no web UI; requests are URLs such as

```
https://<domain>/unsafe/300x200/smart/https://example.com/image.jpg
```

`/healthcheck` answers `WORKING`.

## Deploying

Nothing is required. Thumbor runs with its default configuration, which
serves unsigned `/unsafe/` URLs and signs with the default `SECURITY_KEY`;
change that in a `thumbor.conf` if the service should only answer signed URLs.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/thumbor/thumbor:7.8.0-py-3.12`
  (the current release) on port 8888 with a healthcheck on `/healthcheck`.
- No volume: thumbor's file storage and result storage are caches under
  `/tmp/thumbor` and are rebuilt on demand after a redeploy.
