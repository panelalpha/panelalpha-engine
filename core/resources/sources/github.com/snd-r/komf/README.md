# Komf (github.com/snd-r/komf)

Metadata fetcher for Komga and Kavita: pulls series/book metadata from
providers (MangaUpdates, AniList, BookWalker, ...) and writes it into the
media server. Kotlin/Ktor on :8085; the Komelia web UI at `/` configures it and
shows jobs. Komf has no login of its own.

## Deploying

Nothing is required to start. Connect a media server either in the web UI
(Settings) or with project environment variables:

- Komga: `KOMF_KOMGA_BASE_URI`, `KOMF_KOMGA_USER`, `KOMF_KOMGA_PASSWORD`
- Kavita: `KOMF_KAVITA_BASE_URI`, `KOMF_KAVITA_API_KEY`
- `KOMF_LOG_LEVEL` (default `INFO`)

## What the recipe does

- `overrides/docker-compose.yml` runs `sndxr/komf:2.1.0` on port 8085 with
  `/config` on the named volume `komf-config` (kept across redeploys), and
  upstream's low-memory `JAVA_TOOL_OPTIONS`.
- The repository's own build is not used: it has no compose file, the
  Dockerfile expects a prebuilt jar, and the engine's Gradle build stops at
  `komf-api-models` (Android SDK location not found).
- `config-init` (same image, one-shot) writes an empty `application.yml` on the
  first deploy only; Komf will not start without the file, and the UI saves its
  settings into it.

The web UI assets exist only gzipped and are served when the browser accepts
gzip. The `*.panelalpha.online` test edge drops `Accept-Encoding` (engine#170),
so there the page stays blank; on a domain pointed at the host it loads.
