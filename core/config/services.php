<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WordPress migrator plugin release
    |--------------------------------------------------------------------------
    |
    | The pinned release installed on every source and destination site,
    | repacked so the install zip root is wordpress-migrator/. A download
    | that does not match sha256 is refused. To move to a newer release,
    | set both variables to that release's zip URL and its checksum.
    |
    */
    'wordpress_migrator' => [
        'release_url' => env(
            'WORDPRESS_MIGRATOR_RELEASE_URL',
            'https://github.com/panelalpha/panelalpha-wordpress-migrator/releases/download/v1.1.4/wordpress-migrator.zip',
        ),
        'sha256' => env(
            'WORDPRESS_MIGRATOR_SHA256',
            'f636ceb62c5be51525763f4ae71e669ac0c116d7988eb0190c94b8183a8cb13f',
        ),
    ],

    // Who may redeem a phpMyAdmin SSO token (PmaSso): comma-separated
    // hostnames, addresses or CIDR ranges. `172.16.0.0/12` restores the old
    // "any private address" rule for a topology this default does not fit.
    'phpmyadmin' => [
        'sso_sources' => env('PMA_SSO_SOURCES', 'phpmyadmin-users.shared-hosting.palocal'),
    ],

];
