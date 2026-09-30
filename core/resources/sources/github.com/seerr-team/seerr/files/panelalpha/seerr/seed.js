// One-shot, before the app starts: migrate with Seerr's own compiled code,
// create a local owner if the database has no user yet, and close first run.
process.chdir('/app');
const crypto = require('crypto');
const dataSource = require('/app/dist/datasource').default;
const { isPgsql } = require('/app/dist/utils/dbType');
const { User } = require('/app/dist/entity/User');
const { UserType } = require('/app/dist/constants/user');
const { MediaServerType } = require('/app/dist/constants/server');
const { Permission } = require('/app/dist/lib/permissions');
const { getSettings } = require('/app/dist/lib/settings');
const JellyfinAPI = require('/app/dist/api/jellyfin').default;

const say = (m) => console.log(`[panelalpha/seed] ${m}`);
const need = (k, why = '') => {
  if (!process.env[k]) throw new Error(`${k} is not set${why}`);
  return process.env[k].trim();
};
const SERVERS = {
  plex: MediaServerType.PLEX,
  jellyfin: MediaServerType.JELLYFIN,
  emby: MediaServerType.EMBY,
};

(async () => {
  // Seerr's settings migrator calls a bare process.exit() on failure; make that non-zero.
  process.exitCode = 1;
  const password = need('SEERR_ADMIN_PASSWORD');
  const serverName = (process.env.SEERR_MEDIA_SERVER || 'plex').trim().toLowerCase();
  const serverType = SERVERS[serverName];
  if (!serverType) throw new Error(`SEERR_MEDIA_SERVER must be plex, jellyfin or emby, not '${serverName}'`);
  // SEERR_OWNER_EMAIL (project env): a Plex sign-in with this email links to the owner.
  const wanted = (process.env.SEERR_OWNER_EMAIL || '').trim().toLowerCase();
  const email = wanted || `admin-${need('SEERR_ADMIN_TAG')}@${need('PA_PUBLIC_HOST')}`.toLowerCase();

  const db = await dataSource.initialize();
  if (isPgsql) {
    await db.runMigrations();
  } else {
    await db.query('PRAGMA foreign_keys=OFF');
    await db.runMigrations();
    await db.query('PRAGMA foreign_keys=ON');
  }

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
    // No UI changes an email; follow the env until a media account is linked.
    const owner = await users.findOne({ where: { id: 1 } });
    if (owner && !owner.plexId && !owner.jellyfinUserId && wanted && owner.email !== wanted
        && !(await users.findOne({ where: { email: wanted } }))) {
      owner.email = wanted;
      await users.save(owner);
      say(`owner email set to ${wanted}`);
    } else {
      say('users exist; owner not touched');
    }
  }

  // Raw load first: the settings migrations need a Jellyfin API key in
  // Jellyfin/Emby mode, which is exactly what may be about to be written.
  const settings = await getSettings().load(undefined, true);
  // While NOT_CONFIGURED, /auth/jellyfin against any server the caller runs
  // rewrites user 1 as its admin. Pin the type until a media server is linked.
  const linked = await users.createQueryBuilder('u')
    .where('u.plexId IS NOT NULL OR u.jellyfinUserId IS NOT NULL').getCount();
  const connected = settings.plex.ip !== '' || settings.jellyfin.ip !== '';
  if (!linked && !connected) {
    if (serverType !== MediaServerType.PLEX) {
      // Seerr needs the server up front; a saved host also stops the sign-in
      // form from accepting a host chosen by the caller.
      const why = ` (required with SEERR_MEDIA_SERVER=${serverName})`;
      const url = new URL(need('SEERR_JELLYFIN_URL', why));
      const jf = {
        ip: url.hostname,
        port: Number(url.port) || (url.protocol === 'https:' ? 443 : 80),
        useSsl: url.protocol === 'https:',
        urlBase: url.pathname.replace(/\/+$/, ''),
        apiKey: need('SEERR_JELLYFIN_API_KEY', why),
      };
      settings.main.mediaServerType = serverType;
      const info = await new JellyfinAPI(
        `${jf.useSsl ? 'https' : 'http'}://${jf.ip}:${jf.port}${jf.urlBase}`, jf.apiKey,
      ).getSystemInfo().catch((e) => {
        throw new Error(`${serverName} server at ${url.origin} did not accept SEERR_JELLYFIN_API_KEY: ${e.errorCode || e.message}`);
      });
      Object.assign(settings.jellyfin, jf, { serverId: info.Id, name: info.ServerName });
      say(`${serverName} server saved: ${info.ServerName} at ${url.origin}`);
    } else {
      settings.main.mediaServerType = serverType;
    }
    await settings.save();
  } else if (settings.main.mediaServerType !== serverType) {
    say(`media server already connected; SEERR_MEDIA_SERVER=${serverName} ignored`);
  }

  await settings.load();
  settings.public.initialized = true;
  settings.main.applicationUrl = settings.main.applicationUrl || need('PA_PUBLIC_URL');
  settings.network.trustProxy = true;
  await settings.save();
  say(`settings: initialized, mediaServerType=${settings.main.mediaServerType}, trustProxy`);

  await db.destroy();
  process.exit(0); // API clients keep cache timers alive
})().catch((e) => {
  console.error(`[panelalpha/seed] failed: ${e.stack || e}`);
  process.exit(1);
});
