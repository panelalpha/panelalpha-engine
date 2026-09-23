<?php
/**
 * Runs inside the container on the install and upgrade stages, before Apache
 * binds. Idempotent: the upgrade stage replays it on every redeploy.
 *
 * It sits in the project root rather than in a panelalpha/ directory because
 * the project root *is* the document root here, and the generated vhost denies
 * engine files by name:
 *
 *     <FilesMatch "^(?:docker-compose\.ya?ml|panelalpha[-.])">
 *
 * (resources/deploy/templates/apache-vhost.stub) -- so `panelalpha-setup.php`
 * is denied where `panelalpha/setup.php` would be served as plain text.
 *
 * Two jobs, both of which upstream leaves to a human with a browser:
 *
 *  1. The schema. Upstream ships installation.php, an unauthenticated page that
 *     creates both tables for whoever loads it and then asks to be deleted by
 *     hand. On a public URL that is the first-visitor-wins hole found in
 *     CouchCMS and Chyrp Lite; hooks/prepare.sh deletes the file and this runs
 *     the DDL from the deploy instead.
 *
 *  2. The first user. Upstream's README: "Create a user, the first one will be
 *     an admin". login.php:19 grants admin to whoever registers while the users
 *     table is empty -- so an empty table on a public name is an administrator
 *     account waiting for a stranger. The account is created here, from a
 *     password hooks/prepare.sh generated into ~/.panelalpha/, so the slot is
 *     taken before Apache has bound.
 */

const TABLE_ROWS = 'shortener';
const TABLE_USERS = 'users';
const ADMIN_USER = 'admin';
const PASSWORD_FILE = '/app/.panelalpha-admin-password';

function say(string $message): void
{
    fwrite(STDERR, '[simple-url-shortener] ' . $message . "\n");
}

function fail(string $message): never
{
    say($message);
    exit(1);
}

$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_DATABASE') ?: '';
$user = getenv('DB_USERNAME') ?: '';
$pass = getenv('DB_PASSWORD') ?: '';

if ($host === '' || $name === '') {
    fail("no DB_HOST/DB_DATABASE in the environment; is 'database: mysql' still in panelalpha.yaml?");
}

// The engine already gates this: `database: mysql` makes the generated
// entrypoint run its own `wait-for-mysql` step -- 60 tries at 2s, opening a
// PDO connection to the server -- immediately before this command, so engine#90
// is already answered for the database and nothing here has to re-answer it.
// What that step does not cover is the database *and user* existing rather than
// the server answering, which is a different part of the deploy. Measured on
// this host over four deploys and three restarts, this connected on attempt 1
// every time; five tries is a guard against a race, not a wait for one.
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
$db = null;
$attempts = 0;
for ($i = 1; $i <= 5; $i++) {
    $attempts = $i;
    try {
        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        break;
    } catch (PDOException $e) {
        if ($i === 5) {
            fail('cannot reach the database after 5 tries: ' . $e->getMessage());
        }
        sleep(2);
    }
}
if (!$db instanceof PDO) {
    fail('cannot reach the database');
}
say(sprintf('connected to %s@%s:%s on attempt %d', $name, $host, $port, $attempts));

/*
 * The schema, from installation.php, with three corrections. It is DDL rather
 * than code, so writing a better version of it costs nothing and diverging
 * from upstream here is not a patch anyone has to maintain.
 *
 *  - `short` is VARCHAR(30), not `char(URL_SIZE)`. URL_SIZE is 5, the length
 *    of a *generated* code -- but index.php's form offers an "Optional short
 *    url" field with maxlength=30, and shorten.php:25 stores whatever it is
 *    given. On upstream's char(5) under MySQL's default STRICT_TRANS_TABLES
 *    every custom code longer than five characters is a `Data too long`
 *    error, and CHAR right-pads the short ones so the lookup in index.php:12
 *    never matches them again. VARCHAR(30) is what the form already promises.
 *
 *  - `users.username` is the primary key. Upstream's users table has no key of
 *    any kind, so two accounts can hold the same name -- and login.php:34
 *    fetches the first row that matches, which is whichever one MySQL returns.
 *
 *  - IF NOT EXISTS, because this replays on the upgrade stage.
 */
$db->exec(
    'CREATE TABLE IF NOT EXISTS `' . TABLE_ROWS . '` ('
    . '`short` varchar(30) NOT NULL,'
    . '`url` varchar(700) NOT NULL,'
    . '`comment` varchar(30) DEFAULT NULL,'
    . '`views` int DEFAULT 0,'
    . '`username` varchar(25) DEFAULT NULL,'
    . '`date` datetime NOT NULL,'
    . 'PRIMARY KEY (`short`),'
    . 'KEY `username_date` (`username`, `date`)'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$db->exec(
    'CREATE TABLE IF NOT EXISTS `' . TABLE_USERS . '` ('
    . '`username` varchar(25) NOT NULL,'
    . '`password` char(128) NOT NULL,'
    . '`email` varchar(255) NOT NULL,'
    . '`token` char(15) NOT NULL,'
    . '`admin` tinyint(1) NOT NULL DEFAULT 0,'
    . 'PRIMARY KEY (`username`),'
    . 'KEY `token` (`token`)'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$rows = (int) $db->query('SELECT COUNT(*) AS c FROM `' . TABLE_ROWS . '`')->fetch()['c'];
$users = (int) $db->query('SELECT COUNT(*) AS c FROM `' . TABLE_USERS . '`')->fetch()['c'];
say(sprintf('schema ready: %d short link(s), %d user(s)', $rows, $users));

if ($users > 0) {
    say('a user already exists; leaving the accounts alone');
    exit(0);
}

$password = is_readable(PASSWORD_FILE) ? trim((string) file_get_contents(PASSWORD_FILE)) : '';
if ($password === '') {
    fail('no admin password at ' . PASSWORD_FILE . '; hooks/prepare.sh should have written it');
}

/*
 * The token is the bookmarklet's credential. index.php:46 bakes it into the
 * "Bookmark" link's javascript: URL, and shorten.php:68 accepts it in a query
 * string as a whole authentication -- one GET with ?url= and ?token= creates a
 * short link with no session. The column is char(15); upstream fills it with
 * uniqid(), which is the current time in hex and therefore guessable to within
 * a few thousand tries by anyone who knows roughly when the account was made.
 * 14 hex characters of random_bytes() is the same width and not a clock.
 */
$token = bin2hex(random_bytes(7));
$insert = $db->prepare(
    'INSERT INTO `' . TABLE_USERS . '` (username, password, email, token, admin) VALUES (?,?,?,?,1)'
);
$insert->execute([
    ADMIN_USER,
    password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
    'admin@' . (getenv('DEFAULT_DOMAIN') ?: 'localhost'),
    $token,
]);

say(sprintf(
    "created the '%s' account (admin); its password is in ~/.panelalpha/simple-url-shortener/admin-password",
    ADMIN_USER
));
