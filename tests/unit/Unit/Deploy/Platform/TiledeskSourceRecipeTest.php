<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** tiledesk-server connects before loading its modules (11-13 s cold), longer than the driver's default 10 s connect timeout. */
class TiledeskSourceRecipeTest extends TestCase
{
    public function test_the_server_waits_longer_for_mongodb_than_its_cold_start_takes(): void
    {
        $compose = (string) AppConfigDirectory::read(new LocalAppConfigSource, $this->directory(), true)?->compose();
        $environment = Yaml::parse($compose)['services']['server']['environment'];

        $uri = $this->value($environment, 'MONGODB_URI');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('mongodb://mongo/tiledesk?', $uri);
        $this->assertGreaterThanOrEqual(60000, (int) ($query['connectTimeoutMS'] ?? 0));
    }

    /** @param  list<string>  $environment */
    private function value(array $environment, string $name): string
    {
        foreach ($environment as $entry) {
            if (str_starts_with($entry, $name.'=')) {
                return substr($entry, strlen($name) + 1);
            }
        }
        $this->fail("{$name} is not set");
    }

    private function directory(): string
    {
        return SourceRecipes::defaultDirectory().'/github.com/tiledesk/tiledesk';
    }
}
