// Create the admin (user id 1 = NEXT_PUBLIC_ADMIN) on an empty database by
// calling Linkwarden's own POST /api/v1/users on a loopback-only server.
const { createRequire } = require('module');
const { spawn } = require('child_process');
const { PrismaClient } = createRequire('/data/packages/prisma/')('@prisma/client');

const say = (m) => console.log(`[panelalpha/seed] ${m}`);
const PORT = 3100;
const base = `http://127.0.0.1:${PORT}`;

(async () => {
  const username = process.env.LINKWARDEN_ADMIN_USER;
  const password = process.env.LINKWARDEN_ADMIN_PASSWORD;
  if (!username || !password) throw new Error('admin credentials are not set');

  const prisma = new PrismaClient();
  const count = await prisma.user.count();
  if (count > 0) {
    say(`${count} user(s) exist; admin not touched`);
    await prisma.$disconnect();
    return;
  }

  // Registration must be open for the sign-up call; this server is not published.
  const env = { ...process.env };
  delete env.NEXT_PUBLIC_DISABLE_REGISTRATION;
  const next = spawn('/data/node_modules/.bin/next', ['start', '-p', String(PORT), '-H', '127.0.0.1'],
    { cwd: '/data/apps/web', env, stdio: 'inherit' });
  try {
    const deadline = Date.now() + 240000;
    for (;;) {
      try { if ((await fetch(`${base}/api/v1/logins`)).ok) break; } catch (e) { /* booting */ }
      if (Date.now() > deadline) throw new Error('private server did not come up');
      await new Promise((r) => setTimeout(r, 1000));
    }
    const res = await fetch(`${base}/api/v1/users`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ name: 'Admin', username, password }),
    });
    const body = await res.text();
    if (res.status !== 201) throw new Error(`sign-up returned ${res.status}: ${body}`);
    const admin = await prisma.user.findFirst({ where: { username } });
    say(`admin "${username}" created (id ${admin.id})`);
    if (admin.id !== 1) say('WARNING: admin id is not 1; set NEXT_PUBLIC_ADMIN to it');
  } finally {
    next.kill('SIGTERM');
    await prisma.$disconnect();
  }
})().then(() => process.exit(0), (e) => {
  console.error(`[panelalpha/seed] failed: ${e.stack || e}`);
  process.exit(1);
});
