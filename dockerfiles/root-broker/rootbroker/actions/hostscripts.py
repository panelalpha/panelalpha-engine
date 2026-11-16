"""The scripts the `host` and `firewall` areas run, values quoted as PHP's escapeshellarg() does.

host-cases.json holds the expected command for real callers' values, compared byte for byte by the tests.
"""

from ..composepolicy import LXCFS, LXCFS_FILES
from .shell import escapeshellarg

ENGINE_UPDATE_LOGS = '/opt/panelalpha/log/engine-updates'
ENGINE_UPDATE_PENDING = 'pending'
WORDPRESS_VERSION_FILE = 'wp-includes/version.php'
CACHE_NAMES = ('node_modules', 'npm', 'pnpm', 'yarn', 'bun', 'composer', 'pip', 'go', 'cargo', 'm2', 'maven', 'gradle')
COMPOSER_CACHE = 'composer'


# EngineUpdaterScripts

def enqueue_engine_update(engine_dir, license_key):
    return 'echo ' + escapeshellarg(updater_job(engine_dir, license_key)) + ' | at now'


def updater_job(engine_dir, license_key):
    """PHP's empty() drops a key of '0' as well as no key."""
    updater = 'bash ' + escapeshellarg(engine_dir + '/updater.sh') + ' -f --background'
    if license_key not in ('', '0'):
        updater += ' ' + escapeshellarg(license_key)
    tmp = ENGINE_UPDATE_LOGS + '/tmp'
    return f'{updater} > {tmp}/stdout 2> {tmp}/stderr || echo $? > {tmp}/exit_code'


def seed_pending_placeholder():
    logs = ENGINE_UPDATE_LOGS
    return (f'mkdir -p {logs}/tmp && date +%s > {logs}/tmp/{ENGINE_UPDATE_PENDING}'
            f' && rm -f {logs}/tmp/pid {logs}/tmp/exit_code {logs}/tmp/stdout {logs}/tmp/stderr'
            f' && ln -sfn {logs}/tmp {logs}/latest')


def record_refusal():
    tmp = ENGINE_UPDATE_LOGS + '/tmp'
    return f'printf %s "$1" > {tmp}/stderr && echo "$2" > {tmp}/exit_code'


# HostReadings

PROJECT_DISK = r'''set -u
low=
command -v nice >/dev/null 2>&1 && low="nice -n 19"
command -v ionice >/dev/null 2>&1 && low="$low ionice -c3"
set -- __PAIRS__
while [ $# -ge 2 ]; do
    name=$1 home=$2
    shift 2
    [ -d "$home" ] || continue
    files=$($low du -sx -B1 --exclude="$home/docker" "$home" 2>/dev/null | cut -f1)
    docker=
    [ -d "$home/docker" ] && docker=$($low du -sx -B1 "$home/docker" 2>/dev/null | cut -f1)
    printf 'D\t%s\t%s\t%s\n' "$name" "$files" "$docker"
done
exit 0'''

CLASSIC_APP_TYPES = r'''set -u
core=__CORE_FILE__
set -- __ARGS__
while [ $# -ge 4 ]; do
    name=$1 home=$2 docroot=$3 install=$4
    shift 4
    [ -d "$home" ] || continue
    type=php
    path=
    if [ -f "$docroot/$core" ]; then
        type=wordpress
    elif [ -n "$install" ] && [ -f "$install/$core" ]; then
        type=wordpress
        path=$install
    fi
    printf 'T\t%s\t%s\t%s\n' "$name" "$type" "$path"
done
exit 0'''


def project_disk(homes):
    pairs = ''.join(escapeshellarg(name) + ' ' + escapeshellarg(home.rstrip('/')) + ' ' for name, home in homes)
    return PROJECT_DISK.replace('__PAIRS__', pairs)


