#!/bin/bash
# Account shell, after the clone and before detection.
#
# What this hook does is turn a checkout that cannot be served into a Contao
# project that can. github.com/contao/contao is the *development monorepo* --
# `"type": "symfony-bundle"`, thirteen bundle directories, no front controller,
# no public/, no config/ -- and its own README says so in as many words:
#
#     The purpose of this package is to develop the Contao bundles in a
#     monorepo. Use it when you want to create a pull request or report an
#     issue. [...] Please do not use `contao/contao` in production! Use the
#     split packages instead.
#
# The deployable Contao is the Managed Edition: a project skeleton whose
# composer.json requires the released split packages and whose whole content is
# that file. So this hook writes that manifest, and lays the official example
# website (contao/contao-demo, which is the same manifest plus the demo's
# files/, templates/ and a database dump) around it. The monorepo is moved to
# .contao-monorepo/ rather than deleted -- it is what the customer asked to be
# cloned, and it is outside the document root.
set -e
cd ~/project

# ---------------------------------------------------------------------------
# 1. Read what the checkout can tell us, before it moves.
# ---------------------------------------------------------------------------

# The PHP constraint is the monorepo's own: a clone of main asks for ^8.4, a
# clone of the 5.3 branch asks for less, and it is the value the engine reads
# to pick the base image. Taking it from the repository rather than hardcoding
# it is the one place the checkout still decides something.
PHP_REQ=$(sed -n 's/.*"php"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' composer.json | head -1)
[ -n "${PHP_REQ}" ] || PHP_REQ='^8.4'

# extra.branch-alias says which Contao this clone develops: "dev-main" maps to
# "6.1-dev" today. Only the major is used -- 6.1 is unreleased, and a recipe
# that pinned it would resolve to nothing.
ALIAS=$(sed -n 's/.*"dev-[^"]*"[[:space:]]*:[[:space:]]*"\([0-9]*\)\.[0-9x]*-dev".*/\1/p' composer.json | head -1)
[ -n "${ALIAS}" ] || ALIAS=6

# ---------------------------------------------------------------------------
# 2. Which Contao to install.
# ---------------------------------------------------------------------------
#
# The newest stable contao/contao-demo in that major. Asked of packagist rather
# than api.github.com: the GitHub API's unauthenticated budget is 60 requests
# an hour for the host's whole egress IP, and every deploy on this box shares
# it. The p2 metadata is a static file behind a CDN and lists newest first.
#
# The fallback is a real version, not `latest`: a tag that does not exist is a
# 404 in the middle of a deploy, and there is no floating tag to fall back to.
DEMO_FALLBACK=6.0.1
DEMO_VERSION=$(
    curl -fsS --max-time 30 "https://repo.packagist.org/p2/contao/contao-demo.json" 2>/dev/null \
        | grep -o '"version":"[0-9][^"]*"' \
        | sed 's/.*"\([0-9][^"]*\)"$/\1/' \
        | grep -E "^${ALIAS}\.[0-9]+\.[0-9]+$" \
        | head -1
) || true
[ -n "${DEMO_VERSION}" ] || DEMO_VERSION="${DEMO_FALLBACK}"

# 6.0.1 -> 6.0.*, which is what contao/managed-edition and the demo both pin:
# the bundles are released as one set and mixing minors is not supported.
SERIES="$(echo "${DEMO_VERSION}" | cut -d. -f1,2).*"

echo "[contao] checkout develops Contao ${ALIAS}.x; installing the ${SERIES} release series (demo ${DEMO_VERSION})"

# ---------------------------------------------------------------------------
# 3. Move the monorepo out of the way.
# ---------------------------------------------------------------------------
#
# Everything except .git (the engine's own clone bookkeeping) and panelalpha/
# and the compose override, which this recipe's files/ and overrides/ wrote
# into the checkout a moment ago -- AppConfigBootstrap installs those before it
# runs this script.
#
# The root package.json goes with it, and that matters: HostCompile::runForPhp
# reads the *root* package.json for a `build` script and would otherwise run
# the monorepo's webpack build -- an npm install of the whole Contao frontend
# toolchain inside a 2000 MB account, for assets the released packages already
# ship built.
mkdir -p .contao-monorepo
for entry in * .[!.]*; do
    case "${entry}" in
        '*'|'.[!.]*') continue ;;
        .git|.contao-monorepo|panelalpha|docker-compose.override.yml|docker-compose.yml) continue ;;
    esac
    mv -- "${entry}" .contao-monorepo/
done

# A repository that ships a workstation compose file has it mined for runtime
# sidecars even when it is never run (engine#166). The monorepo ships none
# today; the glob is here so that a branch which starts to does not quietly
# acquire a database container it did not ask for.
for f in .contao-monorepo/docker-compose.*.yml; do
    [ -e "${f}" ] || continue
    mv -- "${f}" "${f}.workstation"
done

# ---------------------------------------------------------------------------
# 4. Lay down the application.
# ---------------------------------------------------------------------------
#
# contao/contao-demo is the Managed Edition plus content: files/ (the media
# library), templates/, theme.xml and var/backups/backup__*.sql, which is a
# full database dump of the official example website. Without it a fresh Contao
# has no root page and answers 404 to everything -- a correct answer from a CMS
# with no pages, and not a site anyone can look at.
curl -fsSL --max-time 300 \
    "https://codeload.github.com/contao/contao-demo/tar.gz/refs/tags/${DEMO_VERSION}" \
    -o .contao-demo.tar.gz
