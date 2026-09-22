#!/bin/bash
# Runs on the account, after the clone and before the image is built. The
# only thing it does is generate the two secrets this account needs and put
# them somewhere the next deploy will still find them.
#
# It deliberately does not touch the checkout. Everything else this recipe
# changes is an environment variable in the generated compose file, so
# `git status` in ~/project stays clean and `git pull` never conflicts.
set -e

# ~/.panelalpha rather than the home itself or ~/project. The home is
# chown root:root 0755 on every rebuild and an account cannot write into it;
# ~/project is emptied by ProjectTree::clearContents() (GitRepository.php:89)
# before every deploy. This subdirectory is the account's and survives both.
#
# 0700 because /home is world-traversable on this host and the file below is
# the site's administrator password. Root -- the Docker daemon, which
# resolves the env_file path -- and the account, which the container runs
# as, both still reach it.
STORE_DIR="$HOME/.panelalpha/partdb"
mkdir -p "$STORE_DIR"
chmod 700 "$STORE_DIR"

STORE="$STORE_DIR/partdb.env"

# Written once and never rewritten: an operator who changes the administrator
# password in Part-DB keeps their change, and a rebuild does not invalidate
# every session by rotating APP_SECRET.
#
# Not in .env, and that is not fastidiousness: ProjectEnvironment::apply()
# copies .env to a world-readable .env.default (engine#173), and the
# Dockerfile's `COPY .env* ./` would bake both into an image layer. The
# container reads this file as a second env_file instead -- see
# overrides/docker-compose.override.yml.
if [ ! -f "$STORE" ]; then
    # The umask is inside a subshell on purpose: it has to cover the
    # redirection that creates the file, so the password is never briefly
    # world-readable, and it must not leak into the rest of this script.
    (
        umask 077
        cat > "$STORE" <<EOF
# Generated once by the PanelAlpha Part-DB recipe, on the first deploy of
# this account. Read into the container as a second env_file. Keep it: it is
# the only copy of the first administrator's password, and rotating
# APP_SECRET logs every session out.

# Symfony's application secret: %kernel.secret%, which signs CSRF tokens and
# the remember-me cookie. The repository commits
# APP_SECRET=a03498528f5a5fc089273ec9ae5b2849 in .env -- a real, fixed value
# printed in a public repository, not a placeholder that would be noticed --
# and env_file makes it the live one. This shadows it.
APP_SECRET=$(openssl rand -hex 32)

# The password the first migration gives the "admin" user.
# AbstractMultiPlatformMigration::getInitalAdminPW() reads INITIAL_ADMIN_PW
# and, when it is unset, invents substr(md5(random_bytes(10)), 0, N) and
# prints it once into the migration's log -- correct, but unreadable
# afterwards. Setting it here means the credential exists in a file the
# account owns before anything can serve a page. Part-DB sets
# need_pw_change=1 on that row, so the first login is forced through a
# password change either way.
INITIAL_ADMIN_PW=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
EOF
    )
    chmod 600 "$STORE"
    echo "[part-db] generated this account's secrets in ~/.panelalpha/partdb/partdb.env"
fi

# Where a human is pointed, beside the secrets rather than in ~/project,
# which the next deploy deletes.
cat > "$STORE_DIR/README.txt" <<'EOF'
Part-DB, deployed by PanelAlpha.

  Sign in:   https://<your domain>/
  Username:  admin
  Password:  the INITIAL_ADMIN_PW line in partdb.env, beside this file.
             Part-DB forces a password change on the first login.

There is no sign-up page and no installer. Every other account is created by
an administrator under Tools -> Users.

Anonymous access is off. Part-DB ships a second account, "anonymous", in the
"readonly" group, and it is what a visitor with no login is treated as: on a
stock install that makes every part, its stock and its storage location
world-readable, and lets a stranger POST /en/category/new to insert rows. The
first deploy takes that account's group away, once, which denies everything
and asks for a login instead. To publish your inventory after all, put
"anonymous" back in a group under Tools -> Users; it will not be changed
again.

This directory survives a redeploy; ~/project does not. The parts database
and private attachments live in the `project_data-var-www-html-uploads`
Docker volume and public attachments in `project_data-var-www-html-public-media`;
both are kept by the `docker compose down` a rebuild runs.
EOF
chmod 600 "$STORE_DIR/README.txt"
