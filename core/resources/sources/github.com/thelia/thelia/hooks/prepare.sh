#!/bin/bash
# Account shell, after the clone and before the build. Nothing here can know
# the database credentials -- the engine provisions those while it writes the
# compose file, after this hook -- so everything that needs them is in
# files/panelalpha/thelia-setup.sh instead.
set -e
cd ~/project

# 1. The committed .env, which is not a dotenv file here but a list of process
#    environment variables.
#
#    The generated compose file carries `env_file: - .env`, so every line of it
#    becomes a real environment variable in the container, and
#    Symfony\Component\Dotenv\Dotenv::populate() never overwrites one of those.
#    That inverts the precedence the file's own header describes: .env.local,
#    the file Symfony documents for per-install values and the file Thelia's
#    bin/install writes, is read, found to be shadowed, and discarded.
#
#    Three keys have to be settled here because of it.

#    APP_ENV. The repository ships `dev`, which is the profiler, no cache
#    warmup, and stack traces in the browser on a public shop.
if grep -q '^APP_ENV=dev$' .env; then
    sed -i 's|^APP_ENV=dev$|APP_ENV=prod|' .env
fi

#    APP_SECRET. Empty in the repository. bin/install generates one into
#    .env.local when it finds none -- and an empty APP_SECRET arriving from
#    env_file would shadow it, leaving Symfony to sign CSRF tokens and
#    remember-me cookies with an empty string. Only the exact published
#    (empty) value is matched, so an operator's own secret is never replaced.
if grep -q '^APP_SECRET=$' .env; then
    sed -i "s|^APP_SECRET=\$|APP_SECRET=$(openssl rand -hex 32)|" .env
fi

#    DATABASE_HOST / _PORT / _NAME / _USER / _PASSWORD. The repository ships
#    all five, four of them empty, as a template for .env.local. Left in place
#    they become empty environment variables that beat the .env.local block
#    bin/install writes, and every request is a PDO connection to no host --
#    HTTP 500 with a correct-looking configuration file on disk.
#
#    Deleted rather than filled in: the credentials do not exist yet at this
#    point in the deploy, and .env.local is where Thelia's own installer puts
#    them. The whole Flex block goes, markers included, so `git diff` shows one
#    removed section rather than five edited lines.
if grep -q '^###> thelia/database ###$' .env; then
    sed -i '/^###> thelia\/database ###$/,/^###< thelia\/database ###$/d' .env
    echo "[thelia] removed the empty DATABASE_* block from .env; .env.local owns it"
fi

# 2. The first administrator's password, generated per account and never a
#    default. Written here rather than in the container so that it exists
#    before anything could serve a page, and read back by
#    panelalpha/thelia-setup.sh when it runs bin/install --with-admin.
#
#    openssl rather than $RANDOM: this is the only credential the account has.
#    The umask is inside a subshell on purpose -- it has to cover the
#    redirection that creates the file, so the password is never briefly
#    world-readable, and it must not survive into the rest of this script,
#    where a 077 default would leave directories the engine (www-data) cannot
#    scan when it walks the tree looking for the document root.
if [ ! -f .panelalpha-admin-password ]; then
    ( umask 077; openssl rand -base64 18 | tr -d '/+=' > .panelalpha-admin-password )
    chmod 600 .panelalpha-admin-password
fi

# 3. The directories Thelia writes into and .gitignore keeps out of the clone.
#
#    bin/install creates var, public, local/media, local/config and
#    var/translations itself, but the container's Apache starts from whatever
#    is on disk, and the engine walks this tree before either of them runs.
#    755, not 777: the container runs as this same account uid (the generated
#    compose file says `user: "<uid>:<gid>"`), so Apache is the owner.
#
#    var/translations is the one that is not obvious: symfony/ux-translator
#    registers it as an AssetMapper path and never creates it, and a missing
#    AssetMapper path is a 500 on the first page.
mkdir -p var/cache var/log var/translations \
         public/assets public/media \
         local/media/images local/config \
         templates/frontOffice templates/backOffice templates/email templates/pdf
chmod 755 var var/cache var/log var/translations public/assets public/media \
          local/media local/media/images local/config templates
