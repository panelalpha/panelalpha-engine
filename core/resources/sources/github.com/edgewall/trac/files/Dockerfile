# Trac ships no Dockerfile and no first-run web installer. This image installs
# Trac from the checkout (pip) and hands off to panelalpha-entrypoint.sh, which
# creates or refreshes the project environment on the persistent /data mount
# before serving it with tracd.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_ROOT_USER_ACTION=ignore \
    TRAC_ENV=/data/env \
    PORT=8000

WORKDIR /build
COPY . /build

# Trac 1.6 is pure Python. Jinja2 is pulled in automatically; setuptools<81
# provides the pkg_resources module Trac imports (removed in setuptools 81+).
RUN pip install . "setuptools<81" \
 && cp panelalpha-entrypoint.sh /usr/local/bin/panelalpha-entrypoint \
 && chmod +x /usr/local/bin/panelalpha-entrypoint

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/panelalpha-entrypoint"]
