<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged\Arguments;
use App\System\Privileged\InvalidArgument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The broker's Python tests answer the same arguments-cases.json. */
final class ArgumentsBrokerCasesTest extends TestCase
{
    private const CASES = '/dockerfiles/root-broker/tests/arguments-cases.json';

    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 5).self::CASES), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            yield $index.' '.$case['kind'].' '.json_encode($case['value']) => [$case['kind'], $case['value'], $case['accepted']];
        }
    }

    #[DataProvider('cases')]
    public function test_the_gateway_gives_the_verdict_the_broker_gives(string $kind, string $value, bool $accepted): void
    {
        if (! $accepted) {
            $this->expectException(InvalidArgument::class);
        }

        $this->assertSame($value, Arguments::$kind($value));
    }
}
