# CommaFeed (github.com/Athou/commafeed)

Self-hosted RSS reader. The repository is a Maven reactor (`commafeed-client`,
`commafeed-server`); its Dockerfiles (`commafeed-server/src/main/docker/`) copy
artifacts built by CI. A plain java deploy builds it and then runs the client
webjar (no Main-Class) instead of `quarkus-app/quarkus-run.jar` (engine#361).

The recipe runs `athou/commafeed:7.3.2-h2` (current release, native build with
the embedded H2 database, as in upstream's quickstart) on port 8082 with
`/commafeed/data` on the named volume `commafeed-data`. On first visit
CommaFeed asks for the admin account (initial setup).
