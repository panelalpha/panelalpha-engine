<?php

namespace Tests\Support;

use App\System;
use Symfony\Component\Process\Process;

/**
 * A host for IPv4 NAT discovery: its interfaces and default route as `ip` prints them, and what a lookup sent from each of its
 * addresses is answered. Any other command fails the test, so nothing real runs; a webserver rebuild is only counted.
 */
final class NatHost extends System
{
    /** @var list<array{name: string, ip: string, kind: ?string, link: string}> */
    private array $interfaces = [];

    /** @var array<string, Process> */
    private array $answers = [];

    private string $defaultRoute = '';

    private ?Process $interfaceRead = null;

    /** @var list<list<string>> */
    public array $lookups = [];

    public int $domainRebuilds = 0;

    public function withInterface(string $name, string $ip, ?string $kind = null, string $link = 'ether'): self
    {
        $this->interfaces[] = ['name' => $name, 'ip' => $ip, 'kind' => $kind, 'link' => $link];

        return $this;
    }

    public function withDefaultRoute(string $interface): self
    {
        $this->defaultRoute = 'default via '.TestNetwork::private(1)." dev {$interface} proto static\n";

        return $this;
    }

    /** What reading the interfaces with `ip -json` ends as, instead of listing them. */
    public function readingInterfacesAs(Process $read): self
    {
        $this->interfaceRead = $read;

        return $this;
    }

    /** What the lookup service answers a request sent from $localIp. */
    public function answering(string $localIp, Process $answer): self
    {
        $this->answers[$localIp] = $answer;

        return $this;
    }

    /** @return list<string> */
    public function lookedUpFrom(): array
    {
        return array_map(static fn (array $argv): string => self::valueAfter($argv, '--interface'), $this->lookups);
    }

    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $cmd = HostCommand::argv($cmd);
        $argv = is_array($cmd) ? array_values($cmd) : explode(' ', $cmd);

        return match (true) {
            $argv === ['ip', '-4', 'addr', 'show'] => FakeProcess::ok($this->addrShowText()),
            $argv === ['ip', '-json', '-details', '-4', 'addr', 'show'] => $this->interfaceRead ?? FakeProcess::ok($this->addrShowJson()),
            $argv === ['ip', 'route', 'show', 'default'] => FakeProcess::ok($this->defaultRoute),
            ($argv[0] ?? '') === 'curl' => $this->lookup($argv),
            default => throw new \LogicException('Unexpected host command: '.implode(' ', $argv)),
        };
    }

    public function rebuildDomains(): bool
    {
        $this->domainRebuilds++;

        return true;
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        throw new \LogicException('Unexpected command: '.(is_array($cmd) ? implode(' ', $cmd) : $cmd));
    }

    /** @param  list<string>  $argv */
    private function lookup(array $argv): Process
    {
        $this->lookups[] = $argv;

        return $this->answers[self::valueAfter($argv, '--interface')]
            ?? FakeProcess::failed('curl: (45) bind failed', 45);
    }

    private function addrShowText(): string
    {
        $text = '';
        foreach ($this->interfaces as $index => $interface) {
            $text .= ($index + 1).": {$interface['name']}: <BROADCAST,UP>\n";
            $text .= "    inet {$interface['ip']}/24 scope global {$interface['name']}\n";
        }

        return $text;
    }

    private function addrShowJson(): string
    {
        $entries = [];
        foreach ($this->interfaces as $index => $interface) {
            $entries[] = array_filter([
                'ifindex' => $index + 1,
                'ifname' => $interface['name'],
                'link_type' => $interface['link'],
                'linkinfo' => $interface['kind'] === null ? null : ['info_kind' => $interface['kind']],
                'addr_info' => [['family' => 'inet', 'local' => $interface['ip'], 'prefixlen' => 24, 'scope' => 'global']],
            ], static fn (mixed $value): bool => $value !== null);
        }

        return (string) json_encode($entries);
    }

    /** @param  list<string>  $argv */
    private static function valueAfter(array $argv, string $option): string
    {
        $at = array_search($option, $argv, true);

        return $at === false ? '' : (string) ($argv[$at + 1] ?? '');
    }
}
