<?php
/**
 * DAViCal's installer, for a host that has no psql and no Perl DBD::Pg.
 *
 * Runs inside the app container from /app on the install and upgrade stages,
 * before Apache binds, because the staged entrypoint execs the serve command
 * last.
 *
 * Upstream installs the database with dba/create-database.sh, and that script
 * cannot run here. It shells out to `createdb`, `createlang` and `psql` for
 * every statement, creates two PostgreSQL roles of its own with `CREATE USER`,
 * and then hands the rest of the job to dba/update-davical-database, a Perl
 * program needing DBI, DBD::Pg and YAML. The shared PHP base image
 * (php:8.3-apache-bookworm plus install-php-extensions) has none of those: no
 * postgresql-client at all, and a Perl with neither DBD::Pg nor YAML. It also
 * expects to be run by a database superuser, which an account's sidecar user
 * is not asked to be.
 *
 * What create-database.sh actually *does*, once the shell is taken out, is a
 * fixed list of SQL files in a fixed order plus one UPDATE. That list is what
 * this file replays through PDO, which the image does have (pdo_pgsql is baked
 * into panelalpha/php). Nothing here reimplements the schema: every statement
 * executed comes out of the repository's own .sql files, and the order is
 * create-database.sh's own.
 *
 * Idempotent, because it runs on the upgrade stage too. A database already at
 * the wanted revision costs one SELECT plus the CREATE OR REPLACE of the
 * views and the stored functions -- which is deliberate, and is exactly what
 * update-davical-database does on every invocation: a redeploy that brings
 * newer DAViCal code must bring its newer function bodies with it, or the
 * application and its stored procedures disagree about the schema.
 */

const DAVICAL_ROOT = '/app';
const AWL_ROOT = '/app/awl';

/** What htdocs/always.php asks the database to be: $c->want_dbversion. */
const WANT_DBVERSION = [1, 3, 6];

function say(string $message): void
{
    fwrite(STDERR, '[davical] ' . $message . "\n");
}

function fail(string $message): never
{
    fwrite(STDERR, '[davical] ' . $message . "\n");
    exit(1);
}

function env(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default === null) {
            fail($name . ' is not set; refusing to guess.');
        }

        return $default;
    }

    return $value;
}

/**
 * The sidecar, once it really answers.
 *
 * `depends_on: {condition: service_healthy}` in the compose override already
 * gates this container on pg_isready, so this normally returns first try. It
 * stays because pg_isready answers as soon as the postmaster accepts
 * connections, which on a first boot is a moment before the entrypoint's
 * initdb has finished creating the role and the database it was asked for --
 * and the failure that produces is `password authentication failed`, thirty
 * seconds into a deploy, with no retry anywhere else in the chain.
 */
function connect(): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        env('DAVICAL_DB_HOST'),
        env('DAVICAL_DB_PORT', '5432'),
        env('DAVICAL_DB_NAME')
    );

    $user = env('DAVICAL_DB_USER');
    $pass = env('DAVICAL_DB_PASS');

    for ($attempt = 1; $attempt <= 90; $attempt++) {
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (Throwable $e) {
            if ($attempt === 90) {
                fail('database never became reachable: ' . $e->getMessage());
            }
            sleep(2);
        }
    }

    fail('unreachable');
}

/**
 * One repository .sql file, as one round trip.
 *
 * PDO::exec on pdo_pgsql hands the whole string to PQexec, which is the
 * server's own parser -- so the dollar-quoted plpgsql bodies in
 * caldav_functions.sql and rrule_functions.sql arrive intact, where splitting
 * the file on ';' in PHP would cut every one of them in half. It also means
 * the file is one implicit transaction: a failure half way leaves nothing
 * behind.
 *
 * None of these files uses a psql meta-command (checked: no line in dba/ or
 * dba/views/ or dba/patches/ begins with a backslash, and there is no
 * `COPY ... FROM stdin`), which is the one thing that would make psql
 * genuinely necessary.
 */
function runSqlFile(PDO $pdo, string $path): void
{
    if (!is_file($path)) {
        fail('missing SQL file: ' . $path);
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        fail('unreadable SQL file: ' . $path);
    }

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fail($path . ': ' . $e->getMessage());
    }
}

/**
 * The revision the database believes it is at, or null when there is no
 * database yet.
 *
 * awl_db_revision is created by AWL's schema-management.sql, so its absence is
 * the only honest test for "nothing has been installed here". A to_regclass()
 * probe rather than a catch: a failed statement on PostgreSQL aborts the
 * transaction it is in, and this runs before anything else.
 */
