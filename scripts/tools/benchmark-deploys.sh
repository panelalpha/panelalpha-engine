#!/usr/bin/env bash
#
# Engine deploy speed, and proof the caches are working.
#
# Each fixture is deployed three ways:
#
#   cold     first deploy into a fresh account — nothing of this repo cached
#   warm     the same account rebuilt in place — the production cache path
#   restart  container boot of an already-built app — no build at all
#
# It exits non-zero when a fixture detects as the wrong strategy, or when a
# warm rebuild reports fewer cached layers than MIN_WARM_RATIO. That second
# check is the reason this exists: a warm deploy that rebuilds every layer is
# a cache regression, and totals alone hide it behind ordinary variance.
#
# Usage:
#   scripts/tools/benchmark-deploys.sh [--host root@HOST] [--apps a,b] [--keep] [--json]
#                                [--timeout SECONDS]   (per fixture, default 900)
#
# See AGENTS.md §9.
set -uo pipefail

HOST="${BENCH_HOST:?set BENCH_HOST to the engine host, e.g. root@engine.example.com}"
CORE="${BENCH_CORE:-}"
MIN_WARM_RATIO="${MIN_WARM_RATIO:-0.5}"
# Bound every fixture. Without this one pathological repo stalls the suite
# forever: BookStack sat in a cold deploy for 40 minutes and the run had no way
# to give up on it. A benchmark that can hang is not a benchmark.
FIXTURE_TIMEOUT="${FIXTURE_TIMEOUT:-900}"
APPS=""; KEEP=0; JSON=0

while [ $# -gt 0 ]; do
  case "$1" in
    --host) HOST="$2"; shift 2 ;;
    --apps) APPS="$2"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --json) JSON=1; shift ;;
    --timeout) FIXTURE_TIMEOUT="$2"; shift 2 ;;
    -h|--help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown flag: $1" >&2; exit 2 ;;
  esac
done

# name | repo | branch | expected strategy
#
# Two fixtures per platform, so a detection regression shows as a pair rather
# than as one repo somebody can dismiss as odd. Every expectation here was
# taken from the engine itself, not guessed — re-derive with:
#   php -r 'require "vendor/autoload.php";
#           print_r(App\Lib\Deploy\Inspect\AppInspector::inspect($argv[1])["application"]);' <dir>
FIXTURES=(
  # static — HTML with no package.json; must never reach railpack
  "bstatic1|https://github.com/octocat/Spoon-Knife||static"
  "bstatic2|https://github.com/mdn/beginner-html-site-styled||static"
  # compose — the repository ships its own stack
  "bcompose1|https://github.com/knadh/listmonk||compose"
  # umami pulls two prebuilt images; example-voting-app was tried here and is
  # unfit — it builds .NET, Python and Node from source, and one `dotnet
  # restore` alone took 607s before failing to reach nuget. A speed fixture
  # must not be a proxy for someone else's package registry.
  "bcompose2|https://github.com/umami-software/umami||compose"
  # dockerfile — a root Dockerfile outranks the framework underneath it.
  # A fixture here has to be a repo whose own Dockerfile still builds:
  # hagopj13/node-express-boilerplate exits 127 (its node:alpine no longer
  # ships yarn — AGENTS.md §6 already lists it as upstream-broken) and
  # docker/getting-started exits 1. Neither is an engine fault, and neither
  # belongs in a suite whose job is to catch engine faults.
  "bdocker1|https://github.com/docker/welcome-to-docker||dockerfile"
  "bdocker2|https://github.com/gothinkster/node-express-realworld-example-app||dockerfile"
  # php — composer.json, no artisan
  "bphp1|https://github.com/matomo-org/matomo|6.x-dev|php"
  "bphp2|https://github.com/getgrav/grav||php"
  # laravel — composer.json plus artisan
  "blaravel1|https://github.com/BookStackApp/BookStack|release|laravel"
  "blaravel2|https://github.com/laravel/laravel||laravel"
  # express / fastify — node frameworks with no Dockerfile
  "bexpress1|https://github.com/heroku/node-js-getting-started||express"
  "bexpress2|https://github.com/Azure-Samples/nodejs-docs-hello-world||express"
  "bfastify1|https://github.com/fastify/fastify-example-todo||fastify"
  "bfastify2|https://github.com/delvedor/fastify-example||fastify"
  # vite — builds to static output served by nginx
  "bvite1|https://github.com/vuejs/create-vue||vite"
  "bvite2|https://github.com/mdn/todo-vue||vite"
  # railpack — recognised by a runtime, claimed by no platform
  "brailpack1|https://github.com/sveltejs/template||railpack"
  "brailpack2|https://github.com/johnpapa/node-hello||railpack"
)

