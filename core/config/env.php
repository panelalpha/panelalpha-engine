<?php

return [
    'USERS_DB_HOST' => env('USERS_DB_HOST'),
    'USERS_DB_USERNAME' => env('USERS_DB_USERNAME'),
    'USERS_DB_PASSWORD' => env('USERS_DB_PASSWORD'),
    'KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS' => env('KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS'),
    'APP_LITE_PROXY_ENABLED' => env('APP_LITE_PROXY_ENABLED', 0),
    // Container engine tenant applications run on. 'dind' (per-account
    // Docker-in-Docker on sysbox) unless another engine is registered with
    // App\Lib\Deploy\Engine\EngineFactory::register().
    'DEPLOY_ENGINE' => env('DEPLOY_ENGINE'),
    // How account containers are isolated: 'sysbox-runc' (default) or
    // 'privileged', the latter only for an engine that itself runs inside a
    // sysbox container. See App\System\Project\Dind\AccountRuntime.
    'DIND_RUNTIME' => env('DIND_RUNTIME'),
    // Images to seed into an account at once. Unset = derived from the host's
    // cores, load and free memory; set it only to override that.
    'DIND_SEED_CONCURRENCY' => env('DIND_SEED_CONCURRENCY'),
    'APP_LITE_PROXY_CA_HOST' => env('APP_LITE_PROXY_CA_HOST', 'panel.app-lite.palocal'),
    'APP_LITE_PROXY_CA_PORT' => env('APP_LITE_PROXY_CA_PORT', 80),
    'APP_LITE_PROXY_AA_HOST' => env('APP_LITE_PROXY_AA_HOST', 'panel.app-lite.palocal'),
    'APP_LITE_PROXY_AA_PORT' => env('APP_LITE_PROXY_AA_PORT', 81),
    'APP_LITE_PROXY_WS_REWRITE_HOST' => env('APP_LITE_PROXY_WS_REWRITE_HOST', 'panel.app-lite.palocal'),
    'APP_LITE_PROXY_WS_REWRITE_PORT' => env('APP_LITE_PROXY_WS_REWRITE_PORT', 83),
    'APP_LITE_PROXY_WS_HOST' => env('APP_LITE_PROXY_WS_HOST', 'websocket.app-lite.palocal'),
    'APP_LITE_PROXY_WS_PORT' => env('APP_LITE_PROXY_WS_PORT', 6001),
    'APP_LITE_CA_PORT' => env('APP_LITE_CA_PORT'),
    'APP_LITE_CA_DOMAIN' => env('APP_LITE_CA_DOMAIN'),
    'APP_LITE_AA_PORT' => env('APP_LITE_AA_PORT', 8443),
    'APP_LITE_AA_DOMAIN' => env('APP_LITE_AA_DOMAIN'),
    // Site password gate UX for nginx-proxy: custom HTML form (default) or
    // browser Basic Auth popup. Password verify is always password-only.
    'SITE_PASSWORD_AUTH_MODE' => env('SITE_PASSWORD_AUTH_MODE', 'custom'),
];
