// Migrate through a private `evershop start` (migrations run only there), then
// create the admin with EverShop's own user:create while admin_user is empty.
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const req = createRequire('/app/node_modules/@evershop/evershop/package.json');
const { Client } = req('pg');
const say = (m) => console.log(`[panelalpha/seed] ${m}`);
const PORT = 3100;

const db = () => new Client({
  host: process.env.DB_HOST, port: Number(process.env.DB_PORT),
  user: process.env.DB_USER, password: process.env.DB_PASSWORD, database: process.env.DB_NAME,
});

async function query(sql, params = []) {
  const c = db();
  await c.connect();
  try { return (await c.query(sql, params)).rows; } finally { await c.end(); }
}

async function main() {
  const { ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD } = process.env;
  if (!ADMIN_EMAIL || !ADMIN_PASSWORD) throw new Error('admin credentials are not set');

  // Bound to loopback by the container's network namespace: nothing publishes 3100.
  const server = spawn('npx', ['evershop', 'start'], {
    cwd: '/app', env: { ...process.env, PORT: String(PORT) }, stdio: 'inherit', detached: true,
  });
  try {
    const deadline = Date.now() + 300000;
    for (;;) {
      try { if ((await fetch(`http://127.0.0.1:${PORT}/`)).status < 500) break; } catch { /* booting */ }
      if (server.exitCode !== null) throw new Error(`evershop start exited ${server.exitCode}`);
      if (Date.now() > deadline) throw new Error('private server did not come up');
      await new Promise((r) => setTimeout(r, 2000));
    }
  } finally {
    try { process.kill(-server.pid, 'SIGTERM'); } catch { /* gone */ }
  }
  say('migrations applied');

  const [{ n }] = await query('SELECT count(*)::int AS n FROM admin_user');
  if (n > 0) { say(`${n} admin(s) exist; not touched`); return; }

  // user:create exits 0 even when the insert fails, so the result is checked below.
  const r = spawnSync('npx', ['evershop', 'user:create', '--name', ADMIN_NAME || 'Administrator',
    '--email', ADMIN_EMAIL, '--password', ADMIN_PASSWORD], { cwd: '/app', stdio: 'inherit' });
  const rows = await query('SELECT admin_user_id FROM admin_user WHERE email = $1', [ADMIN_EMAIL]);
  if (r.status !== 0 || rows.length !== 1) throw new Error('user:create did not create the admin');
  say(`admin ${ADMIN_EMAIL} created (id ${rows[0].admin_user_id})`);
}

main().then(() => process.exit(0), (e) => {
  console.error(`[panelalpha/seed] failed: ${e.stack || e}`);
  process.exit(1);
});
