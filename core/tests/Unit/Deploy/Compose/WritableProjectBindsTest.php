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
}