def classic_app_types(docroots):
    args = ''.join(f'{escapeshellarg(name)} {escapeshellarg(home)} {escapeshellarg(docroot)} {escapeshellarg(install)} ' for name, home, docroot, install in docroots)
    return CLASSIC_APP_TYPES.replace('__ARGS__', args).replace('__CORE_FILE__', escapeshellarg(WORDPRESS_VERSION_FILE))


def edge_probe(domain, ip, timeout, https):
    timeout = max(1, int(timeout))
    host, addr, secure = escapeshellarg(domain), escapeshellarg(ip), 1 if https else 0
    return f'''set -u
host={host}
addr={addr}
f=$(mktemp)
scheme=http
out=000
if [ {secure} = 1 ]; then
  out=$(curl -sS -k -m {timeout} -o "$f" -w '%{{http_code}} %{{redirect_url}}' --resolve "$host:443:$addr" "https://$host/" 2>/dev/null) || out=000
  [ "${{out%% *}}" = 000 ] || scheme=https
fi
if [ "$scheme" = http ]; then
  out=$(curl -sS -m {timeout} -o "$f" -w '%{{http_code}} %{{redirect_url}}' -H "Host: $host" "http://$addr/" 2>/dev/null) || out=000
fi
code=${{out%% *}}
location=$(printf '%s' "${{out#* }}" | tr -d '\t\r\n')
[ "$location" = "$out" ] && location=
marker=no
if [ "$code" = "404" ] && grep -q 'Page Not Found' "$f" && grep -q 'error-page' "$f"; then marker=yes; fi
printf '%s\t%s\t%s\t\t%s\t%s' "${{code:-000}}" "$(head -c 2048 "$f" | sha256sum | cut -d' ' -f1)" "$marker" "$location" "$scheme"
rm -f "$f"
exit 0'''


def lxcfs_files():
    return 'for f in ' + ' '.join(LXCFS_FILES) + f'; do [ -f {LXCFS}/$f ] && echo "$f"; done; true'


# ProjectCachePreparation

def project_cache_preparation(directory, legacy_js, legacy_composer, identity):
    caches = ' '.join(escapeshellarg(directory + '/' + name) for name in CACHE_NAMES)
    group = identity.split(':')[1]
    d, js, composer = escapeshellarg(directory), escapeshellarg(legacy_js), escapeshellarg(legacy_composer)
    return (
        f'mkdir -p -- {d} && chown -h -- {escapeshellarg("0:" + group)} {d} && chmod -- 750 {d} && touch -- {d} || exit 1'
        f'; if [ -d {js} ]; then mv -- {js}/* {d}/ 2>/dev/null; rmdir -- {js} 2>/dev/null; fi'
        f'; if [ -d {composer} ] && [ ! -d {d}/{COMPOSER_CACHE} ]; then mv -- {composer} {d}/{COMPOSER_CACHE} 2>/dev/null; fi'
        f'; for cache in {caches}; do '
        'if [ -L "$cache" ] || { [ -e "$cache" ] && [ ! -d "$cache" ]; }; then rm -rf -- "$cache" || exit 1; fi; '
        f'mkdir -p -- "$cache" && chown -h -- {escapeshellarg(identity)} "$cache" || exit 1; done'
    )


# RootFirewall

WRITE_LIST = r'''mkdir -p "$(dirname "$1")" && printf "%s" "$2" > "$1"'''

ENSURE_LOCK = r'''mkdir -p "$(dirname "$1")" && touch "$1" && chmod 0644 "$1"'''

SNAPSHOT_RULES = r'''snapshot=$1; shift; rm -rf "$snapshot" && mkdir -p "$snapshot" && for f; do [ ! -e "$f" ] || cp -p "$f" "$snapshot/" || exit 1; done && sed -n "s/^ENABLED=//p" "$snapshot/ufw.conf"'''

RESTORE_RULES = r'''snapshot=$1; shift; for f; do b="$snapshot/${f##*/}"; [ ! -e "$b" ] || cp -p "$b" "$f" || exit 1; done'''
