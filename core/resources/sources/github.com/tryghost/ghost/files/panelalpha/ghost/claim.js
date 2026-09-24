// Completes Ghost's owner setup with the credentials the recipe generated, so
// /ghost/ never offers it to whoever opens the site first.
//
//   node claim.js check   exit 0 if the owner is already set up (read-only)
//   node claim.js setup   wait for a Ghost booted on loopback, then set it up
//
// `setup` goes through Ghost's own setup endpoint rather than writing the
// users table, so settings, fixtures and hashing stay Ghost's business.
'use strict';

const http = require('http');

const site = new URL(process.env.url || 'http://localhost');
const env = process.env;

function request(method, path, body) {
    const data = body === undefined ? null : JSON.stringify(body);
    const headers = {
        // Ghost redirects a request that does not match its configured url to
        // the canonical origin; these make a loopback request look like one
        // that came through the proxy.
        Host: site.host,
        'X-Forwarded-Proto': site.protocol.replace(':', ''),
        Origin: site.origin,
        Accept: 'application/json',
    };
    if (data !== null) {
        headers['Content-Type'] = 'application/json';
        headers['Content-Length'] = Buffer.byteLength(data);
    }
    return new Promise((resolve, reject) => {
        const req = http.request({host: '127.0.0.1', port: 2368, method, path, headers}, (res) => {
            let text = '';
            res.on('data', (chunk) => { text += chunk; });
            res.on('end', () => resolve({status: res.statusCode, text}));
        });
        req.on('error', reject);
        req.setTimeout(30000, () => req.destroy(new Error('timed out')));
        if (data !== null) {
            req.write(data);
        }
        req.end();
    });
}

const SETUP = '/ghost/api/admin/authentication/setup/';

async function setupStatus() {
    const res = await request('GET', SETUP);
    if (res.status !== 200) {
        throw new Error(`setup status answered ${res.status}`);
    }
    return JSON.parse(res.text).setup[0].status === true;
}

// Mirrors models.User.isSetup(): the owner row exists and is not 'inactive'.
// Any error (no tables yet, schema drift) means "not known to be set up", and
// the caller takes the full path through Ghost itself.
async function check() {
    const mysql = require('/var/lib/ghost/current/node_modules/mysql2/promise');
    const db = await mysql.createConnection({
        host: env.database__connection__host,
        port: Number(env.database__connection__port || 3306),
        user: env.database__connection__user,
        password: env.database__connection__password,
        database: env.database__connection__database,
    });
    try {
        const [rows] = await db.query(
            "SELECT u.status FROM users u JOIN roles_users ru ON ru.user_id = u.id "
            + "JOIN roles r ON r.id = ru.role_id WHERE r.name = 'Owner' LIMIT 1"
        );
        return rows.length === 1 && rows[0].status !== 'inactive';
    } finally {
        await db.end();
    }
}

async function setup() {
    // A first boot runs every migration against an empty database; a minute
    // is normal, so allow five.
    const deadline = Date.now() + 300000;
    let done;
    for (;;) {
        try {
            done = await setupStatus();
            break;
        } catch (e) {
            if (Date.now() > deadline) {
                throw new Error(`Ghost did not answer on loopback: ${e.message}`);
            }
            await new Promise((r) => setTimeout(r, 2000));
        }
    }
    if (done) {
        console.log('[panelalpha] ghost: owner already set up');
        return;
    }
    const res = await request('POST', SETUP, {setup: [{
        name: env.PA_OWNER_NAME || 'Owner',
        email: env.PA_OWNER_EMAIL,
        password: env.PA_OWNER_PASSWORD,
        blogTitle: env.PA_BLOG_TITLE || 'Ghost',
    }]});
    if (res.status >= 300 || !(await setupStatus())) {
        throw new Error(`owner setup failed (${res.status}): ${res.text.slice(0, 500)}`);
    }
    console.log(`[panelalpha] ghost: owner ${env.PA_OWNER_EMAIL} set up`);
}

const mode = process.argv[2];
if (mode === 'check') {
    check().then((ok) => process.exit(ok ? 0 : 1), () => process.exit(1));
} else if (mode === 'setup') {
    if (!env.PA_OWNER_EMAIL || !env.PA_OWNER_PASSWORD) {
        console.error('[panelalpha] ghost: PA_OWNER_EMAIL / PA_OWNER_PASSWORD missing');
        process.exit(1);
    }
    setup().then(() => process.exit(0), (e) => {
        console.error(`[panelalpha] ghost: ${e.message}`);
        process.exit(1);
    });
} else {
    console.error('usage: claim.js check|setup');
    process.exit(2);
}
