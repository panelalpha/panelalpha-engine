"""The scripts the `account` area runs."""

# AccountFiles

WRITE = r'''set -eu
tmp=$(mktemp -- "$1.panelalpha-XXXXXXXXXX")
trap 'rm -f -- "$tmp"' EXIT
cat > "$tmp"
chmod -- "$2" "$tmp"
mv -fT -- "$tmp" "$1"'''

REAL_DIRECTORY = r'''[ -d "$1" ] && [ ! -L "$1" ]'''


# AccountCommands

READ_REGULAR_FILE = r'''[ -f "$1" ] || { echo "Not a regular file: $1" >&2; exit 1; }; exec cat -- "$1"'''

# unzip exits 1 on a warning it extracted past
EXTRACT_ZIP = (
    r'''cd -P -- "$2" || exit 1; LC_ALL=C.UTF-8 unzip -o "$1"; status=$?; '''
    r'''[ "$status" -eq 1 ] && exit 0; exit "$status"'''
)

COPY_THROUGH_TAR = (
    r'''set -o pipefail; '''
    r'''tar -C "$1" -cf - -- "${@:3}" | tar -C "$2" -xpf - --no-same-owner --no-overwrite-dir'''
)

TAIL_LOG = (
    r'''p=$1; '''
    r'''[ -f "$p" ] && [ ! -L "$p" ] && [ "$(stat -c %h -- "$p" 2>/dev/null)" = 1 ] && exec tail -n "$2" -- "$p"; '''
    r'''exit 3'''
)

ENSURE_LOG_FILE = (
    r'''f=$1; if [ -L "$f" ]; then rm -f -- "$f" || exit 1; fi; if [ -e "$f" ] && [ ! -f "$f" ]; '''
    r'''then exit 0; fi; if [ -f "$f" ] && [ "$(stat -c %u:%g -- "$f")" != "$2" ]; '''
    r'''then t=$(mktemp -- "$f.XXXXXX") || exit 1; cat -- "$f" > "$t" 2>/dev/null || { rm -f -- "$t"; '''
    r'''echo "cannot read $f, left it as it is" >&2; exit 1; }; mv -f -- "$t" "$f" || exit 1; fi; '''
    r'''touch -- "$f" && chmod 664 -- "$f"'''
)

ENTER_DIRECTORY = r'''set -euf
cd -P -- "$1"
home=$(pwd -P)
IFS=/
for part in $2; do
    if [ -L "$part" ]; then
        echo "Refusing to use '$1/$2': '$part' is a symlink." >&2
        exit 3
    fi
    [ -d "$part" ] || mkdir -m "$3" -- "$part"
    cd -P -- "$part"
done
case "$(pwd -P)/" in
    "$home"/*) ;;
    *) echo "Refusing to use '$1/$2': it leads out of the account's home." >&2; exit 3 ;;
esac
chmod "$3" .'''

WRITE_FILE = ENTER_DIRECTORY + r'''
tmp=$(mktemp .panelalpha-XXXXXXXXXX)
trap 'rm -f -- "$tmp"' EXIT
cat > "$tmp"
chmod 0644 -- "$tmp"
mv -fT -- "$tmp" "$4"'''

REMOVE_FILE = r'''set -euf
cd -P -- "$1"
IFS=/
for part in $2; do
    if [ -L "$part" ]; then
        echo "Refusing to use '$1/$2': '$part' is a symlink." >&2
        exit 3
    fi
    [ -d "$part" ] || exit 0
    cd -P -- "$part"
done
rm -f -- "$3"'''


# AccountFileManager

CONFINE_SCRIPT = (
    r'''home=$(realpath -- "$1") || exit 1; shift; for p; '''
    r'''do case "$p" in F:*) r=$(realpath -m -- "${p#F:}") ;; *) q=${p#E:}; q=${q%/}; '''
    r'''r=$(realpath -m -- "$(dirname -- "$q")")/$(basename -- "$q") ;; esac; '''
    r'''case "$r" in "$home"|"$home"/*) ;; '''
    r'''*) echo "Refusing ${p#?:}: it resolves outside the project home" >&2; exit 3 ;; esac; done'''
)

