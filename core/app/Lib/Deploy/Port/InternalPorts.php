<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * Ports that belong to a datastore and must never be proxied as the
 * application's own. Overlapping ranges (7000/7001 Cassandra, 8086 InfluxDB)
 * go to the image check instead.
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
     * Ports that are neither a datastore nor a web server, and so can never be
     * the front door of a site: Gitea's `EXPOSE 22 3000` publishes SSH as the
     * website, and the probe answers `Received HTTP/0.9 when not allowed`.
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
        // Unprivileged mail ports a dev or test mail server binds. Mailpit's
        // `EXPOSE 1025/tcp 1110/tcp 8025/tcp` (SMTP, POP3, web UI) otherwise
        // makes its mail listener the first non-datastore port.
        1025 => 'SMTP (unprivileged)',
        2525 => 'SMTP (unprivileged)',
        1110 => 'POP3 (unprivileged)',
        1143 => 'IMAP (unprivileged)',
        // Every Erlang/Elixir release starts epmd, and epmd binds 4369 the
        // instant the VM comes up -- before the app has migrated, and so
        // before it has bound its own port. TeslaMate's deploy was realigned
        // onto it and answered `Empty reply from server` permanently, because
        // epmd speaks the Erlang port-mapper protocol and nothing else. It is
        // never a front door under any configuration.
        4369 => 'Erlang port mapper (epmd)',
        // The conventional unprivileged SSH port, and the same mistake as 22
        // above one digit further out: an image that publishes it alongside
        // its web port offers the proxy a shell instead of a site.
        2222 => 'SSH (unprivileged)',
    ];

    // Deliberately absent: 9000. It is php-fpm's FastCGI socket, which is how
    // a PHP stack's front door gets mistaken for the app -- but it is equally
    // MinIO's, Portainer's and SonarQube's *real* HTTP port. Listing it would
    // cost more sites than it saves, which is the same trade the 7000/8086
    // overlap is left out for.

    public static function isKnown(int $port): bool
    {
        return isset(self::KNOWN[$port]);
    }

    /**
     * Whether a site could plausibly be served here: not a datastore and not
     * a non-HTTP service.
     */
    public static function isWebCandidate(int $port): bool
    {
        return $port > 0 && $port <= 65535 && !isset(self::KNOWN[$port]) && !isset(self::NON_WEB[$port]);
    }

    /**
     * Whether a binding on this service should be filtered out: the host or
     * container port is known, or the image is a datastore whose port was
     * remapped.
     *
     * @param array<string, mixed> $service
     */
    public static function coversBinding(PortMapping $mapping, array $service): bool
    {
        return self::isKnown($mapping->hostPort)
            || ($mapping->containerPort !== null && self::isKnown($mapping->containerPort))
            || self::isDatastore($service);
    }

    /**
     * Delegates to SidecarEngine::isKnownDatastore(), the single place that
     * knows what a datastore looks like. Only a recognised datastore counts:
     * filtering the application's own port would leave the site unreachable.
     *
     * @param array<string, mixed> $service
     */
    public static function isDatastore(array $service): bool
    {
        if ($service === [] || !is_string($service['image'] ?? null)) {
            return false;
        }

        return SidecarEngine::isKnownDatastore('', $service);
    }
}
