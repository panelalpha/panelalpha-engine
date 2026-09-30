<?php
// config.php.sample with the database the engine provisions (database: mysql),
// read from the container environment.
define('PSM_DB_PREFIX', 'monitor_');
define('PSM_DB_USER', getenv('DB_USERNAME'));
define('PSM_DB_PASS', getenv('DB_PASSWORD'));
define('PSM_DB_NAME', getenv('DB_DATABASE'));
define('PSM_DB_HOST', getenv('DB_HOST'));
define('PSM_DB_PORT', getenv('DB_PORT') ?: '3306');
define('PSM_BASE_URL', '');
define('PSM_WEBCRON_KEY', '');
define('PSM_WEBCRON_ENABLE_IP_WHITELIST', 'true');
define('PSM_PUBLIC', false);
define('PSM_UPTIME_ARCHIVE', 'monthly');
