#!/usr/bin/env php
<?php

/**
 * Replaces the sidecar database's engine-default credentials with ones
 * generated for this account, and hands Phorge a user that can create its 54
 * schemas.
 *
 * Why this file exists, measured on this engine:
 *
 * A compose file in the project root is harvested for backing services
 * (RuntimeSidecars::runtimeSidecarsFromProject) and this recipe's own
 * overrides/docker-compose.override.yml is one -- `exampleComposeFilenames()`
 * globs `docker-compose.*.yml`. The harvest keeps the service's image, command
 * and volumes and then REPLACES its `environment` with credentials the engine
 * derives from the application's database settings. For an application that is
 * not Laravel, those settings are the empty array (PhpStrategy::deploy, line
 * 76: `$artisan ? EnvFile::databaseSettings($projectDir) : []`), so every value
 * falls back to a default and the generated docker-compose.yml comes out as:
 *
 *     MYSQL_DATABASE: app
 *     MYSQL_USER: app
 *     MYSQL_PASSWORD: app
 *     MYSQL_ROOT_PASSWORD: app
 *     MYSQL_ROOT_HOST: '%'
 *
 * `app` is MysqlSidecar::FALLBACK_PASSWORD, line 18. `environment:` outranks
 * `env_file:` in Compose, so a password this recipe generates and passes
 * through env_file loses to it; and the only channel Compose interpolation
 * would read instead is `.env`, which ProjectEnvironment::apply() copies to
 * `.env.default` with mode 644 (engine defect #173) -- a world-readable
 * password is worse than this.
 *
 * So the credentials are fixed from inside, after the database is up, by the
 * one process that can read both of them: the app container has
 * MYSQL_ROOT_PASSWORD in its own environment (the engine puts it there) and
 * ~/.panelalpha/phorge/db.env bind-mounted at /panelalpha (0600, in a 0700
 * directory, owned by the account uid the container runs as).
 *
 * Idempotent, and that is the whole difficulty: on the second deploy the
 * engine still says `app` and the server no longer accepts it. Both are tried.
 *
 * mysqli rather than the `mysql` client, which the shared PHP base image does
 * not contain.
 */

function db_say($message) {
  fwrite(STDERR, '[phorge] '.$message."\n");
}

function db_fail($message) {
  db_say($message);
  exit(1);
}

$host = getenv('MYSQL_HOST');
if (!is_string($host) || $host === '') {
  // The service name in the compose project, which is what the engine sets
  // MYSQL_HOST to; this is only the fallback if it ever stops doing that.
  $host = 'db';
}
$port = (int)(getenv('MYSQL_PORT') ?: 3306);

$secret_file = '/panelalpha/db.env';
if (!is_readable($secret_file)) {
  db_fail('cannot read '.$secret_file.'; is the /panelalpha bind mount still in overrides/docker-compose.override.yml?');
}

$target = null;
foreach (file($secret_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
  if (strncmp($line, 'PHORGE_DB_PASSWORD=', 19) === 0) {
    $target = substr($line, 19);
  }
}
if (!is_string($target) || $target === '') {
  db_fail('PHORGE_DB_PASSWORD is not set in '.$secret_file);
}

// In order: the password we want (a redeploy, already rotated), then the one
// the engine wrote into the container's environment (the first deploy), then
// the literal fallback in case the engine ever stops exporting it to the app.
$candidates = array($target);
$engine_password = getenv('MYSQL_ROOT_PASSWORD');
if (is_string($engine_password) && $engine_password !== '') {
  $candidates[] = $engine_password;
}
$candidates[] = 'app';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = null;
$used = null;
// The database is `depends_on: service_healthy`, so it is already answering by
// the time this runs; the retries are for the few seconds between "answering"
// and "finished its own grant table reload".
for ($attempt = 0; $attempt < 30 && $conn === null; $attempt++) {
  foreach ($candidates as $candidate) {
    $try = @new mysqli($host, 'root', $candidate, '', $port);
    if ($try->connect_errno === 0) {
      $conn = $try;
      $used = $candidate;
      break;
    }
  }
  if ($conn === null) {
    sleep(2);
  }
}

if ($conn === null) {
  db_fail('could not connect to the database at '.$host.':'.$port.' as root with any known password');
}

if ($used === $target) {
  db_say('database credentials are already this account\'s own');
} else {
  db_say('replacing the engine\'s default database credentials with this account\'s own');
}

$quoted = "'".$conn->real_escape_string($target)."'";

// root@'%' is the account the engine creates (MYSQL_ROOT_HOST: '%') and the one
// Phorge will use: it is the only account that may CREATE DATABASE, which
// Phorge does 54 times. root@'localhost' exists inside the container and is
// rotated with it so the two do not drift.
foreach (array("'root'@'%'", "'root'@'localhost'") as $account) {
  if (!$conn->query('ALTER USER '.$account.' IDENTIFIED BY '.$quoted)) {
    // root@'localhost' may not exist depending on the image's own setup, and
    // an account that is not there is not an error worth failing a deploy for.
    db_say('could not set the password for '.$account.': '.$conn->error);
  }
}

// The engine also creates `app`@'%' with the password `app` and ALL PRIVILEGES
// on the `app` schema. Nothing uses it -- Phorge's data is in its own 54
// schemas -- and a published username and password on a live server is worth
// one DROP.
if (!$conn->query("DROP USER IF EXISTS 'app'@'%'")) {
  db_say("could not drop the engine's default 'app' user: ".$conn->error);
}

if (!$conn->query('FLUSH PRIVILEGES')) {
  db_fail('FLUSH PRIVILEGES failed: '.$conn->error);
}

// Proof rather than hope: reconnect with the new password before the setup
// script writes it into Phorge's configuration. A rotation that silently did
// nothing would otherwise surface as `bin/storage upgrade` failing with #1045
// after the config file had already been written.
$check = @new mysqli($host, 'root', $target, '', $port);
if ($check->connect_errno !== 0) {
  db_fail('the new database password does not work: '.$check->connect_error);
}
$check->close();
$conn->close();

db_say('database is reachable as root with this account\'s own password');
