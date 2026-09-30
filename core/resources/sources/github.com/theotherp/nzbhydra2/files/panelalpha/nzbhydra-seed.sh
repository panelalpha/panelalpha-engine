#!/bin/bash
# Seeds /config/nzbhydra.yml once, only if absent: an existing config (and any
# password or key changed in the UI since) is never touched.
set -euo pipefail

cfg=/config/nzbhydra.yml
if [ -e "$cfg" ]; then
    echo "[nzbhydra] $cfg exists; left alone"
    exit 0
fi
: "${NZBHYDRA_ADMIN_USER:?}" "${NZBHYDRA_ADMIN_HASH:?}" "${NZBHYDRA_API_KEY:?}"

# The image's own first-boot config, with form login, every area restricted to
# the admin, a fixed API key and the welcome dialog marked as shown.
python3 - /defaults/nzbhydra.yml "$cfg.tmp" <<'PY'
import os, sys
src = open(sys.argv[1]).read()
user = ('  users:\n'
        '    - username: "%s"\n'
        '      password: "{bcrypt}%s"\n'
        '      maySeeAdmin: true\n'
        '      maySeeDetailsDl: true\n'
        '      maySeeStats: true\n'
        '      showIndexerSelection: true\n') % (os.environ["NZBHYDRA_ADMIN_USER"], os.environ["NZBHYDRA_ADMIN_HASH"])
edits = [
    ('  authType: "NONE"\n', '  authType: "FORM"\n'),
    ('  restrictAdmin: false\n', '  restrictAdmin: true\n'),
    ('  restrictDetailsDl: false\n', '  restrictDetailsDl: true\n'),
    ('  restrictIndexerSelection: false\n', '  restrictIndexerSelection: true\n'),
    ('  restrictSearch: false\n', '  restrictSearch: true\n'),
    ('  restrictStats: false\n', '  restrictStats: true\n'),
    ('  users: []\n', user),
    ('\n  apiKey: null\n', '\n  apiKey: "%s"\n' % os.environ["NZBHYDRA_API_KEY"]),
    ('  welcomeShown: false\n', '  welcomeShown: true\n'),
    ('  startupBrowser: true\n', '  startupBrowser: false\n'),
]
for old, new in edits:
    if src.count(old) != 1:
        sys.exit("[nzbhydra] default config changed, cannot seed: %r" % old)
    src = src.replace(old, new)
open(sys.argv[2], "w").write(src)
PY
mv "$cfg.tmp" "$cfg"
# The image's init (lsiown) hands /config to its runtime user on start.
echo "[nzbhydra] seeded $cfg with form login for ${NZBHYDRA_ADMIN_USER}"
