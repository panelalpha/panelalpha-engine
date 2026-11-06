<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Port\InternalPorts;
use App\Lib\Findings\Finding;

/**
 * Ports and the source of each: the primary is the one the engine proxies, the rest shown so a disagreement is
 * visible. `routed`, `unrouted` and `refused` say which need a proxy rule and which must never get one.
 *
 * @psalm-type PortNote = array{port: int, reason: string, service?: string, routable: bool}
 */
final class PortsReport
{
    public const SECONDARY = 'secondary';

    /** The finding code a secondary port's hints are filed under. */
    public const SECONDARY_PORT = 'secondary_port';

    public const PORT_NOT_DETECTED = 'port_not_detected';

    /** Where a deploy routes the domain when nothing names a port. */
    public const GUESSED_PORT = 8080;

    private readonly string $projectDir;

    /**
     * @param  array<string, mixed>  $decision
     */
    public function __construct(string $projectDir, private readonly array $decision)
    {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array{primary: ?int, source: ?string, routed: list<int>, unrouted: list<PortNote>, refused: list<PortNote>, compose: list<int>, dockerfile_expose: ?int, issue: ?array<string, mixed>}
     */
    public static function of(string $projectDir, array $decision): array
    {
        return (new self($projectDir, $decision))->build();
    }

    /**
     * @return array{primary: ?int, source: ?string, routed: list<int>, unrouted: list<PortNote>, refused: list<PortNote>, compose: list<int>, dockerfile_expose: ?int, issue: ?array<string, mixed>}
     */
    public function build(): array
    {
        $compose = $this->composePorts();
        $expose = $this->exposedPort();
        [$primary, $source] = $this->primary($compose, $expose);
        $all = array_values(array_map('intval', $compose['all'] ?? []));

        return [
            'primary' => $primary,
            'source' => $source,
            'routed' => $primary === null ? [] : [$primary],
            'unrouted' => $this->unrouted($all, $primary),
            'refused' => $this->refused($compose),
            'compose' => $all,
            'dockerfile_expose' => $expose,
            'issue' => $primary === null ? self::portNotDetected()->toArray() : null,
        ];
    }

    private static function portNotDetected(): Finding
    {
        return new Finding(self::PORT_NOT_DETECTED, 'No port the application listens on was found: no recipe names one, '
            .'no compose service publishes one and no Dockerfile exposes one, so a deploy routes the site to '
            .self::GUESSED_PORT.'. Name the port with `port:` in a panelalpha.yaml, or route the domain to the port '
            .'the application listens on.', ['guessed_port' => self::GUESSED_PORT]);
    }

    /**
     * Ports the compose file publishes that the engine leaves alone, and what
     * it would take to reach each one.
     *
     * @param  list<int>  $all
     * @return list<PortNote>
     */
    private function unrouted(array $all, ?int $primary): array
    {
        $notes = [];
        foreach ($all as $port) {
            if ($port === $primary) {
                continue;
            }
            $nonWeb = InternalPorts::nonWebOn($port);
            $notes[] = $nonWeb !== null
                ? [
                    'port' => $port,
                    'reason' => 'non_web',
                    'service' => $nonWeb,
                    'routable' => false,
                ]
                : [
                    'port' => $port,
                    'reason' => self::SECONDARY,
                    'routable' => true,
                ];
        }

        return $notes;
    }

    /**
     * Ports detection refused outright. Never routable: the primary rule for
     * one of these would point the site at a database.
     *
     * @param  array<string, mixed>  $compose
     * @return list<PortNote>
     */
    private function refused(array $compose): array
    {
        $refused = is_array($compose['refused'] ?? null) ? $compose['refused'] : [];
        $notes = [];
        foreach ($refused as $entry) {
            if (! is_array($entry) || ! isset($entry['port'])) {
                continue;
            }
            $notes[] = [
                'port' => (int) $entry['port'],
                'reason' => (string) ($entry['reason'] ?? 'datastore'),
                'service' => (string) ($entry['service'] ?? ''),
                'routable' => false,
            ];
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return array{0: ?int, 1: ?string}
     */
    private function primary(array $compose, ?int $expose): array
    {
        $platform = (int) ($this->decision['port_hint'] ?? 0);
        $composePrimary = (int) ($compose['primary'] ?? 0);

        return match (true) {
            $platform > 0 => [$platform, 'platform'],
            $composePrimary > 0 => [$composePrimary, 'compose'],
            $expose !== null => [$expose, 'dockerfile'],
            default => [null, null],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function composePorts(): array
    {
        $path = $this->composePath();

        return $path === null ? ['all' => [], 'refused' => []] : DetectAppPort::detectAllPorts($path);
    }

    private function composePath(): ?string
    {
        $declared = $this->decision['compose_path'] ?? null;
        if (is_string($declared) && is_file($declared)) {
            return $declared;
        }

        return ComposeFileInspector::firstIn($this->projectDir);
    }

    /**
     * The EXPOSE of a Dockerfile the engine would actually build, found through the same finder: a decision
     * naming none may mean detection refused one, and a file the engine will not build publishes nothing.
     */
    private function exposedPort(): ?int
    {
        $dockerfile = $this->decision['dockerfile'] ?? null;
        if (! is_string($dockerfile) || $dockerfile === '') {
            $dockerfile = DockerfileFinder::find($this->projectDir, []);
        }

        return $dockerfile === null
            ? null
            : DockerfileFinder::exposedPort($this->projectDir.'/'.$dockerfile);
    }
}
