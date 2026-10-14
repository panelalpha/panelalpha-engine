<?php

namespace Tests\Support;

use App\Lib\Project\ApiAccessFirewall;

/**
 * An allow-list writer that records what it was asked to publish, so a test can
 * assert the firewall's list without a host.
 */
class RecordingFirewall extends ApiAccessFirewall
{
    /** @var list<list<string>> */
    public array $applied = [];

    /** @var list<list<string>> the accounts each apply was told had just changed */
    public array $touched = [];

    /**
     * @param list<string> $projects
     * @param list<string> $touched
     */
    public function apply(array $projects, array $touched = []): void
    {
        $this->applied[] = array_values($projects);
        $this->touched[] = array_values($touched);
    }

    /** The list published last, or null when the firewall was never asked. */
    public function last(): ?array
    {
        return $this->applied === [] ? null : $this->applied[array_key_last($this->applied)];
    }
}
