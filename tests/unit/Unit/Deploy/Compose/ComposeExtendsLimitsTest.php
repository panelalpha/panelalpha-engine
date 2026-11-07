<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeExtends;
use PHPUnit\Framework\TestCase;

class ComposeExtendsLimitsTest extends TestCase
{
    public function test_a_chain_deeper_than_five_levels_is_refused(): void
    {
        $compose = ['services' => []];
        foreach (range('a', 'g') as $name) {
            $compose['services'][$name] = ['image' => 'x', 'extends' => chr(ord($name) + 1)];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compose file nests extends: deeper than 5 levels.');

        ComposeExtends::resolve($compose, static fn (string $path): ?string => null);
    }

    public function test_an_extends_naming_no_service_is_only_removed(): void
    {
        $compose = ['services' => ['a' => ['image' => 'x', 'extends' => ['file' => 'other.yml']]]];

        $this->assertSame(
            ['services' => ['a' => ['image' => 'x']]],
            ComposeExtends::resolve($compose, static fn (string $path): ?string => null)
        );
    }

    public function test_a_file_that_extends_itself_is_a_cycle(): void
    {
        $compose = ['services' => ['a' => ['extends' => ['file' => 'x.yml', 'service' => 'b']]]];
        $read = static fn (string $path): ?string => "services:\n  b:\n    extends:\n      file: x.yml\n      service: b\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compose file extends x.yml in a cycle.');

        ComposeExtends::resolve($compose, $read);
    }
}