HERE="$(cd "$(dirname "$0")" && pwd)"
rex() { ssh -o BatchMode=yes "$HOST" "$@"; }
core() { rex "docker exec $CORE $*"; }
jq_get() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1)" 2>/dev/null; }

# Compose prefixes the core container with the project name; ask the host's stack.
if [ -z "$CORE" ]; then
  CORE=$(rex "docker ps --filter label=com.docker.compose.project.working_dir=/opt/panelalpha/shared-hosting \
    --filter label=com.docker.compose.service=core --format '{{.Names}}'" | head -n 1)
  [ -n "$CORE" ] || { echo "no engine core container found on $HOST" >&2; exit 2; }
fi

# Ship the helpers rather than assuming they are already on the host.
for f in deploy-fixture.php restart-app.php; do
  scp -q -o BatchMode=yes "$HERE/benchmark/$f" "$HOST:/tmp/$f" || { echo "cannot reach $HOST" >&2; exit 2; }
  rex "docker cp /tmp/$f $CORE:/tmp/$f" >/dev/null
done

FAILURES=0; RESULTS="[]"
[ "$JSON" -eq 0 ] && printf '%-13s %-9s %8s %8s %8s %7s %7s  %s\n' \
  APP STRATEGY COLD WARM RESTART LAYERS CACHED VERDICT

