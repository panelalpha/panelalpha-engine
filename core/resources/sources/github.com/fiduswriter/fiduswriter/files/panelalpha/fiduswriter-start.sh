#!/bin/sh -e
# Upstream's configuration.py hardcodes ALLOWED_HOSTS to localhost and never
# reads the environment. Create it the way the image's own start script does
# (random SECRET_KEY, kept on the volume), let it accept the public address
# once, then hand over to that script unchanged.
export PATH="/fiduswriter/venv/bin:$PATH"
if [ ! -f /data/configuration.py ]; then
    cd /fiduswriter
    fiduswriter startproject
    mv /fiduswriter/configuration.py /data/configuration.py
fi
if ! grep -q 'PanelAlpha public address' /data/configuration.py; then
    cat >> /data/configuration.py <<'PY'

# PanelAlpha public address (read at start, so a domain change follows).
ALLOWED_HOSTS = list(ALLOWED_HOSTS) + [os.environ.get("PA_PUBLIC_HOST", "")]
CSRF_TRUSTED_ORIGINS = [os.environ.get("PA_PUBLIC_URL", "")]
PY
fi
exec /bin/sh /etc/start-fiduswriter.sh
