<?php

namespace Tests\Unit\Deploy\Dind;

use PHPUnit\Framework\TestCase;
use Tests\Support\HostCompileOwnImageFixtures;

/**
 * A host build runs its image by name on the host's docker, so the host gets
 * one of ours first; when it cannot, the deploy fails naming that base.
 */
class HostCompileOwnImageTest extends TestCase
{
    use HostCompileOwnImageFixtures;

    public function test_one_the_host_cannot_get_fails_the_build_naming_the_base(): void
    {
        $failure = '';
        try {
            $this->build([self::OURS], false);
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        $this->assertStringStartsWith('The shared base image '.self::OURS.' could not be built or loaded on this server', $failure);
        $this->assertStringContainsString('the stock python:3.12-slim lacks what is baked into it', $failure);
        $this->assertSame([], $this->containers);
    }
}
