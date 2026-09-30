<?php
// Mounted as config/parameters_local.php. The engine's proxy terminates HTTPS;
// trust its X-Forwarded-Proto so Mautic builds https URLs (site_url included).
$parameters = [
    'trusted_proxies' => ['0.0.0.0/0'],
];
