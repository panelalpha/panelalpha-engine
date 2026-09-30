#!/bin/sh
# First run: let Home Assistant write its own default config (same version).
set -e
if [ ! -f /config/configuration.yaml ]; then
  python3 -c "from homeassistant.config import _write_default_config as w; import sys; sys.exit(0 if w('/config') else 1)"
fi
# Requests arrive through the account proxy; without this HA answers 400.
if ! grep -q '^http:' /config/configuration.yaml; then
  cat >> /config/configuration.yaml <<'YAML'

# Added by PanelAlpha: the site is served through the account's reverse proxy.
http:
  use_x_forwarded_for: true
  trusted_proxies:
    - 127.0.0.1
    - ::1
    - 172.16.0.0/12
    - 10.0.0.0/8
    - 192.168.0.0/16
YAML
fi
