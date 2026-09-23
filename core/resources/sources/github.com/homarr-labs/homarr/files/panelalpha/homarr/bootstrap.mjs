// Closes Homarr's installer before the application is ever reachable.
//
// Homarr's onboarding is a public tRPC router. `onboardingProcedure` is
// `publicProcedure` with one check -- "is the database's current step the step
// this call belongs to?" (packages/api/src/trpc.ts:169-183) -- and
// `user.initUser` (packages/api/src/router/user.ts:50) creates a user, creates
// the `credentials-admin` group, grants it `admin` and joins the user to it.
// The migrations seed `onboarding` with step `start`, and until the step is
// `finish` the proxy redirects every path to /init
// (apps/nextjs/src/proxy.ts:45-51). So on a stock deploy the first stranger to
// load the domain becomes the administrator of the account owner's dashboard.
// That is engine#200, in its strongest form.
//
// This runs in the one-shot `init` service, which `app` depends on with
// `service_completed_successfully`, so by the time anything can reach port
// 7575 the steps are used up and every one of those endpoints answers
// FORBIDDEN.
//
// Everything here is raw SQL through node:sqlite, which is in Node 24 and in
// the image, so no dependency has to resolve. The one thing that is not raw
// SQL is the password hash: that goes through Homarr's own CLI, so the hashing
// stays Homarr's business and not a reimplementation that drifts.
//
// Columns pinned so schema drift is caught in testing rather than in
// production: user(id,name,provider,password), group(id,name,owner_id,
// home_board_id,position), groupPermission(group_id,permission),
// groupMember(group_id,user_id), onboarding(id,step,previous_step),
// serverSetting(setting_key,value).

import { spawn } from "node:child_process";
import { readFileSync } from "node:fs";
import { setTimeout as sleep } from "node:timers/promises";
import { DatabaseSync } from "node:sqlite";

const DB_PATH = process.env.DB_URL ?? "/appdata/db/db.sqlite";
const ADMIN_USER = (process.env.PA_ADMIN_USER ?? "owner").trim().toLowerCase();
const ADMIN_GROUP = "credentials-admin"; // packages/definitions/src/group.ts:2

const say = (message) => console.log(`[panelalpha] homarr bootstrap: ${message}`);
const die = (message) => {
  console.error(`[panelalpha] homarr bootstrap: ${message}`);
  process.exit(1);
};

const db = new DatabaseSync(DB_PATH);

// The CLI does its work and then never exits: it prints its result and hangs,
// with or without a reachable redis. Measured -- a run killed at 90s had
// already committed the user, the group, the permission and the membership. So
// a command is run, the database is watched for the row it was supposed to
// write, and the process is killed the moment that row is there. An exit code
// is never waited for, because one never comes.
//
// `node ./cli.cjs`, not `/usr/bin/homarr`: that wrapper is a shell script, and
// killing the shell leaves node holding the pipes -- measured, the init
// container hung at "closing the installer" until the deploy timed out.
const runCliUntil = async (label, args, isDone, seconds = 90) => {
  if (isDone()) return;
  const child = spawn("node", ["./cli.cjs", ...args], {
    cwd: "/app/apps/cli",
    env: process.env,
    stdio: ["ignore", "pipe", "pipe"],
  });
  let output = "";
  child.stdout.on("data", (chunk) => (output += chunk));
  child.stderr.on("data", (chunk) => (output += chunk));

  const deadline = Date.now() + seconds * 1000;
  let done = false;
  while (Date.now() < deadline) {
    await sleep(250);
    if (isDone()) {
      done = true;
      break;
    }
    if (child.exitCode !== null) break;
  }
  child.kill("SIGKILL");
  // There is no redis in this container -- the app runs its own, inside its
  // own container, and nothing here needs one. The CLI opens a client anyway
  // and prints a stack trace every time it fails to connect, which is a lot of
  // alarming noise in a deploy log for a connection that does not matter.
  // Dropped, deliberately, and only these lines.
  const noise = /^\[ioredis\]|^at (internalConnectMultiple|afterConnectMultiple)|DeprecationWarning|--trace-deprecation/;
  for (const line of output.split("\n").map((l) => l.trim()).filter(Boolean)) {
    if (!noise.test(line)) say(`  ${label}: ${line}`);
  }
  if (!done && !isDone()) die(`${label} did not do what it was asked (${output.trim() || "no output"})`);
};

const credentialsAdminCount = () =>
  db
    .prepare(
      `SELECT COUNT(*) AS c
         FROM groupPermission gp
         JOIN groupMember gm ON gm.group_id = gp.group_id
         JOIN user u ON u.id = gm.user_id
        WHERE gp.permission = 'admin' AND u.provider = 'credentials'`,
    )
    .get().c;

