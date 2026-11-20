<?php

namespace Tests\Support;

use App\Lib\Deploy\Platform\PlatformManifest;

trait HostScriptFixtures
{
    /** @param list<array<string, mixed>> $commands */
    private function manifest(array $commands): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'detect' => ['file' => 'demo.json'],
            'commands' => $commands,
        ]);
    }
}
