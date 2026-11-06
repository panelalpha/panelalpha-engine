<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** A proxy rule to the project's own container, the one way to route a port the engine did not. */
abstract class ProxyRuleHint extends Hint
{
    abstract protected function transport(): string;

    /**
     * @return array<string, scalar|null> the rule's fields that differ between the routes
     */
    abstract protected function choose(): array;

    public function rest(): ?string
    {
        return 'POST /proxy-rules';
    }

    public function params(): array
    {
        return [
            'username' => '{username}',
            'transport' => $this->transport(),
            ...$this->choose(),
            'upstream_host' => '{username}',
        ];
    }
}
