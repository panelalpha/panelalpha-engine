<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeInterpolation;
use PHPUnit\Framework\TestCase;

/**
 * Every value a compose string can take: each variable with every value the
 * project gives it, and unset.
 */
class ComposeInterpolationTest extends TestCase
{
    public function test_an_unset_variable_takes_its_default_or_nothing(): void
    {
        $this->assertSame(['/var/run'], ComposeInterpolation::candidates('${NOPE:-/var/run}', []));
        $this->assertSame(['/var/run'], ComposeInterpolation::candidates('${NOPE-/var/run}', []));
        $this->assertSame([''], ComposeInterpolation::candidates('${NOPE}', []));
        $this->assertSame(['/data'], ComposeInterpolation::candidates('$NOPE/data', []));
    }

    public function test_a_variable_takes_every_state_it_may_be_in(): void
    {
        $env = ['X' => ['/var/run', './a', null], 'SET' => './s'];

        $this->assertSame(['/var/run', './a', ''], ComposeInterpolation::candidates('${X}', $env));
        $this->assertSame(['/var/run/x', './a/x', './d/x'], ComposeInterpolation::candidates('${X:-./d}/x', $env));
        // Always set: its default is never used.
        $this->assertSame(['./s'], ComposeInterpolation::candidates('${SET:-/etc}', $env));
    }

    public function test_the_empty_value_follows_the_colon_rules(): void
    {
        $env = ['E' => ['', null]];

        $this->assertSame(['d'], ComposeInterpolation::candidates('${E:-d}', $env));
        $this->assertSame(['', 'd'], ComposeInterpolation::candidates('${E-d}', $env));
        $this->assertSame([''], ComposeInterpolation::candidates('${E:+alt}', $env));
        $this->assertSame(['alt', ''], ComposeInterpolation::candidates('${E+alt}', $env));
        // `:?` stops Compose when unset or empty: nothing runs with it.
        $this->assertSame([], ComposeInterpolation::candidates('${E:?required}', $env));
        $this->assertSame(['/p'], ComposeInterpolation::candidates('${P:?required}', ['P' => '/p']));
    }

    public function test_a_default_can_itself_interpolate(): void
    {
        $this->assertSame(['/etc'], ComposeInterpolation::candidates('${A:-${B:-/etc}}', []));
        $this->assertSame(['/x/y', '/y'], ComposeInterpolation::candidates('${A:-${B}/y}', ['B' => ['/x', null]]));
    }

    public function test_a_doubled_dollar_is_a_literal(): void
    {
        $this->assertSame(['./a$b'], ComposeInterpolation::candidates('./a$$b', []));
    }

    public function test_what_cannot_be_told_is_null(): void
    {
        // HOME and PATH come from the account's own environment, which outranks .env.
        $this->assertNull(ComposeInterpolation::candidates('${HOME:-./x}', []));
        $this->assertNull(ComposeInterpolation::candidates('$HOME/x', []));
        $this->assertNull(ComposeInterpolation::candidates('${X', []));
        $this->assertNull(ComposeInterpolation::candidates('${X:=d}', []));
        $this->assertNull(ComposeInterpolation::candidates('$', []));
        // A value in .env that interpolates again is not followed.
        $this->assertNull(ComposeInterpolation::candidates('${X}', ['X' => '${Y:-/var/run}']));
    }

    public function test_the_environment_is_every_value_the_deploy_can_write(): void
    {
        // A key in .env stays set; one the account adds may be unset where .env is tracked.
        $this->assertSame(
            ['A' => ['./env', './mine'], 'B' => ['b'], 'D' => [null, './d']],
            ComposeInterpolation::environment("A=./env\nB=b\n", "A=/example\n", ['A' => './mine', 'C' => '', 'D' => './d'])
        );
        // No .env yet: the deploy copies .env.example, less the keys compose defaults.
        $this->assertSame(['A' => ['/example', null], 'E' => ['', null]], ComposeInterpolation::environment(null, "A=/example\nE=\n", []));
    }
}
