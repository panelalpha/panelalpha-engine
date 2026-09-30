# Emoncms (github.com/emoncms/emoncms)

Energy and sensor data logging and graphing: PHP on MySQL.

## What the recipe does

- `database: mysql` gives the account a MySQL database; `files/settings.php`
  reads its `DB_*` credentials from the container environment. Emoncms creates
  its tables on the first request.
- `overrides/docker-compose.override.yml` mounts the named volume
  `emoncms-data` at `/var/opt/emoncms` (PHPFina/PHPTimeSeries feed files and
  the log). `data-init` creates the directories owned by the account's uid.
- Redis and MQTT stay disabled (upstream defaults), so feeds are written
  directly and no background services are needed.

The first user registered on the login page is the administrator.
