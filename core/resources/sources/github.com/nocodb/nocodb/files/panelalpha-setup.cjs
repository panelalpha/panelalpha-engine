// Closes NocoDB's open registration, once, on the first deploy.
//
// NC_ADMIN_EMAIL/NC_ADMIN_PASSWORD create the super admin (initAdminFromEnv.ts),
// but they do not close the door behind it. DEFAULT_APP_SETTINGS in
// interface/AppSettings.ts has `invite_only_signup: false`, and
// users.service.ts only refuses a signup when that flag is on: on a fresh
// instance anyone who reaches the account's HTTPS name can POST
// /api/v1/auth/user/signup, land as an org-level VIEWER and be added to the
// default workspace, where they can read every base in it. The setting lives
// in nc_store, not in the environment, so there is no env var for it -- the
// only way to set it is POST /api/v1/app-settings as the super admin.
//
// Runs as a one-shot compose service beside NocoDB rather than inside it: the
// admin exists by the time NocoDB is healthy, so there is nothing to race, and
// the readiness gate waits for this to finish before the deploy is called done.
//
// Best effort, and always exit 0. A NocoDB that is serving must not be held
// back by this step -- and the `ready` gate depends on this completing
// successfully, so a non-zero exit would fail `docker compose up -d` and take
// a working deploy with it.

const fs = require('node:fs');

const BASE = process.env.NC_SETUP_URL || 'http://nocodb:8080';
const EMAIL = process.env.NC_ADMIN_EMAIL;
const PASSWORD = process.env.NC_ADMIN_PASSWORD;

// On the data volume, so it survives a redeploy. Once the flag has been set
// the first time, an operator who deliberately re-opens signup in Team &
// Settings keeps their choice: this never touches the setting twice.
const MARKER = '/usr/app/data/.panelalpha-signup-closed';

const say = (m) => console.log(`[panelalpha-setup] ${m}`);

async function main() {
    if (!EMAIL || !PASSWORD) {
        say('no NC_ADMIN_EMAIL/NC_ADMIN_PASSWORD; nothing to do');
        return;
    }
    if (fs.existsSync(MARKER)) {
        say('signup was already closed on an earlier deploy; left alone');
        return;
    }

    // NocoDB is healthy before this service starts, so this is a short grace
    // for the moment between the healthcheck passing and the first request.
    for (let i = 0; i < 30; i++) {
        try {
            const r = await fetch(`${BASE}/api/v1/health`);
            if (r.ok) break;
        } catch {
            /* not listening yet */
        }
        await new Promise((r) => setTimeout(r, 2000));
    }

    const signin = await fetch(`${BASE}/api/v1/auth/user/signin`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
    });
    if (!signin.ok) {
        say(`sign-in as ${EMAIL} returned ${signin.status}; leaving signup open`);
        return;
    }
    // jwt-strategy.provider.ts reads the token from the `xc-auth` header.
    const { token } = await signin.json();
    if (!token) {
        say('sign-in returned no token; leaving signup open');
        return;
    }

    // updateAppSettings merges into what is stored, so this one key is enough.
    const set = await fetch(`${BASE}/api/v1/app-settings`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'xc-auth': token },
        body: JSON.stringify({ invite_only_signup: true }),
    });
    if (!set.ok) {
        say(`POST /api/v1/app-settings returned ${set.status}; leaving signup open`);
        return;
    }

    fs.writeFileSync(MARKER, new Date().toISOString() + '\n');
    say('signup is now invite-only; new users have to be invited by the admin');
}

main()
    .catch((e) => say(`${e}; leaving signup as it is`))
    .finally(() => process.exit(0));
