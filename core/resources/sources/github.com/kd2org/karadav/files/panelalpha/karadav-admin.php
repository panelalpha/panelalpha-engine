<?php

/*
 * Gives KaraDAV's seeded admin the login the engine generated (`credentials:`
 * in panelalpha.yaml; the app container reads ~/.panelalpha/app-credentials.env).
 *
 * KaraDAV has no installer: init.php, on the first request, creates the SQLite
 * database with the admin `demo` / `karadavdemo` (published in doc/INSTALL.md)
 * and signs that request in. On PanelAlpha the first request is the engine's
 * health check, so visitors met a sign-in form whose only account was the
 * published one. Here, before Apache serves, init.php runs from the CLI and
 * creates the database the same way, then the demo admin gets the generated
 * username and password through KaraDAV's own User::edit (which also moves its
 * file storage to the new name), only while it still has the published
 * password. A password changed in KaraDAV is never touched.
 *
 * This file lives in panelalpha/, outside the document root (www/).
 */

namespace KaraDAV;

const PUBLISHED_LOGIN = 'demo';
const PUBLISHED_PASSWORD = 'karadavdemo';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] karadav: '.$message."\n");
}

$login = strtolower(trim((string) getenv('KARADAV_ADMIN_USER')));
$password = (string) getenv('KARADAV_ADMIN_PASSWORD');
if ($login === '' || $password === '') {
    say('KARADAV_ADMIN_USER or KARADAV_ADMIN_PASSWORD is not in the environment; the seeded admin is left as it is');
    exit(0);
}

// init.php works out WWW_URL from the request when the config does not set
// it; from the CLI there is none. Only this run sees the value.
$_SERVER['SERVER_NAME'] ??= 'localhost';
$_SERVER['SERVER_PORT'] ??= 80;

require __DIR__.'/../init.php';

$users = new Users;
$demo = $users->fetch(PUBLISHED_LOGIN);
if ($demo === null || ! password_verify(PUBLISHED_PASSWORD, (string) $demo->password)) {
    say('no admin has the published login; left alone');
    exit(0);
}

$changes = ['password' => $password];
if ($login !== PUBLISHED_LOGIN) {
    $changes['login'] = $login;
}
$demo->edit($changes);
say("the seeded admin now signs in as {$login} with the generated password");
