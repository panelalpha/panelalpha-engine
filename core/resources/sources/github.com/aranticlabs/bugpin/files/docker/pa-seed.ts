// PanelAlpha: seed BugPin's first admin with a controlled password before the
// server's own bootstrap runs.
//
// BugPin hardcodes its first-admin credentials (admin@example.com /
// changeme123, src/server/config.ts) and seeds them on first boot when the
// users table is empty (auth.service.ts bootstrapAdmin, called from
// index.ts). There is no env override. Left alone, a public deploy would ship
// with a documented default password and no first-visitor-wins guard.
//
// This runs as a one-shot init service on the same /data volume, using
// BugPin's own schema/migration code, and inserts the admin the recipe
// controls. When the main server boots afterwards it sees a user already
// exists and skips bootstrap, so the default is never written.
//
// Idempotent: does nothing once any user exists, so redeploys and restarts
// leave the operator's real account (and its password) untouched.
import { initDatabase, initSchema, runMigrations, closeDatabase } from './src/server/database/database.js';
import { usersRepo } from './src/server/database/repositories/users.repo.js';

const email = (process.env.BUGPIN_ADMIN_EMAIL || '').trim();
const password = process.env.BUGPIN_ADMIN_PASSWORD || '';
if (!email || password.length < 8) {
  console.error('pa-seed: BUGPIN_ADMIN_EMAIL and BUGPIN_ADMIN_PASSWORD (>= 8 chars) are required');
  process.exit(1);
}

await initDatabase();
await initSchema();
await runMigrations();

if ((await usersRepo.count()) === 0) {
  // Same algorithm/cost BugPin uses for its own passwords (auth.service.ts).
  const passwordHash = await Bun.password.hash(password, { algorithm: 'bcrypt', cost: 12 });
  await usersRepo.create({ email, passwordHash, name: 'Admin', role: 'admin' });
  console.log('pa-seed: seeded first admin', email);
} else {
  console.log('pa-seed: users already exist, nothing to do');
}

closeDatabase();
