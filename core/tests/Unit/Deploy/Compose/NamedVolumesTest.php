<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\NamedVolumes;
use PHPUnit\Framework\TestCase;

/**
 * The `volumes:` block a reduced stack still needs.
 *
 * Two failures on either side of this: declaring a volume no kept service
 * mounts is a directory the account pays for forever, and mounting one the
 * file never declared is a compose validation error that stops the deploy
 * before anything starts.
 */
class NamedVolumesTest extends TestCase
{
    public function test_a_mounted_volume_keeps_its_declaration(): void
    {
        $volumes = NamedVolumes::usedBy(
            ['db' => ['volumes' => ['dbdata:/var/lib/postgresql/data']]],
            ['dbdata' => ['driver' => 'local']]
        );

        $this->assertSame(['dbdata' => ['driver' => 'local']], $volumes);
    }

    public function test_a_mounted_volume_the_file_never_declared_is_still_declared(): void
    {
        // Compose refuses to start a stack that mounts an undeclared volume,
        // so the null default matters: it emits `dbdata:` with no options.
        $volumes = NamedVolumes::usedBy(['db' => ['volumes' => ['dbdata:/var/lib/mysql']]], []);

        $this->assertSame(['dbdata' => null], $volumes);
    }

    public function test_a_declared_volume_nothing_mounts_is_dropped(): void
    {
        // Left over from a service the reduction removed.
        $volumes = NamedVolumes::usedBy(
            ['app' => ['image' => 'acme/app']],
            ['dbdata' => null, 'esdata' => null]
        );

        $this->assertSame([], $volumes);
    }

    public function test_a_bind_mount_is_not_a_named_volume(): void
    {
        // It points at a path on someone's workstation that does not exist in
        // the account.
        $volumes = NamedVolumes::usedBy(
            ['app' => ['volumes' => ['./src:/app/src', '.:/app', '/etc/localtime:/etc/localtime:ro']]],
            []
        );

        $this->assertSame([], $volumes);
    }

    /**
     * godoxy: split at the first `:` the reference itself became a
     * volume called `${DOCKER_SOCKET`, and compose refused the whole file.
     */
    public function test_a_variable_bind_source_is_not_a_named_volume(): void
    {
        $volumes = NamedVolumes::usedBy(
            ['socket-proxy' => ['volumes' => [
                '${DOCKER_SOCKET:-/var/run/docker.sock}:/var/run/docker.sock',
                '${CONFIG_DIR:?set it}:/config',
                '${DATA}:/data',
            ]]],
            []
        );

        $this->assertSame([], $volumes);
    }

    public function test_a_named_volume_given_by_a_default_is_still_declared(): void
    {
        $volumes = NamedVolumes::usedBy(['db' => ['volumes' => ['${DB_VOLUME:-dbdata}:/var/lib/mysql']]], []);

        $this->assertSame(['dbdata' => null], $volumes);
    }

    public function test_the_long_form_is_read(): void
    {
        $volumes = NamedVolumes::usedBy(
            ['db' => ['volumes' => [['type' => 'volume', 'source' => 'dbdata', 'target' => '/var/lib/mysql']]]],
            ['dbdata' => null]
        );

        $this->assertSame(['dbdata' => null], $volumes);
    }

    public function test_the_long_form_bind_is_not_a_named_volume(): void
    {
        $volumes = NamedVolumes::usedBy(
            ['app' => ['volumes' => [['type' => 'bind', 'source' => './src', 'target' => '/app/src']]]],
            []
        );

        $this->assertSame([], $volumes);
    }

    public function test_an_anonymous_volume_needs_no_declaration(): void
    {
        // `- /var/lib/mysql` with no source: Docker names it itself.
        $volumes = NamedVolumes::usedBy(['db' => ['volumes' => ['/var/lib/mysql']]], []);

        $this->assertSame([], $volumes);
    }

    public function test_one_volume_shared_by_two_services_is_declared_once(): void
    {
        $volumes = NamedVolumes::usedBy(
            [
                'app' => ['volumes' => ['uploads:/app/storage']],
                'worker' => ['volumes' => ['uploads:/app/storage']],
            ],
            ['uploads' => null]
        );

        $this->assertSame(['uploads' => null], $volumes);
    }

    public function test_a_service_that_mounts_nothing_contributes_nothing(): void
    {
        $this->assertSame([], NamedVolumes::usedBy(['app' => ['image' => 'acme/app']], ['dbdata' => null]));
        $this->assertSame([], NamedVolumes::usedBy(['app' => ['volumes' => 'dbdata:/data']], ['dbdata' => null]));
    }

    /** deemix mounts `${DEEMIX_CONFIG_PATH}:/config` and says to set it in the shell. */
    public function test_an_unset_variable_mount_source_becomes_a_named_volume(): void
    {
        $compose = ['services' => ['deemix' => ['image' => 'ghcr.io/bambanah/deemix', 'volumes' => [
            '${DEEMIX_CONFIG_PATH}:/config',
            '${DEEMIX_MUSIC_PATH}:/downloads:rw',
            '${SET_PATH}:/set',
            '${WITH_DEFAULT:-./data}:/data',
        ]]]];

        $result = NamedVolumes::forUnsetSources($compose, ['SET_PATH' => ['./set']]);

        $this->assertSame(
            ['deemix-config-path:/config', 'deemix-music-path:/downloads:rw', '${SET_PATH}:/set', '${WITH_DEFAULT:-./data}:/data'],
            $result['compose']['services']['deemix']['volumes']
        );
        $this->assertSame(['deemix-config-path' => null, 'deemix-music-path' => null], $result['compose']['volumes']);
        $this->assertCount(2, $result['replaced']);
    }
}
