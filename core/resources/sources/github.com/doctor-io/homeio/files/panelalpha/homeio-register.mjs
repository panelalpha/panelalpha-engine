// Registers Homeio's one account with the login the engine generated.
//
// Homeio's first visitor registers the account at /register, but its middleware
// offers that page only when a fetch of /api/auth/status at the site's public
// address, made from inside the container, says there is no user; on
// PanelAlpha that fetch does not come back and Homeio assumes an account
// exists. Here, once the server answers on its own port, the same
// /api/auth/status is asked locally and, only while hasUsers is false, the
// login is registered through Homeio's own POST /api/auth/register (which
// creates the data folders and the app catalog, as the page's registration
// does, and refuses every later registration). An account that exists is never
// touched, so a password changed in Homeio stays changed.

const say = (message) => console.error(`[panelalpha] homeio: ${message}`);

const username = process.env.HOMEIO_ADMIN_USER ?? '';
const password = process.env.HOMEIO_ADMIN_PASSWORD ?? '';
if (username === '' || password === '') {
  say('HOMEIO_ADMIN_USER or HOMEIO_ADMIN_PASSWORD is empty; no account registered');
  process.exit(0);
}

const port = process.env.HOMEIO_HTTP_PORT || process.env.PORT || '3000';
const base = `http://127.0.0.1:${port}`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// docker-entrypoint.sh migrates the database before the server listens; give
// both up to ten minutes.
let hasUsers = null;
for (let i = 0; i < 300 && hasUsers === null; i++) {
  try {
    const response = await fetch(`${base}/api/auth/status`, { cache: 'no-store' });
    if (response.ok) {
      const json = await response.json();
      if (typeof json?.data?.hasUsers === 'boolean') {
        hasUsers = json.data.hasUsers;
        break;
      }
    }
  } catch {
    // not listening yet
  }
  await sleep(2000);
}

if (hasUsers === null) {
  say('the server did not answer /api/auth/status in ten minutes; no account registered');
  process.exit(0);
}
if (hasUsers) {
  say('Homeio has an account already; left alone');
  process.exit(0);
}

const response = await fetch(`${base}/api/auth/register`, {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ username, password, confirmPassword: password }),
});
if (response.ok) {
  say(`registered the account ${username}`);
} else {
  say(`POST /api/auth/register answered ${response.status}: ${(await response.text()).slice(0, 200)}`);
}
