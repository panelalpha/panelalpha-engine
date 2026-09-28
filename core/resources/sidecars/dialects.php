<?php

/**
 * What the engine knows about other people's vocabularies.
 *
 * Everything about a Compose service is derived from the file and the image:
 * its name, its port, its credentials. Three things cannot be — the URL scheme
 * an ecosystem answers to, the driver name a framework knows it by, and which
 * port numbers no web application serves on. Those are facts about other
 * projects' conventions, so they live here as data rather than as branches in
 * SidecarEngine, which stays pure logic and mentions no product at all.
 *
 * A service whose engine is absent from this file still deploys: it is kept,
 * started, and handed its host, port and credentials under its own name. What
 * an entry adds is the polish — a connection URL and the framework spellings.
 *
 * Operators can extend this without touching the release: a JSON file at
 * OVERRIDE_PATH (see SidecarEngine::dialects()) is merged over these, so an
 * in-house datastore is one config entry away.
 *
 * Entry keys:
 *   port              default, used only when the image and the compose file
 *                     say nothing; whatever the image declares wins
 *   scheme            URL scheme, when the ecosystem answers to one
 *   unambiguous_port  true when no web application serves on that port, so it
 *                     may be used to recognise the engine and to keep the port
 *                     out of the proxy's reach
 *   driver            emit the framework database block under this driver name
 *   default_database  path to use in the URL when the file names none
 *   env               extra settings a framework expects; {host} and {port}
 *                     are substituted
 *   init_password     the superuser password the image will not start
 *                     without (`passwords`, first one is generated) and the
 *                     variables that stand in for it (`waivers`)
 *   aliases           other names for this same engine
 */

return [
    'postgres' => [
        'mem_limit' => '512m',
        'port' => 5432,
        'scheme' => 'postgres',
        'unambiguous_port' => true,
        'driver' => 'pgsql',
        'init_vars' => ['POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD'],
        'init_password' => [
            'passwords' => ['POSTGRES_PASSWORD'],
            'waivers' => ['POSTGRES_PASSWORD_FILE', 'POSTGRES_HOST_AUTH_METHOD'],
        ],
        'aliases' => [
            'postgresql', 'postgis', 'pgvector', 'timescaledb',
            'pgautoupgrade', 'supabase-postgres', 'citus', 'pgsql',
        ],
    ],

    'mysql' => [
        'port' => 3306,
        'scheme' => 'mysql',
        'unambiguous_port' => true,
        'driver' => 'mysql',
        'init_vars' => ['MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'],
        'init_password' => [
            'passwords' => ['MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD'],
            'waivers' => [
                'MYSQL_ROOT_PASSWORD_FILE', 'MARIADB_ROOT_PASSWORD_FILE',
                'MYSQL_ALLOW_EMPTY_PASSWORD', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD',
                'MYSQL_RANDOM_ROOT_PASSWORD', 'MARIADB_RANDOM_ROOT_PASSWORD',
            ],
        ],
        // The image refuses remote root connections unless told otherwise, and
        // waits to be asked whether it is ready before the app connects.
        //
        // Two things about that healthcheck, both learned the hard way on
        // Kanboard, whose db sat `unhealthy` with a FailingStreak of 83:
        //
        //  - `$$` and not `$`. Compose interpolates `$VAR` itself, at parse
        //    time, against a `.env` the engine writes empty -- so the password
        //    was substituted away before the container ever saw it, leaving
        //    `mysqladmin ping -p""` and a warning on every compose command.
        //    `$$` is compose's own escape: the container's shell gets `$` and
        //    expands it from the container's environment, where it is set.
        //  - `mariadb-admin` first. `mysqladmin` no longer exists in MariaDB
        //    11+ images -- measured on mariadb:lts (12.3.3), it exits 127,
        //    "not found" -- and `mariadb-admin` does not exist in mysql's.
        //    Trying both covers every image either name appears in; measured
        //    exit 0 on mariadb:lts and on mysql:8.
        'service' => [
            'environment' => ['MYSQL_ROOT_HOST' => '%'],
            'mem_limit' => '512m',
            'pids_limit' => 1024,
            'healthcheck' => [
                'test' => [
                    'CMD-SHELL',
                    'mariadb-admin ping -uroot -p"$$MYSQL_ROOT_PASSWORD" 2>/dev/null'
                    . ' || mysqladmin ping -uroot -p"$$MYSQL_ROOT_PASSWORD"',
                ],
                'interval' => '2s',
                'timeout' => '5s',
                'retries' => 15,
            ],
        ],
        'aliases' => [
            'mariadb', 'mariadb-server', 'mysql-server',
            'percona', 'percona-server', 'percona-server-mysql',
        ],
    ],

    'redis' => [
        'mem_limit' => '192m',
        'port' => 6379,
        'scheme' => 'redis',
        'unambiguous_port' => true,
        // Clients that expect the database index treat its absence as an
        // error rather than as "the first one".
        'default_database' => '0',
        'env' => [
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'CACHE_DRIVER' => 'redis',
        ],
        'aliases' => ['valkey', 'keydb', 'dragonfly', 'redis-stack', 'redis-stack-server'],
    ],

    // HTTP gateway in front of Redis (Upstash-compatible REST). Publishes
    // container port 80 mapped to a high host port — must not win as the site.
    'serverless-redis-http' => [
        'mem_limit' => '128m',
        'port' => 80,
        'scheme' => 'http',
        'aliases' => ['redis-http', 'upstash-redis'],
        'env' => [
            'UPSTASH_REDIS_REST_URL' => 'http://{host}:{port}',
        ],
    ],

    'mongo' => [
        'mem_limit' => '384m',
        'port' => 27017,
        'scheme' => 'mongodb',
        'unambiguous_port' => true,
        'aliases' => ['mongodb', 'mongodb-community-server', 'percona-server-mongodb'],
    ],

    'memcached' => [
        'mem_limit' => '192m',
        'port' => 11211,
        'unambiguous_port' => true,
        'env' => ['CACHE_STORE' => 'memcached', 'CACHE_DRIVER' => 'memcached'],
    ],

    'elasticsearch' => [
        'mem_limit' => '384m',
        'port' => 9200,
        'scheme' => 'http',
        'unambiguous_port' => true,
        'aliases' => ['opensearch'],
    ],

    'typesense' => [
        'mem_limit' => '384m',
        'port' => 8108,
        'scheme' => 'http',
        'unambiguous_port' => true,
        // Scout stays off unless told otherwise, however complete the
        // connection details are.
        'env' => ['TYPESENSE_PROTOCOL' => 'http', 'TYPESENSE_ENABLED' => 'true'],
    ],

    'meilisearch' => [
        'mem_limit' => '384m',
        'port' => 7700,
        'scheme' => 'http',
        'unambiguous_port' => true,
        'env' => ['MEILISEARCH_HOST' => 'http://{host}:{port}'],
    ],

    'rabbitmq' => [
        'mem_limit' => '192m',
        'port' => 5672,
        'scheme' => 'amqp',
        'unambiguous_port' => true,
    ],

    // Engines we know only the port of. Enough to keep that port from being
    // mistaken for the one the site answers on.
    'cassandra' => ['port' => 9042, 'unambiguous_port' => true, 'aliases' => ['scylladb']],
    'influxdb' => ['port' => 8086],
    'clickhouse' => ['port' => 8123, 'aliases' => ['clickhouse-server']],
    'couchdb' => ['port' => 5984],
    'neo4j' => ['port' => 7687],
    'minio' => ['port' => 9000],
    'kafka' => ['port' => 9092],
    'nats' => ['port' => 4222],
    'qdrant' => ['port' => 6333],
];
