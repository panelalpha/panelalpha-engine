<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\WritableProjectBinds;
use PHPUnit\Framework\TestCase;

class WritableProjectBindsTest extends TestCase
{
    private const PROJECT = '/home/acct/project';

    /** stringer: the setup one-shot appends its secrets to the bind-mounted .env. */
    public function test_read_write_checkout_binds_are_found_per_service(): void
    {
        $compose = ['services' => [
            'stringer-setup' => ['image' => 'stringerrss/stringer:latest', 'volumes' => ['./.env:/app/.env']],
            'web' => ['image' => 'nginx', 'user' => '${PUID:-101}', 'volumes' => [
                './conf:/etc/nginx/conf.d:ro',
                ['type' => 'bind', 'source' => './themes', 'target' => '/themes'],
                ['type' => 'bind', 'source' => './static', 'target' => '/static', 'read_only' => true],
                'data:/var/lib/data',
                '/srv/stringer/data:/var/lib/postgresql',
                './:/app',
                '../outside:/x',
                '${UNSET}:/y',
            ]],
            'db' => ['image' => 'postgres', 'volumes' => ['pg:/var/lib/postgresql/data']],
        ]];

        $this->assertSame([
            'stringer-setup' => ['image' => 'stringerrss/stringer:latest', 'user' => null, 'sources' => [self::PROJECT . '/.env']],
            'web' => ['image' => 'nginx', 'user' => '101', 'sources' => [self::PROJECT . '/themes']],
        ], WritableProjectBinds::of($compose, self::PROJECT . '/'));
    }

    public function test_uid_of_a_user_spec(): void
    {
        $passwd = "root:x:0:0:root:/root:/bin/sh\nstringer:x:1000:1000::/home/stringer:/bin/sh\nadmin:x:0:0::/:/bin/sh\n";

        $this->assertSame(1000, WritableProjectBinds::uidOf('stringer', $passwd));
        $this->assertSame(1000, WritableProjectBinds::uidOf('stringer:stringer', $passwd));
        $this->assertSame(70, WritableProjectBinds::uidOf('70:70'));
        $this->assertNull(WritableProjectBinds::uidOf('root', $passwd));
        $this->assertNull(WritableProjectBinds::uidOf('0'));
        $this->assertNull(WritableProjectBinds::uidOf('admin', $passwd));
        $this->assertNull(WritableProjectBinds::uidOf('nobody-here', $passwd));
        $this->assertNull(WritableProjectBinds::uidOf('stringer'));
    }

    public function test_dockerfile_user_is_the_final_stages_last_user(): void
    {
        $this->assertSame(
            ['user' => '33:33', 'base' => null],
            WritableProjectBinds::dockerfileUser("FROM wordpress:cli\nUSER root\nRUN apk add make\nUSER 33:33\nCMD [\"wp\"]\n")
        );
        // A build stage's USER is not the runtime's.
        $this->assertSame(
            ['user' => null, 'base' => 'nginx:1.27'],
            WritableProjectBinds::dockerfileUser("FROM node:22 AS build\nUSER node\nRUN npm ci\nFROM nginx:1.27\nCOPY --from=build /app /usr/share/nginx/html\n")
        );
    }

    public function test_dockerfile_user_follows_a_stage_alias(): void
    {
        $this->assertSame(
            ['user' => 'app', 'base' => null],
            WritableProjectBinds::dockerfileUser("FROM python:3.12 AS base\nUSER app\nFROM base AS runtime\nCOPY . .\n")
        );
        $this->assertSame(
            ['user' => null, 'base' => 'python:3.12'],
            WritableProjectBinds::dockerfileUser("FROM --platform=linux/amd64 python:3.12 AS base\nFROM base\nCOPY . .\n")
        );
    }

    public function test_dockerfile_user_is_unknown_behind_a_variable(): void
    {
        $this->assertSame(['user' => null, 'base' => null], WritableProjectBinds::dockerfileUser("FROM alpine\nUSER \${UID}\n"));
        $this->assertSame(['user' => null, 'base' => null], WritableProjectBinds::dockerfileUser("ARG BASE=alpine\nFROM \${BASE}\n"));
        $this->assertSame(['user' => null, 'base' => null], WritableProjectBinds::dockerfileUser(''));
    }
}
