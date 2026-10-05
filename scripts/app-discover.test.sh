#!/bin/bash
#
# The selection half of app-discover.py, checked offline: a fixed fetch is
# replayed with --reuse-raw and --no-enrich, so no source and no GitHub page is
# read.
#
#   bash scripts/app-discover.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0

check() {
    local what="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  ok    %s\n' "$what"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$what" "$expected" "$actual"
        FAIL=$((FAIL + 1))
    fi
}

cat > "$TMP/tracker.json" <<'EOF'
[{"iid": 1, "title": "Tracked", "repo": "https://github.com/Foo/Tracked"},
 {"iid": 2, "title": "Paperless NGX", "repo": "https://github.com/paperless-ngx/paperless-ngx"}]
EOF

echo '{"reported": {"github.com/old/reported": "2026-09-26"}, "enrich": {}}' > "$TMP/state.json"

cat > "$TMP/state.json.raw.json" <<'EOF'
{"raw": [
  {"repo": "https://github.com/foo/tracked.git", "name": "tracked", "source": "umbrel"},
  {"repo": "https://github.com/someone/paperless-mirror", "name": "Paperless-NGX", "source": "unraid"},
  {"repo": "https://github.com/old/reported", "name": "reported", "source": "umbrel"},
  {"repo": "https://github.com/acme/app/tree/main", "name": "App", "source": "umbrel", "stars": 200},
  {"repo": "https://github.com/Acme/App", "name": "App", "source": "runtipi"},
  {"repo": "https://github.com/big/solo", "name": "Solo", "source": "awesome-selfhosted", "stars": 50000},
  {"repo": "https://github.com/tiny/thing", "name": "Thing", "source": "umbrel", "stars": 5},
  {"repo": "https://github.com/x/node-exporter", "name": "node-exporter", "source": "unraid", "stars": 900},
  {"repo": "https://codeberg.org/forge/keep", "name": "Keep", "source": "yunohost"},
  {"repo": "https://example.com/not-a-repo", "name": "Nope", "source": "coolify"}
 ],
 "counts": {}}
EOF

python3 "$SCRIPT_DIR/app-discover.py" --tracker="$TMP/tracker.json" --state="$TMP/state.json" \
    --out="$TMP/out.json" --reuse-raw --no-enrich 2>"$TMP/err" \
    || { cat "$TMP/err"; echo "app-discover.py failed"; exit 1; }

q() { python3 -c "import json,sys; d=json.load(open('$TMP/out.json')); print($1)"; }

check "ranked by cross-listing, then stars" \
    "github.com/acme/app github.com/big/solo codeberg.org/forge/keep" \
    "$(q '" ".join(c["slug"] for c in d["candidates"])')"
check "one repo listed under two spellings is one candidate with both sources" \
    "runtipi,umbrel" "$(q '",".join(d["candidates"][0]["sources"])')"
check "under --min-stars and out-of-scope names are dropped" \
    "{'stars': 1, 'name': 1}" "$(q 'd["dropped"]')"
check "nothing tracked, mirrored under a tracked title, or already reported comes back" \
    "0" "$(q 'sum(c["slug"] in ("github.com/foo/tracked","github.com/someone/paperless-mirror","github.com/old/reported") for c in d["candidates"])')"

echo '["github.com/acme/app"]' > "$TMP/filed.json"
python3 "$SCRIPT_DIR/app-discover.py" --tracker="$TMP/tracker.json" --state="$TMP/state.json" \
    --mark-reported="$TMP/filed.json" 2>/dev/null
python3 "$SCRIPT_DIR/app-discover.py" --tracker="$TMP/tracker.json" --state="$TMP/state.json" \
    --out="$TMP/out.json" --reuse-raw --no-enrich 2>/dev/null
check "--mark-reported keeps a filed app out of the next run" \
    "github.com/big/solo codeberg.org/forge/keep" \
    "$(q '" ".join(c["slug"] for c in d["candidates"])')"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
