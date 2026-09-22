#!/bin/bash
set -e
cd ~/project

# Grav's plugins and themes are not in the repository: .gitignore excludes
# user/plugins/* and user/themes/*, so a clone has user/config/system.yaml
# asking for the quark2 theme and nothing to load. Every request is then
# `Theme 'quark2' does not exist` as a bare 500 -- bare because the `error`
# and `problems` plugins that would have explained it are missing too.
#
# .dependencies is the repository's own list of what has to be there, and
# upstream installs it with `bin/grav install`. That command cannot run here:
# it is a Grav console command and needs vendor/, which the build has not
# produced yet when this hook runs. So the file is read directly and the same
# repositories are cloned, one shallow clone each, the branch it names.
#
# Parsed with awk rather than a YAML tool because the account shell has no
# python/yq: entries are the 4-space keys under `git:`, their fields are
# indented further, and the `links:` section below (symlinks for plugin
# development) starts at column 0 and ends the section. A literal 4-space
# match rather than {4}, which mawk and busybox awk do not all support.
parse_dependencies() {
    awk '
        function flush() {
            if (url != "" && path != "") {
                print url "\t" path "\t" (branch == "" ? "HEAD" : branch)
            }
            url = ""; path = ""; branch = ""
        }
        /^[^[:space:]#]/          { flush(); ins = ($0 ~ /^git:[[:space:]]*$/); next }
        !ins                      { next }
        /^    [^[:space:]]/       { flush(); next }
        /^[[:space:]]+url:/       { url = $2; next }
        /^[[:space:]]+path:/      { path = $2; next }
        /^[[:space:]]+branch:/    { branch = $2; next }
        END                       { flush() }
    ' .dependencies
}

if [ -f .dependencies ]; then
    while IFS=$'\t' read -r url path branch; do
        # The paths come out of a file in the checkout, so they are checked
        # before anything is written through them: only the two directories
        # Grav keeps extensions in, and no way back up out of the project.
        case "${path}" in
            user/plugins/*|user/themes/*) ;;
            *) echo "[grav] skipping dependency with unexpected path: ${path}" >&2; continue ;;
        esac
        case "${path}" in
            *..*) echo "[grav] skipping dependency with traversal in path: ${path}" >&2; continue ;;
        esac
        # Anything already there is left alone: a redeploy re-clones the
        # repository, but a path that survived (or was put there by hand) is
        # the operator's.
        if [ -n "$(ls -A "${path}" 2>/dev/null)" ]; then
            continue
        fi
        rm -rf "${path}"
        echo "[grav] installing ${path} from ${url} (${branch})"
        if git clone --quiet --depth 1 --branch "${branch}" "${url}" "${path}"; then
            # Not a checkout to update from: GPM installs are plain
            # directories, and five .git directories are weight the account
            # does not need.
            rm -rf "${path}/.git"
        else
            echo "[grav] failed to install ${path} from ${url}" >&2
        fi
    done < <(parse_dependencies)
fi

# Grav 2.0 trusts no X-Forwarded-* header by default and appends a
# non-standard port to its own base URL. Behind the engine's proxy that means
# every absolute URL Grav generates -- canonical links, feeds, sitemaps, the
# redirect after a form post -- comes out as http://<domain>:8000/, an
# unencrypted URL on a port nothing publishes, inside an https page.
#
#   protocol            nginx sets X-Forwarded-Proto; without this Grav reads
#                       the scheme off its own Apache and says http. HTTPS=on
#                       in the container environment does not reach it --
#                       mod_php does not publish the process environment as
#                       $_SERVER['HTTPS'], which is where Grav looks.
#   ip                  X-Forwarded-For, so logs and any rate limiting see the
#                       visitor rather than the proxy.
#   reverse_proxy_setup stops the port being appended. The engine's proxy does
#                       send X-Forwarded-Port -- the app block in
#                       templates/virtualHost-nginx-proxy.blade.php sets it,
#                       along with Proto and Host -- but Grav ignores every one
#                       of them until told to trust them, falls back to
#                       SERVER_PORT, and reports the 8000 Apache really listens
#                       on.
#
# .env is Grav's own override channel (symfony/dotenv, read before the
# configuration is compiled), so user/config/system.yaml stays exactly as the
# repository shipped it and `git pull` stays clean. Written only when absent:
# the engine keeps a .env it finds after the clone, and an operator who edits
# this one keeps their edit.
if [ ! -f .env ]; then
    cat > .env <<'EOF'
# Written by PanelAlpha: Grav runs behind a TLS-terminating reverse proxy.
GRAV_CONFIG=true
GRAV_CONFIG__system__reverse_proxy_setup=true
GRAV_CONFIG__system__http_x_forwarded__protocol=true
GRAV_CONFIG__system__http_x_forwarded__ip=true
EOF
fi
