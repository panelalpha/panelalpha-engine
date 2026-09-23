# Typebot for PanelAlpha Engine

Typebot is a two-app conversational-form builder: the **builder** (editor,
`baptistearno/typebot-builder`) and the **viewer** (public bot runtime,
`baptistearno/typebot-viewer`), both Next.js standalone servers on `:3000`,
over one Postgres. Redis in the upstream compose is optional (rate limiting
only) and is dropped.

## What the recipe does
- Replaces the repository compose with `overrides/docker-compose.yml`: the
  builder is the single proxied web service; the viewer and Postgres sit
  beside it. Redis removed.
- `hooks/prepare.sh` generates and persists per-account secrets under
  `~/.panelalpha/typebot/` (survives rebuild): a 32-char `ENCRYPTION_SECRET`
  (Typebot encrypts saved credentials with it), the Postgres password, and
  `ADMIN_EMAIL`. Postgres data is bind-mounted there too, not to a named
  volume, so a redeploy keeps the database.
- `NEXTAUTH_URL` / `NEXT_PUBLIC_VIEWER_URL` are set to `http://localhost:3000`
  in the compose `environment:` so the engine rewrites them to the account's
  public URL.

## One-domain limitation (partial support)
The viewer's route is a root catch-all (`[[...publicId]].tsx`), so it cannot
share the builder's domain by path routing. On a single-domain account the
owner gets a fully working **editor** (build/design/preview/manage bots) but
**cannot publicly serve published bots** — that needs a second public hostname
for the viewer, set as `NEXT_PUBLIC_VIEWER_URL`.

## Login
Auth is passwordless (no credentials provider). Only providers with env set are
registered: SMTP magic-link, or GitHub/Google/GitLab/Azure/Keycloak/OIDC. With
none configured, nobody can sign in. `ADMIN_EMAIL` grants the owner role to the
first account that signs in with it; the operator must supply SMTP (for the
emailed login code) or an OAuth app.

## Snippets
- `docker-compose.yml` — [`overrides/docker-compose.yml`](overrides/docker-compose.yml)
- prepare / precheck — [`hooks/`](hooks/)
