<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\DockerIgnore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DockerIgnoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dockerignore-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function cases(): array
    {
        return [
            'exact name' => [".npmrc\n", '.npmrc', true],
            'leading slash' => ["/.npmrc\n", '.npmrc', true],
            'leading ./' => ["./.npmrc\n", '.npmrc', true],
            'comment is not a pattern' => ["# .npmrc\n", '.npmrc', false],
            'another name' => [".npmrc.example\n", '.npmrc', false],
            'star within a segment' => ["*.yaml\n", 'pnpm-workspace.yaml', true],
            'star does not cross a slash' => ["*.yaml\n", 'config/app.yaml', false],
            'double star crosses slashes' => ["**/*.yaml\n", 'config/app.yaml', true],
            'a directory excludes its contents' => ["config\n", 'config/app.yaml', true],
            'negation re-includes' => ["*\n!package.json\n!.npmrc\n", '.npmrc', false],
            'last match wins' => ["!.npmrc\n.npmrc\n", '.npmrc', true],
            'question mark is one character' => [".npmr?\n", '.npmrc', true],
            'crlf line endings' => [".git\r\n.npmrc\r\n", '.npmrc', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_excludes(string $dockerignore, string $path, bool $expected): void
    {
        file_put_contents($this->dir . '/.dockerignore', $dockerignore);

        $this->assertSame($expected, DockerIgnore::excludes($this->dir, $path));
    }

    public function test_no_dockerignore_excludes_nothing(): void
    {
        $this->assertFalse(DockerIgnore::excludes($this->dir, '.npmrc'));
        $this->assertFalse(DockerIgnore::excludes('', '.npmrc'));
    }
}
