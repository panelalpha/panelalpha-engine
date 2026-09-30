#!/bin/sh
# First boot only: migrate, then create the owner through Papra's own sign-up
# API on a server bound to loopback inside this one-shot container (no port is
# published). The long-running app keeps registration closed.
set -e
cd /app

MARK=/app/app-data/.panelalpha-owner-seeded
say() { echo "[panelalpha/seed] $*"; }

if [ -f "$MARK" ]; then
    say "owner already seeded; nothing to do"
    exit 0
fi

: "${PAPRA_ADMIN_PASSWORD:?missing}" "${PAPRA_ADMIN_EMAIL:?missing}" "${APP_BASE_URL:?missing}"

say "running migrations"
pnpm migrate:up:prod

AUTH_IS_REGISTRATION_ENABLED=true SERVER_HOSTNAME=127.0.0.1 node dist/index.js &
pid=$!

# Sign up once the server answers; an existing owner (marker lost) is accepted.
node -e '
const base = "http://127.0.0.1:1221";
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  for (let i = 0; ; i++) {
    try { if ((await fetch(base + "/api/health")).ok) break; } catch {}
    if (i >= 60) throw new Error("server never became healthy");
    await sleep(2000);
  }
  const res = await fetch(base + "/api/auth/sign-up/email", {
    method: "POST",
    headers: { "content-type": "application/json", origin: process.env.APP_BASE_URL },
    body: JSON.stringify({ name: "Admin", email: process.env.PAPRA_ADMIN_EMAIL, password: process.env.PAPRA_ADMIN_PASSWORD }),
  });
  const body = await res.text();
  if (res.ok) { console.log("[panelalpha/seed] owner created: " + process.env.PAPRA_ADMIN_EMAIL); }
  else if (/already exists/i.test(body)) { console.log("[panelalpha/seed] owner already exists"); }
  else { throw new Error("sign-up failed: " + res.status + " " + body); }
  await sleep(3000); // let the async first-user-admin handler commit
})().catch((e) => { console.error(e.message); process.exit(1); });
' || { kill "$pid" 2>/dev/null; exit 1; }

kill "$pid"
wait "$pid" 2>/dev/null || true
touch "$MARK"
say "done"
