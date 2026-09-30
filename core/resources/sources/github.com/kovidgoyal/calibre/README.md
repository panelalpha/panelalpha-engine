# calibre (content server)

- Image: `linuxserver/calibre:9.15.0` (calibre 9.15.0); entrypoint replaced to
  run `/opt/calibre/calibre-server` on `:8080` instead of the KasmVNC desktop.
- `/library` (named volume) holds `metadata.db` and the books; created empty on
  first start with `calibredb list`.
- `/config` (named volume) is `HOME`, where calibre keeps its settings.
- No auth is added (upstream default: remote visitors can read, not write).
