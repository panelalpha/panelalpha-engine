# Firefly III Data Importer (github.com/firefly-iii/data-importer)

Web wizard that imports CSV/CAMT files and bank-API data (GoCardless, SimpleFIN,
Enable Banking, ...) into an existing Firefly III instance.

## Deploying

Nothing is required. Optionally set project environment variables:

- `FIREFLY_III_URL`: the Firefly III instance to import into.
- `FIREFLY_III_CLIENT_ID`: an OAuth client created in Firefly III (Options >
  Profile > OAuth), so the importer logs in with it.
- `VANITY_URL`: the Firefly III address shown to the browser, if different.

Without them the importer asks for the Firefly III address and client ID on
its first page.

## What the recipe does

- `overrides/docker-compose.yml` runs `fireflyiii/data-importer:version-2.3.5`
  on port 8080 with `TRUSTED_PROXIES=**`, and the image's own
  `/var/www/html/storage/upload` volume as the named volume `importer-upload`.
- `ready` makes `compose up -d` wait for the image's `/healthcheck`.
- The repository is not built: the generic laravel recipe skips its Vite
  frontend build and every page answers 500.
