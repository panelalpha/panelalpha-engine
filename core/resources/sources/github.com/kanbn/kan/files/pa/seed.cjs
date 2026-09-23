// Seed the workspace owner directly into Postgres, the way Better Auth's email
// sign-up would, so the owner can log in with email+password while public
// sign-up stays closed. Kan ships no admin CLI and has no "first user is admin"
// concept, so there is nothing to call; we write the rows ourselves.
//
// Runs as a one-shot after `drizzle-kit migrate`, before the web app starts.
// Idempotent: it does nothing when the owner already exists, so a redeploy
// never resets the password. Uses only Node's built-in crypto plus the `pg`
// driver already present in this (migrate) image at /db/node_modules -- nothing
// is fetched at deploy time.
//
// Better Auth 1.4 password format (verified against the library): a random
// 16-byte salt as lower-hex, then scrypt(password.normalize("NFKC"), <that hex
// string as UTF-8 bytes>, {N:16384, r:16, p:1, dkLen:64}) as lower-hex, joined
// "salt:hash". A credential account carries providerId="credential" and
// accountId equal to the user id; sign-in looks the user up by email, finds the
// credential account and verifies the password against account.password.
const crypto = require("crypto");
const { Client } = require("pg");

const EMAIL = (process.env.KAN_OWNER_EMAIL || "").trim().toLowerCase();
const NAME = process.env.KAN_OWNER_NAME || "Owner";
const PASSWORD = process.env.KAN_OWNER_PASSWORD || "";
const DSN = process.env.POSTGRES_URL || "";

function fail(msg) {
  console.error(`[kan/seed] ${msg}`);
  process.exit(1);
}

if (!EMAIL || !PASSWORD) fail("KAN_OWNER_EMAIL and KAN_OWNER_PASSWORD are required");
if (!DSN) fail("POSTGRES_URL is required");

function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString("hex"); // 32 hex chars
  const key = crypto.scryptSync(
    password.normalize("NFKC"),
    Buffer.from(salt, "utf8"), // Better Auth salts with the hex STRING's bytes
    64,
    { N: 16384, r: 16, p: 1, maxmem: 128 * 16384 * 16 * 2 },
  );
  return `${salt}:${key.toString("hex")}`;
}

async function main() {
  const db = new Client({ connectionString: DSN });
  await db.connect();
  try {
    const existing = await db.query('SELECT id FROM "user" WHERE lower(email) = $1', [EMAIL]);
    if (existing.rowCount > 0) {
      console.log(`[kan/seed] owner ${EMAIL} already exists, leaving it untouched`);
      return;
    }

    const userId = crypto.randomUUID();
    const password = hashPassword(PASSWORD);

    await db.query("BEGIN");
    await db.query(
      'INSERT INTO "user" (id, name, email, "emailVerified", "createdAt", "updatedAt") ' +
        "VALUES ($1, $2, $3, true, now(), now())",
      [userId, NAME, EMAIL],
    );
    await db.query(
      'INSERT INTO "account" ("accountId", "providerId", "userId", password, "createdAt", "updatedAt") ' +
        "VALUES ($1, 'credential', $2, $3, now(), now())",
      [userId, userId, password],
    );
    await db.query("COMMIT");
    console.log(`[kan/seed] created owner ${EMAIL}`);
  } catch (err) {
    try { await db.query("ROLLBACK"); } catch (_) {}
    fail(`seed failed: ${err && err.message ? err.message : err}`);
  } finally {
    await db.end();
  }
}

main().catch((err) => fail(`seed failed: ${err && err.message ? err.message : err}`));
