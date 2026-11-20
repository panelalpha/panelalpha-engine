<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;

/** A distinctive placeholder inside a value that is no URL, and an environment that is not a map. */
class ComposePlaceholdersShapesTest extends TestCase
{
    private const SEED = 'test-seed-not-a-real-app-key';

    public function test_a_distinctive_placeholder_inside_a_connection_string_gets_the_same_secret(): void
    {
        $env = ComposePlaceholders::fill(['services' => ['app' => ['environment' => [
            'DB_PASSWORD' => 'CHANGE_ME_DB_PASSWORD',
            'DB_CONNECTION' => 'Server=db;User=app;Password=CHANGE_ME_DB_PASSWORD;',
        ]]]], self::SEED)['compose']['services']['app']['environment'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $env['DB_PASSWORD']);
        $this->assertSame('Server=db;User=app;Password=' . $env['DB_PASSWORD'] . ';', $env['DB_CONNECTION']);
    }

    public function test_an_environment_that_is_not_a_map_learns_no_public_address(): void
    {
        $tagged = new TaggedValue('override', ['NODE_ENV' => 'production']);

        $services = ComposePlaceholders::fill(
            ['services' => ['app' => ['image' => 'acme/app', 'environment' => $tagged], 'web' => ['image' => 'acme/web', 'environment' => []]]],
            self::SEED,
            'https://app.example.test'
        )['compose']['services'];

        $this->assertSame($tagged, $services['app']['environment']);
        $this->assertNotSame([], $services['web']['environment']);
    }
}
