# CookCLI (github.com/cooklang/CookCLI)

`cook server`: web UI for a folder of Cooklang recipes (browse, edit, scale,
shopping lists, pantry).

## Deploying

Nothing is required. The first visit lists upstream's seed recipes; new and
edited recipes are saved to the `cook_recipes` volume. The server is open to
everyone who can reach it, as upstream's image ships it; upstream's README
("Sign-in in a container") describes how to add users.

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `ghcr.io/cooklang/cookcli:0.37.0` instead of upstream's compose, whose
  `./recipes` bind mount is created root-owned and makes the entrypoint exit
  ("/recipes directory is not writable by user 1000:1000").
- Recipes on a named volume, first filled from the image's seed recipes.
- `COOK_CORS_ORIGIN` is the site address: without it pages reached by a
  domain can read but not save (upstream's compose comment).
