<?php

namespace Tests\Support;

use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;

trait WordpressMigrationSourceIpFixtures
{
    private function policy(): SourceIpPolicy
    {
        return new SourceIpPolicy(new class extends EngineAddresses {
            public function ranges(): array
            {
                return [TestNetwork::routable(40), TestNetwork::prefix(TestNetwork::add(TestNetwork::routable(), 256), 24)];
            }
        });
    }
}
