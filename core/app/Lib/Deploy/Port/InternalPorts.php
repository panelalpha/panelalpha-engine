<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * Ports that belong to a datastore and must never be proxied as the app's own. Overlapping ranges
 * (7000/7001 Cassandra, 8086 InfluxDB) go to the image check instead.
 */
final class InternalPorts
{
    /** @var array<int, string> port => what listens there */
    private const KNOWN = [
        3306 => 'MySQL',
        3307 => 'MySQL alternate',
        5432 => 'PostgreSQL',
        5433 => 'PostgreSQL alternate',
        6379 => 'Redis',
        6380 => 'Redis alternate',
        11211 => 'Memcached',
        27017 => 'MongoDB',
        27018 => 'MongoDB alternate',
        27019 => 'MongoDB alternate',
        5672 => 'RabbitMQ',
        5673 => 'RabbitMQ alternate',
        15672 => 'RabbitMQ management',
        9200 => 'Elasticsearch',
        9300 => 'Elasticsearch cluster',
        9042 => 'Cassandra CQL',
        50070 => 'Hadoop HDFS NameNode (legacy)',
        8020 => 'Hadoop HDFS IPC (legacy)',
        26379 => 'Redis Sentinel',
        7199 => 'Cassandra JMX',
    ];

    /**
     * Ports that can never be a site's front door: SSH, mail, DNS, epmd (bound before the app's own), BitTorrent.
     * 9000 is left out: php-fpm's FastCGI, but also MinIO's, Portainer's and SonarQube's real HTTP port.
     *
     * @var array<int, string>
     */
    private const NON_WEB = [
        22 => 'SSH',
        25 => 'SMTP',
        465 => 'SMTPS',
        587 => 'SMTP submission',
        143 => 'IMAP',
        993 => 'IMAPS',
        110 => 'POP3',
        995 => 'POP3S',
        53 => 'DNS',
        1025 => 'SMTP (unprivileged)',
        2525 => 'SMTP (unprivileged)',
        1110 => 'POP3 (unprivileged)',
        1143 => 'IMAP (unprivileged)',
        4369 => 'Erlang port mapper (epmd)',
        2222 => 'SSH (unprivileged)',
        6881 => 'BitTorrent',
    ];

    public static function isKnown(int $port): bool
    {
        return isset(self::KNOWN[$port]);
    }

    /**
     * What listens on a datastore port, for a report that must say why a port was turned down; a datastore
     * port is filtered out of detection, while a non-web one is only unfit as a front door.
     */
    public static function datastoreOn(int $port): ?string
    {
        return self::KNOWN[$port] ?? null;
    }

    public static function nonWebOn(int $port): ?string
    {
        return self::NON_WEB[$port] ?? null;
    }

    /**
     * Whether a site could plausibly be served here: not a datastore and not
     * a non-HTTP service.
     */
    public static function isWebCandidate(int $port): bool
    {
        return $port > 0 && $port <= 65535 && ! isset(self::KNOWN[$port]) && ! isset(self::NON_WEB[$port]);
    }

    /**
     * Whether a binding on this service is filtered out: its host or container port is known, or the image
     * is a datastore whose port was remapped.
     *
     * @param  array<string, mixed>  $service
     */
    public static function coversBinding(PortMapping $mapping, array $service): bool
    {
        return self::isKnown($mapping->hostPort)
            || ($mapping->containerPort !== null && self::isKnown($mapping->containerPort))
            || self::isDatastore($service);
    }

    /**
     * Whether SidecarEngine::isKnownDatastore() recognises the service; only a recognised datastore counts,
     * since filtering the app's own port would leave the site unreachable.
     *
     * @param  array<string, mixed>  $service
     */
    public static function isDatastore(array $service): bool
    {
        if ($service === [] || ! is_string($service['image'] ?? null)) {
            return false;
        }

        return SidecarEngine::isKnownDatastore('', $service);
    }
}
