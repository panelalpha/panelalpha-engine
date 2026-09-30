# Convertigo (github.com/convertigo/convertigo)

Low-code application platform: a Java server on Tomcat with a web admin
console and a web Studio.

## What the recipe does

- The repository's root `pom.xml` builds the Eclipse desktop Studio with
  Tycho; a plain deploy runs Maven on it and fails
  (`/app/eclipse-feature/feature.xml (No such file or directory)`). The server
  image upstream builds from `docker/default/Dockerfile` is published as
  `convertigo/convertigo`, so `overrides/docker-compose.yml` runs that image
  (8.4.5, the current release) instead.
- Port 28080 (Tomcat, as upstream configures it). `/` redirects to
  `/convertigo/index.html`.
- `/workspace` (projects, `configuration/engine.properties`, logs) is on the
  `workspace` volume and survives redeploys.
- `JXMX=1024` caps the heap under the 1.5 GB container limit.
- `ready` makes `compose up` wait until the server answers (first boot ~30 s).

The admin console keeps upstream's first-run defaults; change the admin
credentials in the console after the first login.

Upgrading: change both image tags.
