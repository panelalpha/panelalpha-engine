// One-shot, before the app starts: migrate with Overseerr's own compiled code,
// create a local owner if the database has no user yet, and mark setup done.
process.chdir('/app');
const crypto = require('crypto');
const dataSource = require('/app/dist/datasource').default;
const { User } = require('/app/dist/entity/User');
const { UserType } = require('/app/dist/constants/user');
const { Permission } = require('/app/dist/lib/permissions');
const { getSettings } = require('/app/dist/lib/settings');

const say = (m) => console.log(`[panelalpha/seed] ${m}`);
const need = (k) => {
  if (!process.env[k]) throw new Error(`${k} is not set`);
  return process.env[k];
};

(async () => {
  const password = need('OVERSEERR_ADMIN_PASSWORD');
  // OVERSEERR_OWNER_EMAIL: the engine's admin-<random>@<domain>, or the project
  // env's value, the customer's Plex email: "Sign in with Plex" with that
  // account then links Plex to the owner.
  const wanted = need('OVERSEERR_OWNER_EMAIL').trim().toLowerCase();
  const email = wanted;

  const db = await dataSource.initialize();
  await db.query('PRAGMA foreign_keys=OFF');
  await db.runMigrations();
  await db.query('PRAGMA foreign_keys=ON');

  const users = db.getRepository(User);
  if ((await users.count()) === 0) {
    const hash = crypto.createHash('md5').update(email).digest('hex');
    const owner = new User({
      email,
      username: 'admin',
      permissions: Permission.ADMIN,
      userType: UserType.LOCAL,
      plexToken: '',
      avatar: `https://www.gravatar.com/avatar/${hash}?default=mm&size=200`,
    });
    await owner.setPassword(password);
    await users.save(owner);
    say(`owner created: ${email} (id ${owner.id})`);
  } else {
    // Overseerr has no UI to change an email; follow the env until Plex is linked.
    const owner = await users.findOne({ where: { id: 1 } });
    if (owner && !owner.plexId && wanted && owner.email !== wanted
        && !(await users.findOne({ where: { email: wanted } }))) {
      owner.email = wanted;
      await users.save(owner);
      say(`owner email set to ${wanted}`);
    } else {
      say('users exist; owner not touched');
    }
  }

  // The wizard's only exit is a Plex sign-in that makes the first Plex account
  // the admin; with the owner seeded, setup is marked done instead.
  const settings = getSettings().load();
  settings.public.initialized = true;
  settings.main.applicationUrl = settings.main.applicationUrl || need('PA_PUBLIC_URL');
  settings.main.trustProxy = true;
  settings.save();
  say('settings: initialized, applicationUrl, trustProxy');

  await db.destroy();
})().catch((e) => {
  console.error(`[panelalpha/seed] failed: ${e.stack || e}`);
  process.exit(1);
});