// ---------------------------------------------------------------------------
// The administrator
// ---------------------------------------------------------------------------
// Only when there is none at all. A customer who renamed this account, changed
// its password or replaced it with one of their own must not find it recreated
// -- or its password reset -- by the next deploy.
if (credentialsAdminCount() > 0) {
  say("a credentials administrator already exists; leaving it alone");
} else {
  const password = readFileSync(process.env.PA_ADMIN_PASSWORD_FILE, "utf8").trim();
  if (password.length < 8) die("PA_ADMIN_PASSWORD_FILE is empty or too short");

  // Creates the user, an admin group named after its own id, the permission
  // and the membership -- and prints a password of its own, which is thrown
  // away and replaced below by the one the customer was given.
  await runCliUntil(
    "recreate-admin",
    ["recreate-admin", "-u", ADMIN_USER],
    () => credentialsAdminCount() > 0,
  );

  const before = db.prepare(`SELECT id, password FROM user WHERE name = ?`).get(ADMIN_USER);
  if (!before) die(`recreate-admin did not create the user '${ADMIN_USER}'`);

  await runCliUntil(
    "users update-password",
    ["users", "update-password", "-u", ADMIN_USER, "-p", password],
    () => {
      const now = db.prepare(`SELECT password FROM user WHERE id = ?`).get(before.id);
      return Boolean(now?.password) && now.password !== before.password;
    },
  );

  // recreate-admin names the group after its own id and says so itself: "the
  // admin group of it has a temporary name. You should change it to something
  // more meaningful." `credentials-admin` is the name Homarr's own onboarding
  // would have given it, which is what makes the result indistinguishable from
  // an instance a human set up -- and what onboard-queries.ts looks for when it
  // decides whether the `user` step still needs doing.
  const taken = db.prepare(`SELECT id FROM "group" WHERE name = ?`).get(ADMIN_GROUP);
  if (!taken) {
    const group = db
      .prepare(
        `SELECT g.id AS id
           FROM "group" g
           JOIN groupPermission gp ON gp.group_id = g.id
           JOIN groupMember gm ON gm.group_id = g.id
          WHERE gp.permission = 'admin' AND gm.user_id = ?
          LIMIT 1`,
      )
      .get(before.id);
    if (group) {
      db.prepare(`UPDATE "group" SET name = ?, owner_id = ? WHERE id = ?`).run(
        ADMIN_GROUP,
        before.id,
        group.id,
      );
    }
  }

  say(`created the administrator '${ADMIN_USER}'`);
}

// ---------------------------------------------------------------------------
// The installer
// ---------------------------------------------------------------------------
// Straight to `finish`. The intermediate steps (`import`, `user`, `group`,
// `settings`, `integrations`) exist to collect things this script has already
// decided or that the customer should decide for themselves in the running
// application, and every one of them is reachable by anyone while the step is
// current. `previous_step` is left null so the /init page's "back to start"
// link is absent rather than dangling.
const onboarding = db.prepare(`SELECT id, step FROM onboarding LIMIT 1`).get();
if (!onboarding) {
  // The migrations insert this row; if they did not, something is wrong enough
  // to stop rather than paper over.
  die("no onboarding row -- migrations did not run");
}
if (onboarding.step === "finish") {
  say("onboarding was already finished");
} else {
  db.prepare(`UPDATE onboarding SET step = 'finish', previous_step = NULL WHERE id = ?`).run(
    onboarding.id,
  );
  say(`onboarding moved from '${onboarding.step}' to 'finish'`);
}

// ---------------------------------------------------------------------------
// What a stranger sees
// ---------------------------------------------------------------------------
// Deliberately left as the migrations leave it: serverSetting `board` carries
// homeBoardId null, and getHomeIdBoardAsync reads the server setting -- not
// the `everyone` group -- for a request with no session
// (packages/api/src/router/board.ts:1579-1583). Null is NOT_FOUND is
// `redirect("/auth/login")` for an anonymous visitor. A Homarr board can hold
// internal URLs, and the widgets on it can be bound to integrations holding
// API keys, so the default has to be that a stranger sees a login form.
// Publishing a board is one switch in Manage -> Settings; un-publishing one
// after a search engine has indexed it is not.
const board = JSON.parse(
  db.prepare(`SELECT value FROM serverSetting WHERE setting_key = 'board'`).get()?.value ?? "{}",
);
if ((board.json?.homeBoardId ?? null) === null) {
  say("no public home board: an anonymous visitor gets the login page");
} else {
  say("a home board is configured for anonymous visitors; leaving it alone");
}

const finalStep = db.prepare(`SELECT step FROM onboarding LIMIT 1`).get()?.step;
if (finalStep !== "finish") die(`onboarding is '${finalStep}', not 'finish'`);
say(`ready: ${credentialsAdminCount()} credentials administrator(s), onboarding finished`);
