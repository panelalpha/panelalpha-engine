<?php
/**
 * Chyrp Lite setup, run by PanelAlpha on the install and upgrade stages,
 * inside the container, before Apache binds.
 *
 * It drives upstream's own install.php and upgrade.php rather than
 * reimplementing their schema: they are ordinary top-level PHP scripts that
 * read $_POST and $_SERVER, so a CLI process that sets those and includes the
 * file gets exactly what the browser form would have produced -- including
 * every future migration upstream adds to upgrade.php.
 *
 * This file lives in the project root rather than in a panelalpha/ directory
 * because the project root *is* the document root here, and the generated
 * vhost denies exactly two shapes of engine file by name:
 *
 *     <FilesMatch "^(?:docker-compose\.ya?ml|panelalpha[-.])">
 *
 * so `panelalpha-install.php` is denied while `panelalpha/install.php` would
 * be served. (resources/deploy/templates/apache-vhost.stub)
 *
 * Modes: with no argument it is the whole orchestration. The private modes
 * `_install` and `_upgrade` are re-entered as child processes so that an
 * upstream script which ends in exit() or error() reports a status this
 * process can act on, instead of taking the orchestration down with it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access denied.\n");
}

const APP_DIR = '/app';

// The bind mount the compose override adds: ~/.panelalpha/chyrp-lite on the
// account. Everything that has to outlive a redeploy is here, because a
// redeploy clears and re-clones ~/project (engine#173).
const STORAGE = '/data';

function say(string $message): void
{
    fwrite(STDERR, "[chyrp-lite] {$message}\n");
}

function fail(string $message): never
{
    say($message);
    exit(1);
}

/** The account's public https address, from the environment the engine set. */
function appUrl(): string
{
    $url = getenv('APP_URL') ?: '';
    $url = rtrim(trim($url), '/');

    if ($url === '' || parse_url($url, PHP_URL_HOST) === null) {
        fail('APP_URL is not a URL; the engine sets it from the account domain');
    }

    return $url;
}

/**
 * The administrator, generated once per account and kept out of the checkout.
 *
 * install.php creates the first administrator from whatever it is posted and
 * has no gate but the config file it writes at the end, so on a public address
 * whoever loads it first becomes that administrator. It is run from here
 * instead, before Apache binds, with these credentials.
 *
 * Written once and never rewritten: the install is a no-op once the config
 * file is there, so a regenerated password would stop matching the account in
 * a database that survived the redeploy.
 *
 * @return array{login: string, password: string, email: string}
 */
function credentials(): array
{
    $path = STORAGE . '/admin-credentials';
    $host = parse_url(appUrl(), PHP_URL_HOST);

    if (!is_file($path)) {
        // sanitize_db_string() and the login form are happy with the whole
        // alphabet; this avoids the characters that are ambiguous when the
        // password is read off a terminal and retyped.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 24; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $old = umask(0o077);
        $written = file_put_contents($path, <<<EOF
        # Written by PanelAlpha on first deploy. This is the Chyrp Lite
        # administrator for this account -- sign in at
        # {$host}/?action=login .
        #
        # Chyrp Lite's install.php creates the first administrator, and on a
        # public address that is whoever loads it first. It was run at deploy
        # time instead, with these values, and install.php has been removed.
        # Change the password under Controls -> Account and this file stops
        # being interesting.
        CHYRP_ADMIN_LOGIN=admin
        CHYRP_ADMIN_PASSWORD={$password}
        CHYRP_ADMIN_EMAIL=admin@{$host}

        EOF);
        umask($old);

        if ($written === false) {
            fail('could not write ' . $path . '; is ~/.panelalpha/chyrp-lite writable?');
        }

        @chmod($path, 0o600);
    }

    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value);
    }

    foreach (['CHYRP_ADMIN_LOGIN', 'CHYRP_ADMIN_PASSWORD', 'CHYRP_ADMIN_EMAIL'] as $key) {
        if (($values[$key] ?? '') === '') {
            fail("{$key} is missing from {$path}");
        }
    }

    return [
        'login' => $values['CHYRP_ADMIN_LOGIN'],
        'password' => $values['CHYRP_ADMIN_PASSWORD'],
        // is_email() (includes/helpers.php) wants a dotted domain, so
        // admin@localhost would be rejected by the installer's own validation.
        'email' => $values['CHYRP_ADMIN_EMAIL'],
    ];
}

