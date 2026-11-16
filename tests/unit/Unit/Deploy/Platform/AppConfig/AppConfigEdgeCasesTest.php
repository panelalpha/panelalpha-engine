<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The refusals and empty answers of the app config readers, word for word.
 */
class AppConfigEdgeCasesTest extends TestCase
{
    public function test_blank_content_is_no_app_config(): void
    {
        $this->assertNull(AppConfig::fromMarkdown(" \n\t"));
        $this->assertNull(AppConfig::fromYaml(" \n"));
    }

    public function test_a_markdown_page_with_an_embedded_config_reads_both(): void
    {
        $config = AppConfig::fromMarkdown(
            "# App\n\n`panelalpha.yaml`\n```yaml\nenv:\n  MODE: prod\ngit:\n  history: full\n```\n"
        );

        $this->assertNotNull($config);
        $this->assertSame(['MODE' => 'prod'], $config->env());
        $this->assertTrue($config->fullGitHistory());
        $this->assertSame(AppConfig::COMPOSE_REPLACE, $config->composeMode());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusals(): array
    {
        return [
            'scalar document' => ['just words', 'panelalpha.yaml does not contain a mapping'],
            'extends a list' => ["extends: [a]\n", "panelalpha.yaml: 'extends' must name a shipped recipe"],
            'extends blank' => ["extends: '  '\n", "panelalpha.yaml: 'extends' must name a shipped recipe"],
            'files a string' => ["files: x\n", "panelalpha.yaml: 'files' must be an object of path => contents"],
            'files a list' => ["files: [a]\n", "panelalpha.yaml: 'files' keys must be paths"],
            'files contents' => ["files:\n  a.txt: [1]\n", "panelalpha.yaml: contents of 'a.txt' must be a string"],
            'files dot traversal' => ["files:\n  ./../x: y\n", "panelalpha.yaml: './../x' is not a path inside the project"],
            'files dot-dot run' => ["files:\n  ../../etc/cron.d/x: y\n", "panelalpha.yaml: '../../etc/cron.d/x' is not a path inside the project"],
            'files absolute' => ["files:\n  /etc/x: y\n", "panelalpha.yaml: '/etc/x' is not a path inside the project"],
            'files inner dot-dot' => ["files:\n  a/../../x: y\n", "panelalpha.yaml: 'a/../../x' is not a path inside the project"],
            'files trailing dot-dot' => ["files:\n  a/..: y\n", "panelalpha.yaml: 'a/..' is not a path inside the project"],
            'files nul byte' => ["files:\n  \"a\\0b\": y\n", "panelalpha.yaml: 'a\0b' is not a path inside the project"],
            'describes without id' => ["port: 8080\n", "panelalpha.yaml: declares port but has no 'id', or 'extends' naming the recipe to start from"],
            'prepare before precheck' => ["precheck: 5\nprepare: 6\n", "panelalpha.yaml: 'prepare' must be a non-empty script"],
            'compose content before mode' => ["compose: {content: '', mode: nope}\n", "panelalpha.yaml: 'compose.content' must be a non-empty string"],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_malformed_config_is_refused_with_its_message(string $yaml, string $message): void
    {
        try {
            AppConfig::fromYaml($yaml);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_a_leading_dot_slash_is_dropped_from_a_file_path(): void
    {
        $config = AppConfig::fromYaml("files:\n  ./conf/app.ini: \"a=1\"\n  ' web/x.txt ': y\n");

        $this->assertNotNull($config);
        $this->assertSame([
            ['path' => 'conf/app.ini', 'contents' => 'a=1'],
            ['path' => 'web/x.txt', 'contents' => 'y'],
        ], $config->files());
    }

    /** @return array<string, array{0: string, 1: ?array<string, mixed>}> */
    public static function manifests(): array
    {
        return [
            'only commands' => ["commands:\n  - {run: make, stage: build}\n", null],
            'requires alone' => ["requires:\n  node: runtime\n", null],
            'extends alone' => ["extends: laravel\n", ['extends' => 'laravel', 'id' => 'laravel']],
            'extends with an id' => ["id: mine\nextends: ' laravel '\n", ['id' => 'mine', 'extends' => ' laravel ']],
            'extends with a blank id' => ["id: ''\nextends: laravel\nport: 81\n", ['id' => 'laravel', 'port' => 81, 'extends' => 'laravel']],
            'an id of its own' => ["id: mine\nlabel: Mine\nenv: {A: b}\n", ['id' => 'mine', 'label' => 'Mine']],
            'credentials ride along' => ["id: mine\nport_scheme: https\n", ['id' => 'mine', 'port_scheme' => 'https']],
        ];
    }

    /** @param ?array<string, mixed> $expected */
    #[DataProvider('manifests')]
    public function test_the_manifest_keys_a_config_carries(string $yaml, ?array $expected): void
    {
        $this->assertSame($expected, AppConfig::fromYaml($yaml)?->manifest());
    }
}
