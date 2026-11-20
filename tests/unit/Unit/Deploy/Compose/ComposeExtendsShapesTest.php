<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeExtends;
use PHPUnit\Framework\TestCase;

/** A file without a service map, and an `extends` that names nothing. */
class ComposeExtendsShapesTest extends TestCase
{
    public function test_a_file_without_services_is_returned_as_is(): void
    {
        $compose = ['volumes' => ['data' => null]];

        $this->assertSame($compose, ComposeExtends::resolve($compose, static fn (): ?string => null));
    }

    public function test_an_extends_that_is_neither_a_name_nor_a_map_is_dropped(): void
    {
        $resolved = ComposeExtends::resolve(
            ['services' => ['app' => ['image' => 'acme/app', 'extends' => 42], 'base' => ['image' => 'acme/base', 'environment' => ['A' => '1']]]],
            static fn (): ?string => null
        );

        $this->assertSame(['image' => 'acme/app'], $resolved['services']['app']);
    }
}