ENTRY_TYPE_SCRIPT = (
    r'''if [ ! -e "$1" ]; then if [ -L "$1" ]; then echo dangling; else echo missing; fi; '''
    r'''elif [ -d "$1" ]; then if [ -L "$2" ]; then echo dir-link; else echo dir; fi; else echo file; fi'''
)

RESOLVED_FILE_SCRIPT = r'''[ -f "$1" ] && realpath -e -- "$1"'''

ENTRY_FIELDS = r'''%f\0%y\0%s\0%T@\0%m\0%U\0%G\0%l\0%Y\0'''

LIST_PAGE_SCRIPT = (
    r'''cd -- "$1" && [ -r . ] || exit 1; limit=$2; shift 2; page=$(mktemp) || exit 1; '''
    r'''trap 'rm -f -- "$page"' EXIT; '''
    r'''find . -mindepth 1 -maxdepth 1 "$@" \( -type d -printf '0%p\0' -o -printf '1%p\0' \) | LC_ALL=C sort -z | sed -z -n -e "1,${limit}{s/^.//;w $page" -e '}' -e '$='; '''
    r"""find -files0-from "$page" -maxdepth 0 -printf '%f\0%y\0%s\0%T@\0%m\0%U\0%G\0%l\0%Y\0'"""
)

# a folder the account cannot write to, the destination or the one it would be made in, is printed with exit 6
MOVE_UNPACKED_SCRIPT = (
    r'''src=$1; home=$(realpath -- "$2") || exit 1; p=${3%/}; d=$p; while [ ! -e "$d" ]; do d=$(dirname -- "$d"); done; '''
    r'''[ "$d" = "$p" ] || { [ -d "$d" ] && [ -w "$d" ]; } || { printf %s "$d"; exit 6; }; '''
    r'''mkdir -p -- "$3" && cd -- "$3" || exit 1; '''
    r'''case $(pwd -P) in "$home"|"$home"/*) ;; '''
    r'''*) echo "Refusing $3: it resolves outside the project home" >&2; exit 3 ;; esac; '''
    r'''[ -w . ] || { printf %s "$p"; exit 6; }; set --; for e in "$src"/* "$src"/.[!.]* "$src"/..?*; '''
    r'''do if [ -e "$e" ] || [ -L "$e" ]; then set -- "$@" "${e##*/}"; fi; done; taken=0; for n; '''
    r'''do if [ -e "./$n" ] || [ -L "./$n" ]; then printf '%s\0' "$n"; taken=1; fi; done; '''
    r'''[ "$taken" = 0 ] || exit 4; for n; '''
    r'''do mv --update=none-fail -T -- "$src/$n" "./$n" || { printf '%s\0' "$n"; exit 5; }; done'''
)

WRITE_AS_USER_SCRIPT = (
    r'''dir=$(dirname -- "$2"); mkdir -p -- "$dir" 2>/dev/null; '''
    r'''[ -w "$2" ] || { [ -d "$dir" ] && [ -w "$dir" ]; } || exit 6; '''
    r'''cat -- "$1" > "$2" && chmod 644 -- "$2"'''
)

MKDIR_SCRIPT = (
    r'''p=${1%/}; shift; if [ ! -e "$p" ]; then d=$(dirname -- "$p"); while [ ! -e "$d" ]; '''
    r'''do d=$(dirname -- "$d"); done; [ -d "$d" ] && [ -w "$d" ] || { printf %s "$d"; exit 6; }; fi; '''
    r'''exec mkdir "$@"'''
)

STAGE_COPY_SCRIPT = r'''[ -f "$1" ] || { echo "Not a regular file: $1" >&2; exit 1; }; cat -- "$1" > "$2"'''

GUNZIP_SCRIPT = r'''gzip -dc -- "$1" > "$2"'''