function currentRevision(PDO $pdo): ?array
{
    $exists = $pdo->query("SELECT to_regclass('public.awl_db_revision') IS NOT NULL")->fetchColumn();
    if (!$exists) {
        return null;
    }

    $row = $pdo->query(
        'SELECT schema_major, schema_minor, schema_patch FROM awl_db_revision ORDER BY schema_id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    return [(int) $row['schema_major'], (int) $row['schema_minor'], (int) $row['schema_patch']];
}

/** @return int -1, 0 or 1, comparing [major, minor, patch] triples. */
function compareRevisions(array $a, array $b): int
{
    return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
}

/**
 * The parts of the schema that are replaced rather than migrated.
 *
 * update-davical-database runs exactly this list, in exactly this order, every
 * time it is invoked -- before the patches on its --nopatch pass and after
 * them on its normal one. The order is upstream's and is kept even where it
 * looks odd (the views before the functions): these files are CREATE OR
 * REPLACE throughout, so the second pass is what settles any dependency the
 * first pass could not.
 *
 * The permissions step that follows it upstream is deliberately absent. It
 * exists to grant a minimum-privilege `davical_app` role access to tables owned
 * by `davical_dba`, a split that needs two roles and a superuser to create
 * them. This deployment has one role, which owns the database and everything
 * in it, so there is no privilege left to grant -- and nothing outside the
 * account's own compose network can reach 5432 to use it.
 */
function refreshFunctionsAndViews(PDO $pdo): void
{
    runSqlFile($pdo, DAVICAL_ROOT . '/dba/supported_locales.sql');

    $views = glob(DAVICAL_ROOT . '/dba/views/*.sql') ?: [];
    sort($views);
    foreach ($views as $view) {
        runSqlFile($pdo, $view);
    }

    runSqlFile($pdo, DAVICAL_ROOT . '/dba/caldav_functions.sql');

    // rrule_functions-8.1.sql is the variant for PostgreSQL before 8.3.
    // The sidecar is 17; upstream picks by version and so does this.
    runSqlFile($pdo, DAVICAL_ROOT . '/dba/rrule_functions.sql');
}

/**
 * Every dba/patches/<n>.sql newer than the database, in upstream's order.
 *
 * Today this applies exactly one file: dba/davical.sql ends with
 * `new_db_revision(1,3,5)` and $c->want_dbversion is 1.3.6, so a fresh install
 * is one patch short of what the code expects. It is written as the general
 * walk rather than `runSqlFile('1.3.6.sql')` because the upgrade stage is the
 * case that matters: a redeploy onto a volume installed by an older DAViCal
 * has to replay whatever came between, and that list is not knowable when this
 * file is written.
 *
 * The alphabetic suffix ('1.2.1a.sql') is upstream's way of offering an
 * alternative for the same revision, and sorts after the bare form.
 */
function applyPatches(PDO $pdo, array $from): array
{
    $files = glob(DAVICAL_ROOT . '/dba/patches/*.sql') ?: [];

    $patches = [];
    foreach ($files as $file) {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)([a-z]?)\.sql$/', basename($file), $m)) {
            continue;
        }
        $patches[] = [
            'file' => $file,
            'rev' => [(int) $m[1], (int) $m[2], (int) $m[3]],
            'alt' => $m[4],
        ];
    }

    usort($patches, fn ($a, $b) => compareRevisions($a['rev'], $b['rev']) ?: strcmp($a['alt'], $b['alt']));

    $current = $from;
    foreach ($patches as $patch) {
        if (compareRevisions($patch['rev'], $current) <= 0) {
            continue;
        }

        say('applying patch ' . basename($patch['file']));
        runSqlFile($pdo, $patch['file']);

        // Each patch calls check_db_revision() on entry and new_db_revision()
        // on exit, so the database itself is what says whether it took.
        $after = currentRevision($pdo);
        if ($after === null || compareRevisions($after, $patch['rev']) !== 0) {
            fail('patch ' . basename($patch['file']) . ' did not move the schema revision');
        }
        $current = $after;
    }

    return $current;
}

