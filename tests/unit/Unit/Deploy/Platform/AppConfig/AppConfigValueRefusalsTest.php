<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The refusals for a malformed `app`, `compose`, `commands`, `requires` and `env`, and how content without a filename is read.
 */
class AppConfigValueRefusalsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function refusals(): array
    {
        return [
            'compose a number' => ["compose: 5\n", "panelalpha.yaml: 'compose' must be a string or an object"],
            'app a number' => ["app: 5\n", "panelalpha.yaml: 'app' must be a script or an object"],
            'app without a script' => ["app: {script: ''}\n", "panelalpha.yaml: 'app.script' must be a non-empty string"],
            'commands a string' => ["commands: run\n", "panelalpha.yaml: 'commands' must be a list"],
            'a command that is a string' => ["commands:\n  - echo hi\n", 'panelalpha.yaml.commands[0]: must be an object'],
            'two commands with one id' => [
                "commands:\n  - {id: seed, run: 'echo a', stage: build}\n  - {id: seed, run: 'echo b', stage: build}\n",
                "panelalpha.yaml: duplicate command id 'seed'",
            ],
            'requires a list' => ["requires: node\n", "panelalpha.yaml: 'requires' must be an object"],
            'requires a bad name' => ["requires:\n  Node_JS: runtime\n", "panelalpha.yaml: 'Node_JS' is not a valid runtime name"],
            'requires a number' => ["requires:\n  node: 5\n", 'panelalpha.yaml.requires.node: must be a role name or an object'],
            'env a list' => ["env: X\n", "panelalpha.yaml: 'env' must be an object"],
            'env a bad name' => ["env:\n  1X: y\n", "panelalpha.yaml: '1X' is not a valid environment variable name"],
            'env a list value' => ["env:\n  X: [1]\n", 'panelalpha.yaml.env.X: must be a scalar'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_malformed_value_is_refused_with_its_message(string $yaml, string $message): void
    {
        try {
            AppConfig::fromYaml($yaml);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_requires_and_env_are_read_into_their_shapes(): void
    {
        $config = AppConfig::fromYaml("requires:\n  node: build\n  python: {optional: true}\nenv:\n  DEBUG: false\n  PORT: 8080\n  NAME: app\n");

        $this->assertNotNull($config);
        $this->assertSame([
            'node' => ['role' => 'build', 'optional' => false],
            'python' => ['role' => 'runtime', 'optional' => true],
        ], $config->requires());
        $this->assertSame(['DEBUG' => 'false', 'PORT' => '8080', 'NAME' => 'app'], $config->env());
    }

    public function test_an_app_object_names_its_script(): void
    {
        $this->assertSame('node server.js', AppConfig::fromYaml("app:\n  script: node server.js\n")?->appScript());
    }

    public function test_content_that_opens_with_a_known_key_is_read_as_yaml(): void
    {
        $this->assertSame(['MODE' => 'prod'], AppConfig::fromContent("# hosting\n\nenv:\n  MODE: prod\n")?->env());
    }

    public function test_content_that_opens_with_prose_is_read_as_a_page(): void
    {
        $config = AppConfig::fromContent("Deploy notes for this app.\n");

        $this->assertNotNull($config);
        $this->assertSame([], $config->env());
        $this->assertNull($config->appScript());
    }

    public function test_content_of_only_comments_is_read_as_a_page(): void
    {
        $this->assertNotNull(AppConfig::fromContent("# just a heading\n# and another\n"));
        $this->assertNull(AppConfig::fromContent(" \n"));
    }
}
