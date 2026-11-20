// One-shot, run in the API image (Node 24, global fetch) before the site
// answers. ABC User Feedback starts with no tenant: its web app sends the
// first visitor to /tenant/create, and POST /api/admin/tenants, which needs no
// login, makes the tenant and its super user from whatever email and password
// that visitor types. Whoever opened the address first owned the install.
//
// Here that first super user is the login the engine generated
// (ABC_ADMIN_EMAIL / ABC_ADMIN_PASSWORD from ~/.panelalpha/app-credentials.env),
// made through the same endpoint the setup page uses. Only while no tenant
// exists: once one does, the endpoint refuses everyone and this exits 0
// without touching it, so a password changed in the app stays changed.

const api = process.env.ABC_API_URL || 'http://api:4000';
const email = process.env.ABC_ADMIN_EMAIL || '';
const password = process.env.ABC_ADMIN_PASSWORD || '';
const siteName = (process.env.ABC_SITE_NAME || 'ABC User Feedback').slice(0, 20);

const log = (message) => console.log(`[panelalpha] ${message}`);

if (!email || !password) {
  log('no ABC_ADMIN_EMAIL / ABC_ADMIN_PASSWORD in ~/.panelalpha/app-credentials.env; not leaving the setup page open to the first visitor');
  process.exit(1);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function tenantExists() {
  const response = await fetch(`${api}/api/admin/tenants`);
  if (response.status === 200) return true;
  if (response.status === 404) return false;
  throw new Error(`GET /api/admin/tenants answered ${response.status}`);
}

// The API migrates its database before it listens; give it up to 5 minutes.
let exists = null;
for (let attempt = 1; attempt <= 150; attempt++) {
  try {
    exists = await tenantExists();
    break;
  } catch (error) {
    if (attempt === 150) {
      log(`the API never answered: ${error.message}`);
      process.exit(1);
    }
    await sleep(2000);
  }
}

if (exists) {
  log('the tenant exists; its users are left as they are');
  process.exit(0);
}

const response = await fetch(`${api}/api/admin/tenants`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ siteName, email, password }),
});
if (!response.ok) {
  log(`POST /api/admin/tenants answered ${response.status}: ${await response.text()}`);
  process.exit(1);
}
log(`made the tenant "${siteName}" with its super user ${email}`);
