#!/bin/sh
# Pins login and listener settings in config.xml before Whisparr starts.
# Whisparr v2 reads them only from config.xml (no WHISPARR__AUTH__ env).
set -eu
CFG=/config/config.xml

[ -s "$CFG" ] || printf '<Config>\n</Config>\n' > "$CFG"

pin() {
    if grep -Eq "<$1>|<$1 */>" "$CFG"; then
        sed -i -E "s#<$1>[^<]*</$1>|<$1 */>#<$1>$2</$1>#" "$CFG"
    else
        sed -i "s#</Config>#  <$1>$2</$1>\n</Config>#" "$CFG"
    fi
}

# Forms (or Basic, if the admin chose it) and never "disabled for local
# addresses": behind the proxy every client is local.
method=$(sed -n 's#.*<AuthenticationMethod>\([^<]*\)</AuthenticationMethod>.*#\1#p' "$CFG")
case "$method" in
    Forms|forms|Basic|basic) ;;
    *) pin AuthenticationMethod Forms ;;
esac
pin AuthenticationRequired Enabled
pin Port 6969
pin BindAddress '*'
pin UrlBase ''

# The app runs as PUID 1000; the library volume is created root-owned.
chown 1000:1000 /config /library "$CFG"
echo "whisparr: config.xml pinned (auth $(sed -n 's#.*<AuthenticationMethod>\([^<]*\)<.*#\1#p' "$CFG"))"
