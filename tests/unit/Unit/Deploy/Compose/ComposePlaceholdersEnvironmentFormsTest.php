<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use PHPUnit\Framework\TestCase;

class ComposePlaceholdersEnvironmentFormsTest extends TestCase
{
    public function test_entries_without_a_value_to_replace_pass_through_untouched(): void
    {
        $compose = ['services' => [
            'app' => ['image' => 'x', 'environment' => ['NULLED' => null, 'FLAG' => true, 'LIST' => ['a'], 'APP_SECRET' => 'changeme', 'N' => 5]],
            'svc' => ['image' => 'y', 'environment' => 'SOME=string'],
            'lst' => ['image' => 'z', 'environment' => ['INHERIT', 7, 'DB_PASSWORD=STRONG_DB_PASSWORD', ' SPACED = x']],
        ]];

        $this->assertSame([
            'compose' => ['services' => [
                'app' => ['image' => 'x', 'environment' => [
                    'NULLED' => null,
                    'FLAG' => true,
                    'LIST' => ['a'],
                    'APP_SECRET' => '5001faadf40c1001d466d427da6a693cc9c28917263a84e0',
                    'N' => '5',
                ]],
                'svc' => ['image' => 'y', 'environment' => 'SOME=string'],
                'lst' => ['image' => 'z', 'environment' => [
                    'INHERIT',
                    7,
                    'DB_PASSWORD=3d5a3b7389134f0c616ae13d6aaf45eb5752154eac63cd71',
                    ' SPACED = x',
                ]],
            ]],
            'secrets' => ['APP_SECRET', 'DB_PASSWORD'],
            'urls' => [],
            'published' => [],
        ], ComposePlaceholders::fill($compose, 'seed', null, []));
    }
}
