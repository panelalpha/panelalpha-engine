# LazyLibrarian: a single CherryPy web process, pure Python, SQLite.
#
# Written for the recipe because the repository ships no Dockerfile. There is
# no build step — the app is interpreted — so this just installs the pinned
# requirements and runs LazyLibrarian.py. python:3.12-slim is chosen because
# the project's requires-python is >=3.10 and every pin in requirements.txt
# publishes a manylinux wheel for 3.12 (cherrypy, cryptography, lxml, numpy,
# pillow), so no compiler is needed in the image. Pinning the interpreter is a
# base-image choice, not a patch to the source.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Requirements first so the layer caches across source-only changes.
COPY requirements.txt ./
RUN pip install -r requirements.txt

# The application itself.
COPY . /app

# The datadir is a bind mount to the account's persistent ~/.panelalpha (see
# ../overrides/docker-compose.override.yml); everything LazyLibrarian writes —
# the SQLite db, config.ini, logs, cache — lives there and survives a rebuild.
# config.ini is pre-seeded by the prepare hook with BASIC auth enabled.
# --nolaunch: never try to open a desktop browser from inside the container.
EXPOSE 5299
CMD ["python", "LazyLibrarian.py", "--datadir", "/pa-data/lazylibrarian", "--port", "5299", "--nolaunch"]