/**
 * The built-in administrator, which dba/base-data.sql seeds as
 *
 *   INSERT INTO usr (..., username, password, ...)
 *        VALUES (1, ..., 'admin', '**nimda', ...)
 *
 * '**' is AWL's marker for a plaintext password (session_validate_password in
 * awl/inc/AWLUtilities.php: `if preg_match('/^\*\*.+$/', $we_have) return
 * "**$they_sent" == $we_have`), so that row is the literal password `nimda` --
 * "admin" backwards -- on an account with a public HTTPS domain and a CalDAV
 * endpoint that accepts Basic auth. create-database.sh overwrites it with a
 * pwgen'd value and prints it to a terminal nobody is watching here; this
 * replaces it with the password hooks/prepare.sh generated into
 * ~/.panelalpha/davical-app.env (0600, in a 0700 directory, outside the
 * checkout that a redeploy wipes).
 *
 * Stored as a salted SHA-1 in AWL's '*<salt>*{SSHA}<hash>' form rather than as
 * '**<plaintext>': it is the strongest of the three formats
 * session_validate_password understands, and it means the generated password
 * is not sitting in a table in the clear. SHA-1 is not a password hash by any
 * modern standard and this is not a choice -- it is what the application can
 * verify.
 *
 * Only ever applied while the password is still the seeded one, so a redeploy
 * leaves a password the owner has since changed alone.
 */
function rotateAdminPassword(PDO $pdo, string $plaintext): void
{
    $stored = $pdo->query('SELECT password FROM usr WHERE user_no = 1')->fetchColumn();
    if ($stored === false) {
        say('no user_no 1 row; nothing to rotate');

        return;
    }

    if ($stored !== '**nimda') {
        say("built-in admin password is not base-data.sql's seeded value; left untouched");

        return;
    }

    // session_salted_sha1(), transcribed from awl/inc/AWLUtilities.php so that
    // this script does not have to load AWL to run.
    $salt = substr(str_replace('*', '', base64_encode(sha1((string) random_int(100000, 9999999), true))), 2, 9);
    $hash = sprintf('*%s*{SSHA}%s', $salt, base64_encode(sha1($plaintext . $salt, true) . $salt));

    $update = $pdo->prepare('UPDATE usr SET password = ?, updated = current_date WHERE user_no = 1');
    $update->execute([$hash]);

    say('built-in admin password rotated (see ~/.panelalpha/davical-admin-credentials.txt)');
}

// ---------------------------------------------------------------------------

$adminPassword = getenv('DAVICAL_ADMIN_PASS');
if ($adminPassword === false || $adminPassword === '') {
    fail('DAVICAL_ADMIN_PASS is empty -- refusing to leave admin on the seeded `nimda`');
}

if (!is_file(AWL_ROOT . '/dba/awl-tables.sql')) {
    fail('AWL is not vendored at ' . AWL_ROOT . ' -- hooks/prepare.sh did not run');
}

say('connecting to ' . env('DAVICAL_DB_HOST') . ':' . env('DAVICAL_DB_PORT', '5432'));
$pdo = connect();
say('database reachable');

$revision = currentRevision($pdo);

if ($revision === null) {
    say('empty database: installing the DAViCal schema');

    // create-database.sh, in order: AWL's own tables, then AWL's schema
    // version machinery (which is what awl_db_revision and new_db_revision
    // come from), then DAViCal's tables -- which end at revision 1.3.5.
    runSqlFile($pdo, AWL_ROOT . '/dba/awl-tables.sql');
    runSqlFile($pdo, AWL_ROOT . '/dba/schema-management.sql');
    runSqlFile($pdo, DAVICAL_ROOT . '/dba/davical.sql');

    // Upstream's `update-davical-database --nopatch` pass. It has to happen
    // before base-data.sql, because base-data.sql calls privilege_to_bits(),
    // which caldav_functions.sql defines.
    refreshFunctionsAndViews($pdo);

    runSqlFile($pdo, DAVICAL_ROOT . '/dba/base-data.sql');

    $revision = currentRevision($pdo);
    if ($revision === null) {
        fail('schema load left no awl_db_revision row');
    }
    say(sprintf('schema installed at %d.%d.%d', ...$revision));
} else {
    say(sprintf('existing database at revision %d.%d.%d', ...$revision));
}

$revision = applyPatches($pdo, $revision);

// Upstream's second pass: the functions and views again, now that the patches
// have been applied.
refreshFunctionsAndViews($pdo);

if (compareRevisions($revision, WANT_DBVERSION) !== 0) {
    say(sprintf(
        'WARNING: schema is %d.%d.%d but this DAViCal wants %d.%d.%d',
        $revision[0], $revision[1], $revision[2],
        WANT_DBVERSION[0], WANT_DBVERSION[1], WANT_DBVERSION[2]
    ));
}

rotateAdminPassword($pdo, $adminPassword);

say(sprintf('setup complete; schema %d.%d.%d', ...$revision));
