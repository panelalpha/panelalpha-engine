<?php
// Written by PanelAlpha. b1gMail's setup wizard normally writes this file with
// the database password inlined; ~/project is wiped and re-cloned on every
// deploy, so it reads the environment instead and keeps no secret of its own.
// b1gMail checks mysqli return values and never calls mysqli_report(); its DB
// layer (serverlib/db.class.php:154) treats a failed query as `false`. Since
// PHP 8.1 the default is MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT, which turns
// every one of those into an uncaught mysqli_sql_exception. The image is PHP
// 8.3, so restore the semantics the application was written against.
mysqli_report(MYSQLI_REPORT_OFF);

$mysql = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'user' => getenv('DB_USERNAME') ?: '',
    'pass' => getenv('DB_PASSWORD') ?: '',
    'db' => getenv('DB_DATABASE') ?: '',
    'prefix' => 'bm60_',
];

// The signing key outlives the checkout: it lives in ~/.panelalpha/b1gmail/
// and reaches the container as a second env_file.
define('B1GMAIL_SIGNKEY', getenv('B1GMAIL_SIGNKEY') ?: '');
define('DB_CHARSET', 'utf8mb4');
