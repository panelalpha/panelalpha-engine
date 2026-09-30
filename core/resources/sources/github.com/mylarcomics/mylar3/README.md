# Mylar3

Automated comic book downloader (*arr family). Runs the LinuxServer.io image
`linuxserver/mylar3:v0.11.0-ls273` instead of the repository's Dockerfile, which
no longer builds.

- `/config` (config.ini, mylar.db) and `/comics` are named volumes.
- The login (`admin`) and API key are generated once into
  `~/.panelalpha/mylar/` (`credentials.txt`) and seeded into `config.ini` on the
  first boot only.
- No ComicVine API key is set; add one under Settings before searching.
