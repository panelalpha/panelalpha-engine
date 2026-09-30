#!/bin/sh
# First boot: the backend fills its infra_config table and then stops itself,
# relying on a container restart. Run that boot here, one-shot, so the app
# container starts once. Later boots answer /ping without stopping: done.
cd /dist/backend || exit 1
node dist/src/main.js > /tmp/backend.log 2>&1 &
pid=$!
i=0
while kill -0 "$pid" 2>/dev/null; do
    if ! grep -q "Stopping app in" /tmp/backend.log \
        && curl -sf -o /dev/null http://127.0.0.1:8080/ping; then
        kill "$pid"; wait "$pid"
        echo "[panelalpha] hoppscotch: infra config already populated"
        exit 0
    fi
    i=$((i + 1))
    if [ "$i" -gt 180 ]; then
        cat /tmp/backend.log; kill "$pid"
        echo "[panelalpha] hoppscotch: backend did not come up in 180s" >&2
        exit 1
    fi
    sleep 1
done
wait "$pid"; rc=$?
grep -q "Stopping app in" /tmp/backend.log || { cat /tmp/backend.log; exit 1; }
echo "[panelalpha] hoppscotch: infra config populated (backend exit $rc)"
exit 0
