# GitBucket (github.com/gitbucket/gitbucket)

GitHub-like Git hosting in one executable WAR (embedded Jetty, H2 database).

## What the recipe does

- The repository is an sbt build with no container setup, and the
  `gitbucket/gitbucket` image on Docker Hub stops at 4.38.4, so
  `overrides/docker-compose.yml` follows upstream's install steps instead:
  - `fetch` (one-shot) downloads `gitbucket.war` 4.48.0 from the GitHub
    release into the `war` volume and checks its SHA-256; a failed download
    fails the deploy.
  - `app` runs `java -jar` on `eclipse-temurin:21-jre`, port 8080, with
    `GITBUCKET_HOME=/gitbucket` on the `data` volume (repositories, H2
    database, settings; kept across redeploys).
  - `ready` makes `compose up` wait until GitBucket answers.
- Upgrading: change `GITBUCKET_VERSION` and `GITBUCKET_WAR_SHA256` together.

The first login is upstream's default `root` / `root`; change it in the app.
Git over HTTPS works through the site; GitBucket's SSH server is off by
default and is not published.
