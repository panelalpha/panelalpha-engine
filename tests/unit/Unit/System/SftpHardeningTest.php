<?php

namespace Tests\Unit\System;

use Tests\TestCase;

/**
 * SFTP logins are confined by the engine's own sshd Match blocks, not only by
 * the upstream base image's global sshd_config, and the base image is pinned by
 * digest so a rebuild cannot silently change what the guest can reach.
 */
class SftpHardeningTest extends TestCase
{
    private function repo(string $path): string
    {
        $full = dirname(base_path()) . '/' . $path;
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    public function test_every_generated_match_block_confines_the_login(): void
    {
        $script = $this->repo('templates/config/sftp/sync-logins.sh');

        $this->assertSame(1, preg_match('/Match User \$user\n(.*?)\nEOF/s', $script, $matches));

        foreach ([
            'ChrootDirectory %h',
            'ForceCommand internal-sftp',
            'AllowTcpForwarding no',
            'X11Forwarding no',
        ] as $directive) {
            $this->assertStringContainsString($directive, $matches[1]);
        }
    }

    public function test_the_sftp_base_image_is_pinned_by_digest(): void
    {
        $dockerfile = $this->repo('dockerfiles/Dockerfile-sftp');

        $this->assertMatchesRegularExpression(
            '#^FROM atmoz/sftp:debian@sha256:[0-9a-f]{64}$#m',
            trim($dockerfile)
        );
    }
}
