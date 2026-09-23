#!/bin/sh
# Serve the mounted application. Baked into the shared PHP base image.
#
# This exists so that a platform manifest does not have to spell out a shell
# incantation to be served. Every one of the fourteen shipped PHP manifests
# used to carry its own copy of
#
#   { PA_DOCROOT=/app/public; export PA_DOCROOT; exec apache2-foreground; }
#
# differing only in the directory. That is not a command a recipe should have
# to know: the recipe knows *where the document root is*, and how to hand a
# document root to Apache is the image's business. So a manifest declares
# `docroot:` and the engine passes it as PA_DOCROOT; this script is what turns
# that into a running server.
set -e

# Where a checkout with no front controller is served from: an empty directory
# baked into the image, outside /app so the account cannot fill it. Serving the
# repository instead published the whole source tree with the PHP handler
# active over it -- composer.lock, config/, and every .php under vendor/
# directly invocable. The deploy still succeeds and the health check still
# reports a missing entry point; only what is reachable changes.
EMPTY_DOCROOT=/usr/local/lib/panelalpha/empty-docroot

# Is there a front controller anywhere worth pointing Apache at? Bounded depth,
# because the answer is only interesting near the top: detection looks one
# level down, and an index.php buried deeper is not a document root either way.
has_index() {
    [ -n "$(find /app -maxdepth 4 -name index.php -print -quit 2>/dev/null)" ]
}

if [ -z "${PA_DOCROOT:-}" ]; then
    # No declared document root. Almost every PHP application either serves
    # from public/ or from its own root, and which one is a fact about the
    # directory rather than a decision -- so look.
    if [ -d /app/public ]; then
        PA_DOCROOT=/app/public
    elif has_index; then
        PA_DOCROOT=/app
    else
        # Nothing was detected and there is no index.php anywhere, so there is
        # no application here to serve. Falling back to /app cannot be helping;
        # the only thing it can do is publish source.
        echo "panelalpha: no index.php found under /app; serving an empty document root" >&2
        PA_DOCROOT=$EMPTY_DOCROOT
    fi
fi

# A document root that is not there produces `AH00526: Syntax error … DocumentRoot
# must be a directory`, which says nothing about which directory or who asked
# for it. Say it plainly instead, and fall back rather than refusing to boot:
# an application whose asset build has not created public/ yet is better served
# from its root than not at all -- but only if there is something there to
# serve, by the same rule as above.
if [ ! -d "$PA_DOCROOT" ]; then
    if has_index; then
        echo "panelalpha: document root $PA_DOCROOT does not exist; serving /app instead" >&2
        PA_DOCROOT=/app
    else
        echo "panelalpha: document root $PA_DOCROOT does not exist and /app has no index.php;" \
             "serving an empty document root" >&2
        PA_DOCROOT=$EMPTY_DOCROOT
    fi
fi

export PA_DOCROOT
exec apache2-foreground
