<?php
// Prints the ILIAS CLI setup config (components/ILIAS/setup_/README.md) from the
// account's environment: its MySQL, the site's address, data on volumes.
$e = static fn (string $k, string $d = ''): string => (string) (getenv($k) ?: $d);
echo json_encode([
    'common' => ['client_id' => 'ilias', 'server_timezone' => $e('TZ', 'UTC')],
    'database' => [
        'type' => 'innodb',
        'host' => $e('DB_HOST'),
        'port' => (int) $e('DB_PORT', '3306'),
        'database' => $e('DB_DATABASE'),
        'user' => $e('DB_USERNAME'),
        'password' => $e('DB_PASSWORD'),
        'create_database' => false,
    ],
    'filesystem' => ['data_dir' => '/var/lib/ilias/data'],
    'http' => [
        'path' => $e('APP_URL'),
        'https_autodetection' => ['header_name' => 'X-Forwarded-Proto', 'header_value' => 'https'],
    ],
    'logging' => [
        'enable' => true,
        'path_to_logfile' => '/var/lib/ilias/logs/ilias.log',
        'errorlog_dir' => '/var/lib/ilias/logs',
    ],
    'systemfolder' => ['contact' => [
        'firstname' => 'ILIAS',
        'lastname' => 'Administrator',
        'email' => 'root@' . $e('SERVER_NAME', 'localhost'),
    ]],
    'utilities' => ['path_to_convert' => '/usr/bin/convert'],
    'preview' => ['path_to_ghostscript' => '/usr/bin/gs'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