for row in "${FIXTURES[@]}"; do
  IFS='|' read -r NAME REPO BRANCH EXPECT <<< "$row"
  [ -n "$APPS" ] && [[ ",$APPS," != *",$NAME,"* ]] && continue

  core "php /var/www/html/artisan users:delete --force $NAME" >/dev/null 2>&1

  COLD_JSON=$(timeout "$FIXTURE_TIMEOUT" ssh -o BatchMode=yes "$HOST" \
    "docker exec $CORE php /tmp/deploy-fixture.php $NAME $REPO $BRANCH")
  if [ -z "$COLD_JSON" ]; then
    COLD_JSON='{"seconds":null,"strategy":null,"status":"timeout",
                "error":"exceeded '"$FIXTURE_TIMEOUT"'s"}'
    core "php /var/www/html/artisan users:delete --force $NAME" >/dev/null 2>&1
  fi
  COLD=$(echo "$COLD_JSON" | jq_get '["seconds"]')
  STRATEGY=$(echo "$COLD_JSON" | jq_get '["strategy"] or ""')
  STATUS=$(echo "$COLD_JSON" | jq_get '["status"] or ""')
  ERROR=$(echo "$COLD_JSON" | jq_get '["error"] or ""')

  COLD_TIMINGS=$(core "php /var/www/html/artisan project:deploy:timings $NAME --json" 2>/dev/null)

  WARM_START=$(date +%s)
  core "php /var/www/html/artisan users:rebuild --username=$NAME" >/dev/null 2>&1
  WARM=$(( $(date +%s) - WARM_START ))

  TIMINGS=$(core "php /var/www/html/artisan project:deploy:timings $NAME --json" 2>/dev/null)
  LAYERS=$(echo "$TIMINGS" | jq_get '["build"]["step_count"]' || echo 0)
  CACHED=$(echo "$TIMINGS" | jq_get '["build"]["cached_steps"]' || echo 0)
  PHASES=$(printf '%s\n%s\n' "$COLD_TIMINGS" "$TIMINGS" | python3 -c '
import json, sys
raw = sys.stdin.read().strip().split("\n{")
docs = []
for i, chunk in enumerate(raw):
    try:
        docs.append(json.loads(chunk if i == 0 else "{" + chunk))
    except Exception:
        docs.append(None)
cold, warm = (docs + [None, None])[:2]
def ph(d):
    return {p["name"]: p["seconds"] for p in (d or {}).get("phases", [])}
def sl(d):
    return [(s["command"][:44], s["seconds"], s["cached"]) for s in (d or {}).get("build", {}).get("slowest", [])[:4]]
c, w = ph(cold), ph(warm)
names = [n for n in ["preparing","cloning","detect","base_images","build","start_to_answer"] if n in c or n in w]
if names:
    print("    %-16s %8s %8s" % ("phase", "cold", "warm"))
    for n in names:
        print("    %-16s %7ss %7ss" % (n, c.get(n,"-"), w.get(n,"-")))
for cmd, secs, cached in sl(cold):
    print("      cold layer  %-44s %6ss%s" % (cmd, secs, "  (cached)" if cached else ""))
for cmd, secs, cached in sl(warm):
    print("      warm layer  %-44s %6ss%s" % (cmd, secs, "  (cached)" if cached else ""))
' 2>/dev/null)
  LAYERS=${LAYERS:-0}; CACHED=${CACHED:-0}

  RESTART=$(core "php /tmp/restart-app.php $NAME" 2>/dev/null | jq_get '["seconds"]')

  VERDICT="ok"
  if [ "$STATUS" != "success" ]; then
    # Print the reason, not just the fact. A bare "DEPLOY FAILED" sends the
    # reader back to the host to find out what every run already knew.
    VERDICT="DEPLOY FAILED: $(echo "$ERROR" | cut -c1-90)"; FAILURES=$((FAILURES+1))
  elif [ -n "$STRATEGY" ] && [ "$STRATEGY" != "$EXPECT" ]; then
    VERDICT="STRATEGY: got '$STRATEGY', want '$EXPECT'"; FAILURES=$((FAILURES+1))
  elif [ "$LAYERS" -gt 0 ]; then
    RATIO=$(echo "scale=3; $CACHED / $LAYERS" | bc)
    if [ "$(echo "$RATIO < $MIN_WARM_RATIO" | bc)" = "1" ]; then
      VERDICT="CACHE: warm hit ratio $RATIO < $MIN_WARM_RATIO"; FAILURES=$((FAILURES+1))
    else
      VERDICT="ok (cache $RATIO)"
    fi
  fi

  if [ "$JSON" -eq 1 ]; then
    RESULTS=$(python3 -c "
import json,sys
r=json.loads('''$RESULTS''')
r.append({'app':'$NAME','strategy':'$STRATEGY','cold':float('${COLD:-0}'),'warm':$WARM,
          'restart':float('${RESTART:-0}'),'layers':$LAYERS,'cached':$CACHED,'verdict':'''$VERDICT'''})
print(json.dumps(r))")
  else
    printf '%-13s %-9s %7ss %7ss %7ss %7s %7s  %s\n' \
      "$NAME" "${STRATEGY:-?}" "${COLD:-?}" "$WARM" "${RESTART:-?}" "$LAYERS" "$CACHED" "$VERDICT"
    # The totals say a fixture got slower; the phases say which part did.
    [ -n "$PHASES" ] && echo "$PHASES"
  fi

  [ "$KEEP" -eq 0 ] && core "php /var/www/html/artisan users:delete --force $NAME" >/dev/null 2>&1
done

[ "$JSON" -eq 1 ] && echo "$RESULTS"

if [ "$FAILURES" -gt 0 ]; then
  echo >&2
  echo "FAILED: $FAILURES fixture(s). See AGENTS.md §9 for what each verdict means." >&2
  exit 1
fi
exit 0
