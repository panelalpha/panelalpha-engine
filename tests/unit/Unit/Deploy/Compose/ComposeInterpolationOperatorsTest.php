<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeInterpolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ComposeInterpolationOperatorsTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>|null}>
     */
    public static function values(): array
    {
        return [
            'alternative when set' => ['${SET+alt}', ['alt']],
            'alternative when unset' => ['${UNSET+alt}', ['']],
            'alternative when empty' => ['${EMPTY+alt}', ['alt']],
            'colon alternative when empty' => ['${EMPTY:+alt}', ['']],
            'colon error when set' => ['${SET:?e}', ['v']],
            'colon error when empty' => ['${EMPTY:?e}', []],
            'error when unset' => ['${UNSET?e}', []],
            'error when empty' => ['${EMPTY?e}', ['']],
            'error when maybe unset' => ['${MAYBE?e}', ['x']],
            'nested alternative' => ['${MAYBE+a${SET}}', ['av', '']],
            'colon alternative over states' => ['${BOTH:+w}', ['', 'w']],
            'default over states' => ['${BOTH-d}', ['', 'y']],
            'braced and bare in one value' => ['a${SET}b$SET-c', ['avbv-c']],
            'an unknown operator' => ['${SET:x}', null],
        ];
    }

    /**
     * @param  list<string>|null  $expected
     */
    #[DataProvider('values')]
    public function test_each_operator_answers_per_state(string $value, ?array $expected): void
    {
        $env = ['SET' => 'v', 'EMPTY' => '', 'MAYBE' => ['x', null], 'BOTH' => ['', 'y']];

        $this->assertSame($expected, ComposeInterpolation::candidates($value, $env));
    }
}
