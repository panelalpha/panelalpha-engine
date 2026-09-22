# Roundup ships no Dockerfile and no first-run web installer. This image
# installs roundup from the checkout (pip) and hands off to
# panelalpha-entrypoint.sh, which creates or refreshes the tracker home on the
# persistent /data mount before serving it with gunicorn.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_ROOT_USER_ACTION=ignore \
    TRACKER_HOME=/data/tracker \
    SENDMAILDEBUG=/data/tracker/mail-debug.log \
    PYTHONPATH=/opt/panelalpha

WORKDIR /build
COPY . /build

# roundup 2.6 is pure Python (no compiler needed); gunicorn is the WSGI server.
RUN pip install . gunicorn \
 && install -d /opt/panelalpha \
 && cp panelalpha-wsgiapp.py /opt/panelalpha/panelalpha_wsgiapp.py \
 && cp panelalpha-entrypoint.sh /usr/local/bin/panelalpha-entrypoint \
 && chmod +x /usr/local/bin/panelalpha-entrypoint

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/panelalpha-entrypoint"]
