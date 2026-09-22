# PanelAlpha settings_local for Bitpoll. The image symlinks
# bitpoll/settings_local.py to /opt/config/settings.py, where this file is
# mounted; bitpoll/settings.py imports it last, so it overrides the defaults.
import os
from urllib.parse import urlparse

# Required with DEBUG off. Generated once by hooks/prepare.sh and reused.
SECRET_KEY = os.environ["SECRET_KEY"]
# Fernet key for encrypted_model_fields; rotating it strands existing data.
FIELD_ENCRYPTION_KEY = os.environ["FIELD_ENCRYPTION_KEY"]

DEBUG = False

# PostgreSQL beside the app (psycopg2-binary ships in the production extra).
DATABASES = {
    "default": {
        "ENGINE": "django.db.backends.postgresql",
        "NAME": os.environ.get("POSTGRES_DB", "bitpoll"),
        "USER": os.environ.get("POSTGRES_USER", "bitpoll"),
        "PASSWORD": os.environ.get("POSTGRES_PASSWORD", ""),
        "HOST": os.environ.get("DB_HOST", "db"),
        "PORT": os.environ.get("DB_PORT", "5432"),
    }
}

# The account's public https address, rewritten into PA_PUBLIC_URL by the
# engine. Everything the visitor needs (Host validation, CSRF origin, the base
# URL used in emails and absolute links) is derived from it. 127.0.0.1 and
# localhost are added for the engine's in-container health probe.
ALLOWED_HOSTS = ["127.0.0.1", "localhost"]
CSRF_TRUSTED_ORIGINS = []

_public = os.environ.get("PA_PUBLIC_URL", "").strip().rstrip("/")
if _public and _public.lower() not in ("http://localhost", "https://localhost"):
    _parsed = urlparse(_public)
    if _parsed.hostname:
        ALLOWED_HOSTS.insert(0, _parsed.hostname)
        CSRF_TRUSTED_ORIGINS.append(f"{_parsed.scheme}://{_parsed.netloc}")
        BASE_URL = _public

# TLS is terminated at the engine's proxy; the app speaks plain http inside the
# container. Trust the forwarded proto so Django knows the request is secure,
# and mark the cookies secure without forcing a redirect loop.
SECURE_PROXY_SSL_HEADER = ("HTTP_X_FORWARDED_PROTO", "https")
SESSION_COOKIE_SECURE = True
CSRF_COOKIE_SECURE = True

# The engine proxy fronts the app; do not have Django itself redirect to https.
SECURE_SSL_REDIRECT = False
