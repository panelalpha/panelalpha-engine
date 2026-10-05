#!/usr/bin/env bash
# Run the Node.js app-support batch against a test engine host, with
# observability that survives the shell that started it.
#
#   scripts/tools/run-node-batch.sh <apps.json> <outdir> [--parallel=N] [--timeout=N]
#
# Started detached (nohup), so progress has to be readable from the filesystem
# rather than from a terminal. Three artifacts, all under <outdir>:
#
#   run.log     the runner's own output, line-buffered
#   run.status  one line per state change: `started`, `heartbeat`, `done`
#   summary.md  written once, at the end, from the runner's summary.json
#
# `run.status` exists because a batch that wrote only `run.log` could not be
# told apart from a batch that had died: an idle runner and a dead one both
# leave the log unchanged. The heartbeat makes liveness a fact rather than an
# inference -- and since a host reboot is exactly what killed the previous
# batch, that distinction is the difference between waiting and wasting an hour.
set -euo pipefail

APPS="${1:?usage: run-node-batch.sh <apps.json> <outdir> [--parallel=N]}"
OUTDIR="${2:?usage: run-node-batch.sh <apps.json> <outdir> [--parallel=N]}"
shift 2

PARALLEL=4
TIMEOUT=2400
for arg in "$@"; do
  case "$arg" in
    --parallel=*) PARALLEL="${arg#*=}" ;;
    --timeout=*)  TIMEOUT="${arg#*=}" ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

ENGINE_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
API_URL="${PA_API_URL:?set PA_API_URL, e.g. https://203.0.113.58:2011/api}"
: "${PA_API_TOKEN:?set PA_API_TOKEN}"
export PA_API_URL PA_API_TOKEN

mkdir -p "$OUTDIR"
LOG="$OUTDIR/run.log"
STATUS="$OUTDIR/run.status"

COUNT=$(python3 -c "import json,sys;print(len(json.load(open(sys.argv[1]))))" "$APPS")

# Truncate, so a reader never sees a stale `done` from a previous run.
: > "$STATUS"
echo "started $(date -Is) apps=$COUNT parallel=$PARALLEL timeout=$TIMEOUT api=$API_URL" >> "$STATUS"

# The heartbeat records progress, not just liveness: the count of apps that
# have reached a verdict. A run that is alive but not advancing looks the same
# as a stuck one from the outside, and that was the failure mode nobody caught.
(
  while sleep 60; do
    DONE=$(python3 - "$OUTDIR" <<'PY' 2>/dev/null || echo 0
import json, pathlib, sys
out = pathlib.Path(sys.argv[1])
n = 0
for d in out.iterdir():
    if d.is_dir() and (d / 'result.json').exists():
        try:
            r = json.loads((d / 'result.json').read_text())
        except Exception:
            continue
        if r.get('verdict'):
            n += 1
print(n)
PY
)
    echo "heartbeat $(date -Is) verdicts=$DONE/$COUNT" >> "$STATUS"
  done
) &
HEARTBEAT=$!
trap 'kill $HEARTBEAT 2>/dev/null || true' EXIT

set +e
python3 "$ENGINE_DIR/scripts/tools/app-support-batch.py" \
  --apps="$APPS" --outdir="$OUTDIR" --redo \
  --parallel="$PARALLEL" --timeout="$TIMEOUT" \
  --email="node-batch-$(date +%m%d)-@example.com" \
  > "$LOG" 2>&1
RC=$?
set -e

kill $HEARTBEAT 2>/dev/null || true

echo "done $(date -Is) rc=$RC" >> "$STATUS"

{
  echo "# Node.js app-support batch"
  echo
  echo "- api: \`$API_URL\`"
  echo "- apps: $COUNT, parallel=$PARALLEL, timeout=${TIMEOUT}s"
  echo "- exit code: $RC"
  echo
  echo "| app | verdict | deploy | secs | http | strategy |"
  echo "|---|---|---|---|---|---|"
  python3 - "$OUTDIR" <<'PY'
import json, pathlib, sys
out = pathlib.Path(sys.argv[1])
rows = []
for d in sorted(out.iterdir()):
    f = d / 'result.json'
    if not (d.is_dir() and f.exists()):
        continue
    try:
        r = json.loads(f.read_text())
    except Exception:
        continue
    dep = r.get('deploy') or {}
    insp = r.get('inspect') or {}
    rows.append((
        r.get('title') or d.name,
        r.get('verdict') or '?',
        dep.get('status') or '?',
        dep.get('seconds'),
        dep.get('http') if dep.get('http') else r.get('http'),
        insp.get('strategy') if isinstance(insp, dict) else insp,
    ))
for t, v, s, sec, http, strat in sorted(rows, key=lambda x: (x[1], x[0])):
    secs = f'{sec:.0f}' if isinstance(sec, (int, float)) else '-'
    print(f'| {t} | {v} | {s} | {secs} | {http if http is not None else "-"} | {strat} |')
print()
from collections import Counter
c = Counter(v for _, v, *_ in rows)
print('## Verdicts')
print()
for k, n in c.most_common():
    print(f'- {k}: {n}')
print(f'- **total: {len(rows)}**')
PY
} > "$OUTDIR/summary.md" 2>/dev/null

exit $RC