/**
 * Make a CLI process look enough like the POST the upstream script expects.
 *
 * STORAGE_DIR is the one that matters: both scripts read it from
 * $_SERVER['CHYRP_STORAGE_DIR'] and fall back to includes/, which is inside
 * the checkout a redeploy clears. Set here rather than relied upon from the
 * process environment, because whether CLI PHP copies the environment into
 * $_SERVER depends on variables_order, and the base image loads no php.ini
 * at all (engine#185).
 *
 * @param array<string, string> $post
 */
function fakeRequest(string $script, array $post): void
{
    $url = appUrl();

    $_SERVER['CHYRP_STORAGE_DIR'] = STORAGE;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SERVER_NAME'] = parse_url($url, PHP_URL_HOST);
    $_SERVER['HTTP_HOST'] = parse_url($url, PHP_URL_HOST);
    $_SERVER['HTTPS'] = str_starts_with($url, 'https://') ? 'on' : 'off';
    $_SERVER['REQUEST_URI'] = '/' . $script;
    $_SERVER['SCRIPT_NAME'] = '/' . $script;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'PanelAlpha';

    $_POST = $post;
    $_GET = [];
    $_REQUEST = $post;
}

/** Has Chyrp been installed? The config file is the only thing that says so. */
function installed(): bool
{
    return is_file(STORAGE . '/config.json.php');
}

/**
 * Re-enter this file as a child process, so an exit() in there is survivable.
 *
 * The output is captured rather than passed through: both upstream scripts
 * render a full HTML page whatever happens, and several hundred lines of
 * stylesheet in the container log on every first boot buries the one line that
 * matters. It is printed only when something went wrong, and then in full.
 */
function child(string $mode): int
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($mode);
    $output = [];
    exec($command . ' 2>&1', $output, $status);

    $GLOBALS['chyrp_child_output'] = implode("\n", $output);

    return $status;
}

/** What the upstream script said, for a failure message. */
function childComplaints(): string
{
    $output = (string) ($GLOBALS['chyrp_child_output'] ?? '');

    // Both scripts report their problems as <span role="alert"> before the
    // page, and headline them in <h1>/<h2>.
    preg_match_all('~<(?:span role="alert"|h1|h2)[^>]*>(.*?)</~s', $output, $m);
    $lines = array_filter(array_map(fn ($s) => trim(html_entity_decode(strip_tags($s))), $m[1] ?? []));

    return $lines === [] ? $output : implode(' | ', $lines);
}

// ---------------------------------------------------------------- the modes

$mode = $argv[1] ?? '';

if ($mode === '_install') {
    $admin = credentials();

    fakeRequest('install.php', [
        'install' => 'yes',
        'adapter' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'username' => getenv('DB_USERNAME') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'database' => getenv('DB_DATABASE') ?: '',
        'prefix' => '',
        'url' => appUrl(),
        'name' => 'Chyrp Lite',
        'description' => '',
        'timezone' => 'UTC',
        'locale' => 'en_US',
        'login' => $admin['login'],
        'password1' => $admin['password'],
        'password2' => $admin['password'],
        'email' => $admin['email'],
    ]);

    require APP_DIR . '/install.php';
    exit(0);
}

if ($mode === '_upgrade') {
    fakeRequest('upgrade.php', ['upgrade' => 'yes']);

    require APP_DIR . '/upgrade.php';
    exit(0);
}

if ($mode !== '') {
    fail("unknown mode '{$mode}'");
}

// ------------------------------------------------------- the orchestration

if (!is_dir(STORAGE) || !is_writable(STORAGE)) {
    fail(STORAGE . ' is not a writable directory; is the ~/.panelalpha/chyrp-lite mount there?');
}

