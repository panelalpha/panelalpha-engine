<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Sidecar\SidecarCredentials;
use PHPUnit\Framework\TestCase;

/**
 * A kept sidecar's bare secret in a list-form `command:` gets a generated value, and the parts that
 * are not strings stay as they are.
 */
class SidecarCredentialsBareCommandTest extends TestCase
{
    public function test_a_bare_secret_in_a_command_list_is_filled(): void
    {
        [$service, $generated] = SidecarCredentials::withBareSecretsFilled(
            ['image' => 'redis:7', 'command' => ['redis-server', '--requirepass', '${REDIS_PASSWORD}', 6379]],
            'seed-1',
            []
        );

        $value = ComposePlaceholders::requiredSecretValue('REDIS_PASSWORD', 'seed-1');
        $this->assertSame(['REDIS_PASSWORD' => $value], $generated);
        $this->assertSame(['redis-server', '--requirepass', $value, 6379], $service['command']);
    }

    public function test_a_secret_compose_already_has_a_value_for_is_left_to_compose(): void
    {
        $command = ['redis-server', '--requirepass', '${REDIS_PASSWORD}'];

        [$service, $generated] = SidecarCredentials::withBareSecretsFilled(
            ['image' => 'redis:7', 'command' => $command],
            'seed-1',
            ['REDIS_PASSWORD' => ['from-the-env-file']]
        );

        $this->assertSame([], $generated);
        $this->assertSame($command, $service['command']);
    }

    public function test_a_command_that_is_neither_string_nor_list_is_untouched(): void
    {
        [$service, $generated] = SidecarCredentials::withBareSecretsFilled(
            ['image' => 'redis:7', 'command' => 42],
            'seed-1',
            []
        );

        $this->assertSame(['image' => 'redis:7', 'command' => 42], $service);
        $this->assertSame([], $generated);
    }
}
