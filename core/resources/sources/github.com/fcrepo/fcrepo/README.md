# Fedora Commons Repository (github.com/fcrepo/fcrepo)

Linked Data Platform repository for digital libraries and archives (Java).

## What the recipe does

- The repository is the Maven source of a WAR. A plain deploy runs the Java
  strategy, which starts `fcrepo-http-api-*-tests.jar` ("no main manifest
  attribute") and restart-loops. `overrides/docker-compose.yml` runs
  upstream's published `fcrepo/fcrepo:7.0.0-tomcat10` (current release).
- Port 8080. `fcrepo.home` (OCFL root and the embedded H2 index) is on the
  `fcrepo-home` volume and survives redeploys. Heap capped at 1 GB.
- `files/fcrepo-root/index.jsp` is mounted as Tomcat's ROOT webapp so `/`
  redirects to the REST root `/fcrepo/rest` instead of a Tomcat 404.
- Every path requires HTTP Basic auth, so the health check logs in with the
  image's own `FEDORA_ADMIN_USERNAME` / `FEDORA_ADMIN_PASSWORD`.
- fcrepo honours `X-Forwarded-Proto` (the image's RemoteIpValve), so resource
  URIs come out as `https://<domain>/fcrepo/rest/...`.

The admin login is upstream's image default (`fedoraAdmin` / `fedoraAdmin`);
change it by setting those two variables in the compose file.

Upgrading: change both image tags.
