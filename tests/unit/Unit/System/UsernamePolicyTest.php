<?php

namespace Tests\Unit\System;

use App\System\UsernamePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A project gets an OS user and a home directory named after it, so the rule
 * here is `useradd`'s. It was a regex and a nineteen-name list inside
 * System::isUsernameAvailable(), which cannot run without a host.
 */
class UsernamePolicyTest extends TestCase
{
    #[DataProvider('wellFormed')]
    public function test_a_well_formed_name_is_accepted(string $username): void
    {
        $this->assertTrue(UsernamePolicy::isWellFormed($username), $username);
        $this->assertTrue(UsernamePolicy::isAcceptable($username), $username);
    }

    /** @return array<string, array{string}> */
    public static function wellFormed(): array
    {
        return [
            'plain' => ['alice'],
            'with digits' => ['app2'],
            'with underscore' => ['my_app'],
            'with hyphen' => ['my-app'],
            'single letter' => ['a'],
            'exactly 32 characters' => ['a' . str_repeat('b', 31)],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_name_is_refused(string $username, string $why): void
    {
        $this->assertFalse(UsernamePolicy::isWellFormed($username), $why);
        $this->assertFalse(UsernamePolicy::isAcceptable($username), $why);
    }

    /** @return array<string, array{string, string}> */
    public static function malformed(): array
    {
        return [
            'empty' => ['', 'an empty name'],
            'starts with a digit' => ['1app', 'useradd refuses a leading digit'],
            'starts with a hyphen' => ['-app', 'a leading hyphen reads as an option'],
            'starts with an underscore' => ['_app', 'must start with a letter'],
            'uppercase' => ['Alice', 'lower case only'],
            'dot' => ['my.app', 'a dot is not allowed'],
            'space' => ['my app', 'a space is not allowed'],
            'slash' => ['my/app', 'a slash would escape the home directory'],
            'null byte' => ["alice\0", 'a null byte truncates downstream'],
            'newline' => ["alice\nroot", 'a newline could forge a second line'],
            '33 characters' => ['a' . str_repeat('b', 32), 'one over the limit'],
            'non-ascii' => ['álice', 'ascii only'],
        ];
    }

    #[DataProvider('reserved')]
    public function test_a_name_the_base_system_owns_is_refused(string $username): void
    {
        $this->assertTrue(UsernamePolicy::isWellFormed($username), 'shape is fine');
        $this->assertTrue(UsernamePolicy::isReserved($username));
        $this->assertFalse(UsernamePolicy::isAcceptable($username), $username . ' must be refused');
    }

    /** @return array<string, array{string}> */
    public static function reserved(): array
    {
        return array_map(static fn (string $n): array => [$n], array_combine(
            UsernamePolicy::reserved(),
            UsernamePolicy::reserved()
        ));
    }

    public function test_the_reserved_list_is_lower_case_and_unique(): void
    {
        $reserved = UsernamePolicy::reserved();

        $this->assertSame($reserved, array_unique($reserved));
        $this->assertSame($reserved, array_map('strtolower', $reserved));
        $this->assertContains('root', $reserved);
        $this->assertContains('www-data', $reserved);
    }

    /** The proxy's resolver may answer these already, so a project's rules would reach them. */
    public function test_a_name_the_engines_networks_already_answer_is_refused(): void
    {
        foreach (['core', 'ftp', 'sftp', 'lighthouse', 'dind', 'php', 'localhost', 'localhost4', 'localhost6'] as $name) {
            $this->assertFalse(UsernamePolicy::isAcceptable($name), $name);
        }
    }

    /** A service added to the engine's or an account's compose file must be reserved too. */
    public function test_every_compose_service_name_a_project_could_take_is_reserved(): void
    {
        $root = dirname(__DIR__, 4);
        $names = [];
        $files = glob($root . '/docker-compose.yml*') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            foreach ((array) (Yaml::parseFile($file)['services'] ?? []) as $service => $definition) {
                $names[] = (string) $service;
                if (is_array($definition) && is_string($definition['container_name'] ?? null)) {
                    $names[] = $definition['container_name'];
                }
            }
        }
        // The account templates are Blade, not YAML: their service is the first key under services.
        $templates = [...(glob($root . '/templates/user/*/project/docker-compose.yml*.blade.php') ?: []),
            ...(glob($root . '/templates/user-config/docker-compose.yml*.blade.php') ?: [])];
        $this->assertNotEmpty($templates);
        foreach ($templates as $file) {
            $this->assertSame(1, preg_match('/^services:\n  ([a-z0-9_-]+):/m', (string) file_get_contents($file), $m), $file);
            $names[] = $m[1];
        }

        $names = array_values(array_unique($names));
        $this->assertContains('core', $names);
        $this->assertContains('dind', $names);
        foreach ($names as $name) {
            // A project name has no hyphen.
            if (!str_contains($name, '-')) {
                $this->assertTrue(UsernamePolicy::isReserved($name), "'{$name}' must be reserved");
            }
        }
    }

    public function test_a_name_that_merely_contains_a_reserved_one_is_fine(): void
    {
        $this->assertTrue(UsernamePolicy::isAcceptable('rooted'));
        $this->assertTrue(UsernamePolicy::isAcceptable('mailer'));
        $this->assertFalse(UsernamePolicy::isReserved('rooted'));
    }
}