if (!is_file(APP_DIR . '/index.php') || !is_dir(APP_DIR . '/includes')) {
    fail('this does not look like a Chyrp Lite checkout');
}

if (!installed()) {
    if ((getenv('DB_DATABASE') ?: '') === '') {
        fail("no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?");
    }

    say('running the Chyrp Lite installer');

    if (child('_install') !== 0 || !installed()) {
        say('the installer did not produce ' . STORAGE . '/config.json.php. Chyrp Lite said:');
        fail(childComplaints());
    }

    say('installed; the administrator password is in ~/.panelalpha/chyrp-lite/admin-credentials');
} elseif (is_file(APP_DIR . '/upgrade.php')) {
    // Every deploy, not only a version bump. upgrade.php is idempotent -- each
    // migration tests for its own change first -- and it is also what puts the
    // schema right after the checkout moved forward. Without this the first
    // visitor after an upgrade would be the one paying for the ALTER TABLEs,
    // and until then includes/upgrading.lock makes every request a 503.
    //
    // It also re-does the cacert.pem rename. install.php renames
    // includes/cacert.pem to a random name and records it in the config; the
    // config survives a redeploy and the renamed file does not, so
    // rename_cacert_pem() in upgrade.php is what stops curl_setopt CURLOPT_CAINFO
    // pointing at a file that is no longer there (includes/helpers.php:2461).
    say('running the Chyrp Lite upgrader');

    if (child('_upgrade') !== 0) {
        say('the upgrader exited non-zero. Chyrp Lite said:');
        fail(childComplaints());
    }
}

// ------------------------------------------------------ the account's URL
//
// Chyrp stores its own address in the config and builds every link, redirect,
// feed entry and session cookie domain from it (`secure` is set from the
// scheme, includes/helpers.php:43). install.php wrote whatever APP_URL was on
// the first deploy; if the account has been given a different domain since,
// nothing in Chyrp notices. Done as plain JSON rather than through Config,
// because the file is a 403 guard line followed by the object and this needs
// no bootstrap.
$configFile = STORAGE . '/config.json.php';
$guard = "<?php header(\"Status: 403\"); exit(\"Access denied.\"); ?>\n";
$raw = (string) @file_get_contents($configFile);
$data = json_decode(str_replace($guard, '', $raw), true);

if (is_array($data) && ($data['url'] ?? null) !== appUrl()) {
    say('pointing the site at ' . appUrl() . ' (was ' . ($data['url'] ?? 'unset') . ')');
    $data['url'] = appUrl();
    $data['chyrp_url'] = appUrl();

    if (@file_put_contents($configFile, $guard . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        fail('could not rewrite ' . $configFile);
    }
}

// ------------------------------------------------- close the two open doors
//
// Upstream means both of these to be deleted after use -- install.php's own
// closing screen says so, and the Dockerfile's entrypoint tries to remove them
// (it looks in includes/ and they are in the root, so it never does). Neither
// has any authentication:
//
//   install.php  creates the first administrator. Gated only by the config
//                file, which now exists -- but deleting it is what makes that
//                unconditional.
//   upgrade.php  runs the migrations from an anonymous POST upgrade=yes. No
//                token, no login, no lock. It has been run above, from the
//                deploy, so nothing is left for a visitor to do with it.
//
// They come back with every clone, so this runs on every deploy.
foreach (['install.php', 'upgrade.php'] as $name) {
    if (is_file(APP_DIR . '/' . $name) && !@unlink(APP_DIR . '/' . $name)) {
        fail("could not remove {$name} from the document root");
    }
}

// includes/upgrading.lock is a *tracked file* in this repository, containing
// the word UPGRADING. includes/common.php:373 answers 503 to every request
// while it is there, which is why a straight clone of Chyrp Lite serves
// nothing but "This resource is temporarily unable to serve your request."
// install.php and upgrade.php unlink it when they finish; this is the
// belt-and-braces for the case where neither ran.
if (is_file(APP_DIR . '/includes/upgrading.lock') && !@unlink(APP_DIR . '/includes/upgrading.lock')) {
    fail('could not remove includes/upgrading.lock; every request would answer 503');
}

say('setup finished');
