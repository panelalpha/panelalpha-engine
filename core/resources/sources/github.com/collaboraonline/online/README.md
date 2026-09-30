# Collabora Online Development Edition (github.com/collaboraonline/online)

LibreOffice-based online office (coolwsd). A WOPI host such as Nextcloud,
Seafile or ownCloud embeds it; it holds no documents itself.

## What the recipe does

- The GitHub repository keeps only the Helm chart and the image build; the
  source lives on Gerrit. A plain deploy finds no application and serves the
  PanelAlpha placeholder. `overrides/docker-compose.yml` runs upstream's
  published `collabora/code` image of the current release (26.04.4.2.1).
- Port 9980, plain HTTP: `--o:ssl.enable=false --o:ssl.termination=true`
  (via the image's `extra_params`), so discovery hands out `https://` URLs
  for the engine's TLS front. `server_name` is the project domain.
- The image has no shell or curl: the health check is upstream's own
  `coolwsd --probe`, and the `ready` gate runs `coolwsd --version-hash`.
- Stateless, so no volumes.
- Optional project env vars, read by the image as upstream documents them:
  `aliasgroup1` (allowed WOPI host, e.g. `https://cloud.example.com:443`;
  unset allows any), `username` / `password` (admin console at
  `/browser/dist/admin/admin.html`; unset leaves it disabled, upstream's
  default).

Without CAP_SYS_ADMIN coolwsd logs that bind-mounting jails fails and falls
back to copying the system template per document; this works, just slower.

Upgrading: change both image tags.
