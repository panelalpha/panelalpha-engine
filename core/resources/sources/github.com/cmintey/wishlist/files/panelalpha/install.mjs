// Creates this installation's account and closes public signup, once, on the
// first boot -- before the HTTP server binds.
//
// Wishlist has no installer and no CLI. The first person to reach
// /setup-wizard gets `roleId` 2 (ADMIN): routes/signup/+page.server.ts does
// `userCount > 0 ? Role.USER : Role.ADMIN`, and routes/setup-wizard/
// +page.server.ts only redirects away once a user exists. On a public HTTPS
// name that is first-visitor-wins. And creating the account does not close the
// door behind it: getDefaultConfig() in lib/server/config.ts has
// `enableSignup: true`, the setting lives in the system_config table rather
// than in the environment, and /signup is in hooks.server.ts's
// `nonPrivateRoutes`.
//
// So both are done here, from the credentials hooks/prepare.sh generated,
// while nothing is listening yet. Run from the entrypoint rather than from a
// sibling compose service on purpose: a one-shot beside the app can only start
// once the app is healthy, which means once the app is answering, and the
// window this exists to close is exactly that moment.
//
// Only node builtins. The image's node_modules are laid out by pnpm and this
// file is not part of the package, so a bare import would not resolve;
// node:sqlite and node:crypto are enough for the three rows involved.
//
// Always exits 0. A Wishlist that would otherwise serve must not be held back
// by this -- but a failure here leaves signup open, so every path says so
// loudly.

import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { randomBytes, scryptSync } from "node:crypto";
import { DatabaseSync } from "node:sqlite";

const DB = process.env.PA_WISHLIST_DB || "/usr/src/app/data/prod.db";
const CREDENTIALS = process.env.PA_WISHLIST_CREDENTIALS || "/usr/src/app/panelalpha-credentials";
// On the data bind mount, so it survives a redeploy: an operator who later
// re-opens signup in the admin panel keeps that choice.
const MARKER = process.env.PA_WISHLIST_MARKER || "/usr/src/app/data/.panelalpha-installed";

const say = (m) => console.log(`[panelalpha] wishlist: ${m}`);

/**
 * lib/server/password.ts: scrypt N=16384 r=16 p=1 dkLen=64 over the NFKC form
 * of the password, with a 16-byte hex salt used as *text*, stored as
 * `s2:<salt>:<key>`. 128 * N * r is 32 MiB, over node's default maxmem.
 */
function hashPassword(password) {
    const salt = randomBytes(16).toString("hex");
    const key = scryptSync(password.normalize("NFKC"), salt, 64, {
        N: 16384,
        r: 16,
        p: 1,
        maxmem: 96 * 1024 * 1024
    });

    return `s2:${salt}:${key.toString("hex")}`;
}

function credentials() {
    const out = {};
    for (const line of readFileSync(CREDENTIALS, "utf8").split("\n")) {
        const m = /^([A-Z_]+)=(.*)$/.exec(line.trim());
        if (m) {
            out[m[1]] = m[2];
        }
    }

    return out;
}

function main() {
    if (existsSync(MARKER)) {
        say("already installed on an earlier deploy; leaving the account and the signup setting alone");

        return;
    }
    const db = new DatabaseSync(DB);

    // Checked before the credentials are read, and not only because it is
    // cheaper: an installation people are already using is one this must not
    // touch, whatever state the credentials file is in. A redeploy runs this
    // again, and a second admin -- or a reset password -- is not something a
    // deploy gets to decide.
    const { n } = db.prepare("SELECT COUNT(*) AS n FROM user").get();
    if (n > 0) {
        say("the installation already has an account; nothing to do");
        writeFileSync(MARKER, new Date().toISOString() + "\n");
        db.close();

        return;
    }

    if (!existsSync(CREDENTIALS)) {
        say(`no credentials at ${CREDENTIALS}; SIGNUP IS OPEN and the first visitor becomes the admin`);
        db.close();

        return;
    }

    const { WISHLIST_USERNAME: username, WISHLIST_EMAIL: email, WISHLIST_PASSWORD: password } = credentials();
    if (!username || !email || !password) {
        say("the credentials file is incomplete; SIGNUP IS OPEN and the first visitor becomes the admin");
        db.close();

        return;
    }

    // prisma/seed.ts has just run and creates "Default" when there is no
    // group. lib/server/user.ts puts the first user in the first group it
    // finds, and getActiveMembership() needs the membership to be active --
    // without it the account cannot create a list at all.
    const group = db.prepare('SELECT id FROM "group" ORDER BY rowid LIMIT 1').get();
    if (!group) {
        say("no group exists yet; did the seed run? SIGNUP IS OPEN");
        db.close();

        return;
    }

    // The ids are cuid2/uuid upstream; nothing reads their shape, only their
    // uniqueness.
    const userId = "pa" + randomBytes(12).toString("hex");

    db.exec("BEGIN");
    try {
        db.prepare(
            'INSERT INTO user (id, username, name, email, "roleId", "hashedPassword") VALUES (?, ?, ?, ?, 2, ?)'
        ).run(userId, username, "Admin", email, hashPassword(password));
        // roleId left at the column default (1/USER), which is the row
        // createUser() writes; the global role on the user row is what makes
        // this an admin.
        db.prepare(
            'INSERT INTO user_group_membership (id, active, "userId", "groupId") VALUES (?, 1, ?, ?)'
        ).run(randomBytes(16).toString("hex"), userId, group.id);
        // system_config is keyed on (key, groupId); "global" is the scope
        // getConfig() reads for enableSignup.
        db.prepare(
            'INSERT INTO system_config ("key", "value", "groupId") VALUES (?, ?, ?)'
            + ' ON CONFLICT ("key", "groupId") DO UPDATE SET "value" = excluded."value"'
        ).run("enableSignup", "false", "global");
        db.exec("COMMIT");
    } catch (e) {
        db.exec("ROLLBACK");
        say(`could not create the account (${e}); SIGNUP IS OPEN and the first visitor becomes the admin`);
        db.close();

        return;
    }

    db.close();
    writeFileSync(MARKER, new Date().toISOString() + "\n");
    say(`created the admin account ${email}; its password is in ~/.panelalpha/wishlist/credentials`);
    say("public signup is off; invite people from Admin > Settings, or turn it back on there");
}

try {
    main();
} catch (e) {
    say(`${e}; SIGNUP MAY BE OPEN -- check Admin > Settings`);
}
process.exit(0);
