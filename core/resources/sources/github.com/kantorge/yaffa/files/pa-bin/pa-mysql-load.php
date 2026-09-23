<?php

/**
 * The body of the `mysql` shim: read the SQL Laravel pipes on stdin (its
 * squashed schema dump) and execute it against the database through pdo_mysql,
 * because the runtime image carries no mysql client for Laravel to shell out
 * to. Credentials come from the LARAVEL_LOAD_* variables Laravel exports to the
 * command. pdo_mysql runs the whole multi-statement dump in one exec; the dump
 * is DROP/CREATE with foreign-key checks disabled at the top, so order does not
 * matter and a fresh load is clean. Any argv Laravel passed is ignored.
 */

$host = getenv('LARAVEL_LOAD_HOST') ?: '127.0.0.1';
$port = getenv('LARAVEL_LOAD_PORT') ?: '3306';
$name = getenv('LARAVEL_LOAD_DATABASE') ?: '';
$user = getenv('LARAVEL_LOAD_USER') ?: '';
$pass = getenv('LARAVEL_LOAD_PASSWORD');
$pass = $pass === false ? '' : $pass;

$sql = stream_get_contents(STDIN);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "pa-mysql shim: no SQL on stdin, nothing to load\n");
    exit(0);
}

try {
    $dsn = "mysql:host={$host};port={$port}" . ($name !== '' ? ";dbname={$name}" : '');
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec($sql);
    fwrite(STDERR, 'pa-mysql shim: loaded ' . strlen($sql) . " bytes via pdo_mysql\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'pa-mysql shim: ' . $e->getMessage() . "\n");
    exit(1);
}