tar xzf .contao-demo.tar.gz --strip-components=1 \
    --exclude='*/composer.json' --exclude='*/.gitattributes'
rm -f .contao-demo.tar.gz

# The Managed Edition manifest. Written here rather than taken from the tarball
# because two things have to be added to it and editing JSON in a shell script
# is worse than writing it:
#
#   * "php", so the engine picks the base image from the constraint the
#     checkout declared rather than guessing;
#   * config.allow-plugins. The demo's own composer.json has none -- it is
#     installed by `composer create-project`, which asks interactively -- and
#     a non-interactive install without it dies on
#     "contao-components/installer contains a Composer plugin which is blocked
#     by your allow-plugins config". Both plugins listed here are load-bearing;
#     see the README.
cat > composer.json <<EOF
{
    "name": "panelalpha/contao",
    "description": "Contao Managed Edition, assembled by PanelAlpha from contao/managed-edition and contao/contao-demo",
    "license": "LGPL-3.0-or-later",
    "type": "project",
    "require": {
        "php": "${PHP_REQ}",
        "contao/calendar-bundle": "${SERIES}",
        "contao/comments-bundle": "${SERIES}",
        "contao/conflicts": "@dev",
        "contao/faq-bundle": "${SERIES}",
        "contao/listing-bundle": "${SERIES}",
        "contao/manager-bundle": "${SERIES}",
        "contao/news-bundle": "${SERIES}",
        "contao/newsletter-bundle": "${SERIES}",
        "contao-components/swiper": "^12"
    },
    "conflict": {
        "contao-components/installer": "<1.3"
    },
    "config": {
        "allow-plugins": {
            "composer/package-versions-deprecated": true,
            "contao-components/installer": true,
            "contao/manager-plugin": true,
            "php-http/discovery": false
        }
    },
    "extra": {
        "contao-component-dir": "assets"
    },
    "scripts": {
        "post-install-cmd": [
            "@php vendor/bin/contao-setup"
        ],
        "post-update-cmd": [
            "@php vendor/bin/contao-setup"
        ]
    }
}
EOF

# public/index.php has to exist before the container is ever built: the PHP
# strategy refuses a project it cannot find an index.php in, and Contao's own
# front controller is written by `skeleton:install`, which runs in the install
# stage -- after that check. This is that same file, copied out of the checkout
# (manager-bundle/skeleton/public/index.php) so it is Contao's and not a stub;
# contao-setup overwrites it with the installed release's copy.
mkdir -p public
if [ -f .contao-monorepo/manager-bundle/skeleton/public/index.php ]; then
    cp .contao-monorepo/manager-bundle/skeleton/public/index.php public/index.php
else
    printf '<?php // replaced by skeleton:install during the install stage\n' > public/index.php
fi

# ---------------------------------------------------------------------------
# 5. Secrets, generated once and kept outside the checkout.
# ---------------------------------------------------------------------------
#
# ~/project does not survive a deploy -- the clone clears it -- while the
# account's MySQL database does. An administrator password regenerated on
# redeploy would be a password the database never learns, and a rotated
# APP_SECRET invalidates every session and remember-me cookie. $HOME is
# root-owned 0755 and an account cannot create files directly in it, so the
# store lives in ~/.panelalpha/.
STORE="${HOME}/.panelalpha/contao.env"
if [ ! -f "${STORE}" ]; then
    mkdir -p "$(dirname "${STORE}")"
    chmod 700 "$(dirname "${STORE}")"
    (
        umask 077
        cat > "${STORE}" <<EOF
# Generated by the PanelAlpha Contao recipe on the first deploy.
CONTAO_APP_SECRET=$(openssl rand -hex 32)
CONTAO_ADMIN_USERNAME=admin
CONTAO_ADMIN_EMAIL=admin@example.com
CONTAO_ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-20)
EOF
    )
fi
# shellcheck disable=SC1090
. "${STORE}"

# APP_SECRET goes into .env rather than .env.local, and that is deliberate use
# of a property that usually bites: the generated compose file carries
# `env_file: - .env`, so every line of it becomes a real environment variable,
# and Symfony's Dotenv never overwrites one of those. Which is exactly what is
# wanted here -- ContaoSetupCommand generates and writes a *new* APP_SECRET
# into .env.local on every run when it cannot see one, so on each redeploy the
# secret would rotate and take every session with it. A real environment
# variable settles it before contao-setup looks.
(
    umask 077
    cat > .env <<EOF
# Read by Symfony's Dotenv *and* mounted as real environment variables by the
# generated compose file's env_file. Do not put per-install secrets that the
# application is supposed to be able to rewrite in here.
APP_SECRET=${CONTAO_APP_SECRET}
#COOKIE_ALLOW_LIST=PHPSESSID,csrf_https-contao_csrf_token,csrf_contao_csrf_token,trusted_device,REMEMBERME
EOF

    # Where a human is pointed, and where panelalpha/contao-setup.sh reads the
    # credentials it creates the administrator with. Contao has no sign-up page
    # and no web installer since 5.0, so without this the back end is a login
    # form nobody holds an account for.
    cat > .panelalpha-admin-password <<EOF
# Written by PanelAlpha on the first deploy. Sign in at https://<your-domain>/contao
CONTAO_ADMIN_USERNAME=${CONTAO_ADMIN_USERNAME}
CONTAO_ADMIN_EMAIL=${CONTAO_ADMIN_EMAIL}
CONTAO_ADMIN_PASSWORD=${CONTAO_ADMIN_PASSWORD}
EOF
)

echo "[contao] project assembled: Managed Edition ${SERIES} with the official demo website"
