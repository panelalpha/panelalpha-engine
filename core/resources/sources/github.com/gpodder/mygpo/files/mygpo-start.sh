#!/bin/sh
# mygpo has no container build: install requirements.txt into a venv on a
# named volume (again only when it changes), then run the given role.
set -e
V=/opt/venv
want=$(sha256sum /src/requirements.txt | cut -c1-16)
if [ "$1" = web ] && [ "$(cat "$V/.pa-req" 2>/dev/null)" != "$want" ]; then
    python -m venv --clear "$V"
    "$V/bin/pip" install --no-cache-dir -r /src/requirements.txt
    echo "$want" > "$V/.pa-req"
fi
export PATH="$V/bin:$PATH"
cd /src
case "$1" in
    web)
        python manage.py migrate --noinput
        # STATIC_ROOT is "staticfiles", relative to the working directory.
        (cd /var/www && python /src/manage.py collectstatic --noinput -v 0)
        exec gunicorn mygpo.wsgi:application --bind 0.0.0.0:8000 \
            --workers 3 --worker-class gthread --threads 3 --timeout 120 \
            --access-logfile - ;;
    worker)
        exec celery -A mygpo worker --beat --concurrency=2 -l info \
            --scheduler django_celery_beat.schedulers:DatabaseScheduler ;;
esac
