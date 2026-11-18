<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\MissingBindFiles;
use PHPUnit\Framework\TestCase;

/**
 * A file the compose file binds that the repository ships only as an example: Docker would make a directory of it, and
 * the mount over the image's file fails.
 */
class MissingBindFilesTest extends TestCase
{
    private const DIR = '/home/acme/project';

    /**
     * @param  list<string>  $files  relative paths in the checkout
     * @param  array<string, mixed>  $compose
     * @return array<string, string>
     */
    private function examples(array $compose, array $files): array
    {
        $present = array_flip(array_map(static fn (string $f): string => self::DIR.'/'.$f, $files));

        return MissingBindFiles::examples($compose, self::DIR, static fn (string $path): bool => isset($present[$path]));
    }

    public function test_a_missing_config_is_created_from_the_example_beside_it(): void
    {
        $found = $this->examples(
            ['services' => ['app' => ['volumes' => ['./config.yaml:/app/config.yaml', './data:/app/data']]]],
            ['config.example.yaml']
        );

        $this->assertSame([self::DIR.'/config.yaml' => self::DIR.'/config.example.yaml'], $found);
    }

    public function test_a_long_syntax_bind_and_a_suffixed_example_are_read(): void
    {
        $found = $this->examples(
            ['services' => ['caddy' => ['volumes' => [['type' => 'bind', 'source' => './caddy/Caddyfile', 'target' => '/etc/caddy/Caddyfile']]]]],
            ['caddy/Caddyfile.example']
        );

        $this->assertSame([self::DIR.'/caddy/Caddyfile' => self::DIR.'/caddy/Caddyfile.example'], $found);
    }

    public function test_a_file_the_repository_has_is_left_alone(): void
    {
        $this->assertSame([], $this->examples(
            ['services' => ['app' => ['volumes' => ['./config.yaml:/app/config.yaml:ro']]]],
            ['config.yaml', 'config.example.yaml']
        ));
    }

    public function test_without_an_example_nothing_is_created(): void
    {
        $this->assertSame([], $this->examples(
            ['services' => ['app' => ['volumes' => ['./blacklist.json:/app/backend/blacklist.json']]]],
            []
        ));
    }

    public function test_a_named_volume_a_directory_and_a_path_outside_the_checkout_are_not_files_to_create(): void
    {
        $this->assertSame([], $this->examples(
            ['services' => ['app' => ['volumes' => [
                'config.yaml:/app/config.yaml',
                './conf:/app/conf',
                './../secrets.yaml:/app/secrets.yaml',
                '/etc/app.conf:/app/app.conf',
            ]]]],
            ['config.example.yaml', 'conf.example', '../secrets.example.yaml']
        ));
    }
}
