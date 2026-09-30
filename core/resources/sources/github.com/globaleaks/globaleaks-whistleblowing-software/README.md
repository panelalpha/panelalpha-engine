# GlobaLeaks

Whistleblowing platform. Upstream's `docker/docker-compose.yml` on the published
`globaleaks/globaleaks:v5.0.99` image (cap_drop ALL, read-only root, data on the
`globaleaks` volume).

- GlobaLeaks redirects every plain-HTTP request to `https://<same host>`; behind the
  engine's TLS proxy that loops. The `app` service is an nginx TLS bridge to
  `https://globaleaks:8443` (self-signed until an HTTPS certificate is configured in
  the admin UI), passing the original `Host`.
- The first visit shows GlobaLeaks' setup wizard.
- The engine already serves the site over HTTPS; GlobaLeaks' own HTTPS / Let's Encrypt
  setup is not needed (its ACME challenge cannot reach the container).
