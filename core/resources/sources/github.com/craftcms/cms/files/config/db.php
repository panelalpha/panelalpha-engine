<?php
/**
 * Database settings, written by PanelAlpha.
 *
 * `database: mysql` in panelalpha.yaml gets the account a database and a user
 * on its own MySQL server, and PhpEnvironment::mysql() hands them to the
 * container as DB_CONNECTION / DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME /
 * DB_PASSWORD -- compose `environment:` entries, so the password is never
 * written to a file in the checkout.
 *
 * Craft reads CRAFT_DB_SERVER / CRAFT_DB_USER / CRAFT_DB_DATABASE and friends
 * (Config::_createConfigObj, envPrefix `CRAFT_DB_`), which are not the names
 * the engine writes, and it has no SQLite driver at all -- craft\db\Connection
 * supports mysql and pgsql only. So this file is the translation, and it is the
 * whole of it.
 *
 * Environment values still win over what is returned here (App::envConfig() is
 * applied on top), so an operator who sets CRAFT_DB_* in the panel's env_vars
 * overrides the provisioned database rather than fighting it.
 */

use craft\config\DbConfig;
use craft\helpers\App;

$driver = App::env('DB_CONNECTION') === 'pgsql' ? 'pgsql' : 'mysql';

return DbConfig::create()
    ->driver($driver)
    ->server(App::env('DB_HOST') ?: '127.0.0.1')
    ->port((int)(App::env('DB_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306)))
    ->database((string)(App::env('DB_DATABASE') ?: ''))
    ->user((string)(App::env('DB_USERNAME') ?: ''))
    ->password((string)(App::env('DB_PASSWORD') ?: ''))
    ->tablePrefix((string)(App::env('CRAFT_DB_TABLE_PREFIX') ?: ''));
