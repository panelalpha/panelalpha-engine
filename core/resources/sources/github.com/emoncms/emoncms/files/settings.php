<?php
// Emoncms settings (example.settings.php format): the database the engine
// provisions (database: mysql), read from the container environment.
$settings = array(
    "sql" => array(
        "server"   => getenv('DB_HOST'),
        "database" => getenv('DB_DATABASE'),
        "username" => getenv('DB_USERNAME'),
        "password" => getenv('DB_PASSWORD'),
        "port"     => (int) (getenv('DB_PORT') ?: 3306),
        "dbtest"   => true
    ),
    // Feed data and the log live on the emoncms-data volume.
    "feed" => array(
        "phpfina"       => array("datadir" => "/var/opt/emoncms/phpfina/"),
        "phptimeseries" => array("datadir" => "/var/opt/emoncms/phptimeseries/")
    ),
    "log" => array(
        "location" => "/var/opt/emoncms/log"
    )
);
