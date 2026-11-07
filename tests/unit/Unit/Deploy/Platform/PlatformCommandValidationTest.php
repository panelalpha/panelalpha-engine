<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each refusal `PlatformCommand::fromArray()` makes, and the order they are checked in when one entry is wrong twice.
 */
class PlatformCommandValidationTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'run missing' => [['stage' => 'build'], "m.commands[2]: 'run' must be a non-empty string"],
            'run before stage' => [['run' => ' ', 'stage' => 'nope'], "m.commands[2]: 'run' must be a non-empty string"],
            'stage before id' => [['run' => 'x', 'stage' => 'nope', 'id' => ''], "m.commands[2]: unknown stage 'nope'; expected one of precheck, prepare, build, install, upgrade, start"],
            'id blank' => [['run' => 'x', 'stage' => 'build', 'id' => ' '], "m.commands[2]: 'id' must be a non-empty string when present"],
            'id before serve' => [['run' => 'x', 'stage' => 'build', 'id' => 5, 'serve' => true], "m.commands[2]: 'id' must be a non-empty string when present"],
            'serve outside start' => [['run' => 'x', 'stage' => ['start', 'install'], 'serve' => true, 'when' => 'x'], "m.commands[2]: a 'serve' command must belong to the start stage and no other"],
            'when not an object' => [['run' => 'x', 'stage' => 'build', 'when' => 'yes', 'timeout' => 0], "m.commands[2]: 'when' must be an object"],
            'timeout zero' => [['run' => 'x', 'stage' => 'build', 'timeout' => 0, 'workdir' => ''], "m.commands[2]: 'timeout' must be a positive integer of seconds"],
            'timeout a string' => [['run' => 'x', 'stage' => 'build', 'timeout' => '30'], "m.commands[2]: 'timeout' must be a positive integer of seconds"],
            'workdir blank' => [['run' => 'x', 'stage' => 'build', 'workdir' => ' ', 'description' => 1], "m.commands[2]: 'workdir' must be a non-empty string when present"],
            'description not a string' => [['run' => 'x', 'stage' => 'build', 'description' => 1, 'role' => 'nope'], "m.commands[2]: 'description' must be a string when present"],
            'role unknown' => [['run' => 'x', 'stage' => 'build', 'role' => 'nope'], "m.commands[2]: 'role' must be one of dependencies, assets"],
            'role outside build' => [['run' => 'x', 'stage' => 'start', 'role' => 'assets'], "m.commands[2]: 'role' only applies to build-stage commands"],
        ];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('refusals')]
    public function test_a_malformed_command_is_refused_with_its_message(array $raw, string $message): void
    {
        try {
            PlatformCommand::fromArray($raw, 'm', 2);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_every_field_a_command_declares_is_kept(): void
    {
        $command = PlatformCommand::fromArray([
            'id' => ' web ',
            'run' => '  ./serve --port 80  ',
            'stage' => 'start',
            'optional' => 1,
            'serve' => true,
            'when' => ['file' => 'x'],
            'description' => 'The server.',
            'timeout' => 30,
            'workdir' => ' app ',
            'before' => 'yes',
        ], 'm', 0);

        $this->assertSame(
            ['web', ['start'], './serve --port 80', true, true, ['file' => 'x'], 'The server.', 30, 'app', PlatformCommand::ROLE_NONE, true],
            [$command->id, $command->stages, $command->run, $command->optional, $command->serve, $command->when,
                $command->description, $command->timeout, $command->workdir, $command->role, $command->before]
        );
    }

    public function test_an_undeclared_command_gets_defaults_and_a_derived_id(): void
    {
        $command = PlatformCommand::fromArray(['run' => 'npm run build -- --prod', 'stages' => ['build', 'build']], 'm', 4);

        $this->assertSame(
            ['npm-run-build', ['build'], false, false, null, null, null, null, PlatformCommand::ROLE_ASSETS, false],
            [$command->id, $command->stages, $command->optional, $command->serve, $command->when,
                $command->description, $command->timeout, $command->workdir, $command->role, $command->before]
        );
    }
}
