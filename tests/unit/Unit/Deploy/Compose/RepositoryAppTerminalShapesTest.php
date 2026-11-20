<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RepositoryAppTerminal;
use PHPUnit\Framework\TestCase;

/** A compose file that does not parse is skipped, and so is a build that names no path. */
class RepositoryAppTerminalShapesTest extends TestCase
{
    public function test_an_unreadable_file_and_a_build_with_no_path_are_passed_over(): void
    {
        $settings = RepositoryAppTerminal::settings('Dockerfile', [
            'docker-compose.yml' => "services: [unclosed\n",
            'compose.yaml' => "services:\n  odd:\n    build:\n      context: [a, b]\n    tty: true\n"
                . "  app:\n    build: .\n    stdin_open: true\n",
        ]);

        $this->assertSame(['stdin_open' => true], $settings);
    }
}
