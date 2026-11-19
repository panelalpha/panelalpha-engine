// Sets the engine's login on Fredy's built-in admin while it still has the default
// password `admin`. Run from /fredy before Fredy starts, with Fredy's own modules: its
// config, migrations, admin bootstrap and password hashing, so a new database gets the
// admin index.js would create and this only changes its name and password.
import { refreshConfig } from './lib/utils.js';
import SqliteConnection from './lib/services/storage/SqliteConnection.js';
import { runMigrations } from './lib/services/storage/migrations/migrate.js';
import * as users from './lib/services/storage/userStorage.js';
import * as hasher from './lib/services/security/hash.js';

const say = (message) => console.log(`[panelalpha] fredy: ${message}`);

await refreshConfig();
await SqliteConnection.init();
await runMigrations();
// A new database: the admin/admin Fredy creates on its first start.
await users.ensureAdminUserExists();

const name = process.env.FREDY_ADMIN_USERNAME || users.ADMIN_USERNAME;
const admin = users.getUserWithSecretsByUsername(users.ADMIN_USERNAME);
if (!admin || !(await hasher.verify(users.DEFAULT_ADMIN_PASSWORD, admin.password))) {
  // No default login left: somebody chose theirs.
  process.exit(0);
}
if (name !== admin.username && users.getUserWithSecretsByUsername(name)) {
  say(`a user ${name} exists already; the admin stays admin/admin`);
  process.exit(1);
}

await users.upsertUser({ userId: admin.id, username: name, password: process.env.FREDY_ADMIN_PASSWORD, isAdmin: true });
SqliteConnection.close();
say('the admin\'s login is the one the engine generated');
process.exit(0);
