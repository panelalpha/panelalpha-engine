<?php

namespace App\Lib\Net\Outbound;

use App\System;
use App\System\Privileged;

/**
 * The outbound limits through the root broker: its report, and lifting an owner's scan block.
 */
class OutboundLimits
{
    private const HOST_SECONDS = 60;

    /** @var callable(list<string>): string */
    private $runOnHost;

    /** @param (callable(list<string>): string)|null $runOnHost runs a host argv, returns stdout */
    public function __construct(?callable $runOnHost = null)
    {
        $this->runOnHost = $runOnHost ?? static fn (array $argv): string => (new System)->exec($argv, [], self::HOST_SECONDS);
    }

    public function report(): OutboundReport
    {
        return OutboundReport::fromJson(($this->runOnHost)(Privileged::host()->egressReport()));
    }

    /** @return list<string> the addresses that lost their block, none when the owner had none */
    public function unblock(string $owner): array
    {
        $decoded = json_decode(trim(($this->runOnHost)(Privileged::host()->egressUnblock($owner))), true);
        $addresses = is_array($decoded) && is_array($decoded['addresses'] ?? null) ? $decoded['addresses'] : [];

        return array_values(array_filter($addresses, 'is_string'));
    }
}
