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
    | Tag archive downloaded once per migration task and repacked so the
    | install zip root is wordpress-migrator/ (not the GitHub tag folder).
    | The default follows the newest release; set the variable to a
    | .../releases/download/<tag>/wordpress-migrator.zip URL to pin one.
    |
    */
    'wordpress_migrator' => [
        'release_url' => env(
            'WORDPRESS_MIGRATOR_RELEASE_URL',
            'https://github.com/panelalpha/panelalpha-wordpress-migrator/releases/latest/download/wordpress-migrator.zip',
        ),
    ],

    // Who may redeem a phpMyAdmin SSO token (PmaSso): comma-separated
    // hostnames, addresses or CIDR ranges. `172.16.0.0/12` restores the old
    // "any private address" rule for a topology this default does not fit.
    'phpmyadmin' => [
        'sso_sources' => env('PMA_SSO_SOURCES', 'phpmyadmin-users.shared-hosting.palocal'),
    ],

];
