<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeRequiredEnv;
use PHPUnit\Framework\TestCase;

/** `include:` paths stay inside the project, and a cycle of includes is read once. */
class ComposeRequiredEnvIncludesTest extends TestCase
{
    public function test_include_paths_outside_the_project_are_dropped_and_dot_segments_folded(): void
    {
        $yaml = "include:\n  - ./db/compose.yml\n  - /etc/compose.yml\n  - ../outside.yml\n  - path: [docker//./cache.yml]\n";

        $this->assertSame(['db/compose.yml', 'docker/cache.yml'], ComposeRequiredEnv::includedPaths($yaml, ''));
        $this->assertSame(
            ['docker/db/compose.yml', 'outside.yml', 'docker/docker/cache.yml'],
            ComposeRequiredEnv::includedPaths($yaml, 'docker')
        );
    }

    public function test_files_including_each_other_are_read_once(): void
    {
        $files = [
            'compose.yml' => "include:\n  - other.yml\nservices:\n  app:\n    image: acme/app\n",
            'other.yml' => "include:\n  - compose.yml\nservices:\n  db:\n    image: postgres\n",
        ];
        $reads = [];

        $collected = ComposeRequiredEnv::collect('compose.yml', static function (string $path) use ($files, &$reads): ?string {
            $reads[] = $path;

            return $files[$path] ?? null;
        });

        $this->assertSame(['compose.yml', 'other.yml'], $reads);
        $this->assertSame([['dir' => '', 'yaml' => $files['compose.yml']], ['dir' => '', 'yaml' => $files['other.yml']]], $collected);
    }
}
