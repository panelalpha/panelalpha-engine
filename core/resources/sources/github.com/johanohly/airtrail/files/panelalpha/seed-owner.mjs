// Idempotent owner seed. Runs from the wrapped entrypoint after the migrations
// have created the schema and before `node build` binds, so AirTrail's setup
// window (POST /api/users/setup, open until an owner exists) is already closed
// on the first public request. Uses the image's own pg and @node-rs/argon2 from
// /app/node_modules, and AirTrail's exact argon2id parameters
// (src/lib/server/utils/hash.ts) with NFKC normalisation, so the seeded hash
// verifies against the running app. A no-op once an owner exists, so a redeploy
// never creates a second account.
//
// AirTrail changed how it marks the owner between releases: the current release
// (johly/airtrail :latest, v3.x) has a NOT NULL `role` column on "user" with the
// owner carrying role='owner', while main has moved to an RBAC model with an
// `is_owner` boolean and a nullable `role_id`. migrate.js applies whichever set
// of migrations is baked into the image, so this seed introspects the columns
// that ended up on "user" and matches them, rather than assuming one shape --
// that is what keeps an unpinned :latest safe across that transition.
import { randomBytes } from 'node:crypto';
import pg from 'pg';
import { hash } from '@node-rs/argon2';

const ARGON2 = { memoryCost: 19456, timeCost: 2, outputLen: 32, parallelism: 1 };

const dbUrl = process.env.DB_URL;
const username = process.env.AIRTRAIL_OWNER_USERNAME;
const password = process.env.AIRTRAIL_OWNER_PASSWORD;
const displayName = process.env.AIRTRAIL_OWNER_DISPLAY_NAME || username;

if (!dbUrl || !username || !password) {
  console.error('[panelalpha] seed-owner: DB_URL / owner env not set');
  process.exit(1);
}

// Lucia-style id: 15 lowercase-alphanumeric characters.
function genId(n = 15) {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const bytes = randomBytes(n);
  let out = '';
  for (let i = 0; i < n; i++) out += alphabet[bytes[i] % alphabet.length];
  return out;
}

const client = new pg.Client({ connectionString: dbUrl });
await client.connect();
try {
  const { rows: cols } = await client.query(
    `SELECT column_name FROM information_schema.columns WHERE table_name = 'user'`,
  );
  const names = new Set(cols.map((c) => c.column_name));
  const useRbac = names.has('is_owner'); // main / RBAC model
  const useRole = names.has('role'); // current released model

  if (!useRbac && !useRole) {
    console.error(
      '[panelalpha] seed-owner: neither is_owner nor role on "user"; unknown schema, leaving setup open',
    );
    process.exit(1);
  }

  const ownerWhere = useRbac ? 'is_owner = true' : "role = 'owner'";
  const { rows } = await client.query(
    `SELECT 1 FROM "user" WHERE ${ownerWhere} LIMIT 1`,
  );
  if (rows.length > 0) {
    console.log('[panelalpha] seed-owner: owner already exists, skipping');
  } else {
    const id = genId(15);
    const hashed = await hash(password.normalize('NFKC'), ARGON2);
    if (useRbac) {
      await client.query(
        'INSERT INTO "user" (id, username, display_name, password, is_owner) VALUES ($1, $2, $3, $4, true)',
        [id, username, displayName, hashed],
      );
    } else {
      await client.query(
        'INSERT INTO "user" (id, username, display_name, password, role) VALUES ($1, $2, $3, $4, $5)',
        [id, username, displayName, hashed, 'owner'],
      );
    }
    console.log(`[panelalpha] seed-owner: created owner "${username}"`);
  }
} finally {
  await client.end();
}
