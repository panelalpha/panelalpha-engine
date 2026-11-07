<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a catalogue entry resolves to, every spec field and version list pinned.
 */
class RuntimeImageCatalogSpecTest extends TestCase
{
    private const CONFIG = <<<'YAML'
runtimes:
  demo:
    default: "2"
    image:
      from: "demo:{version}-slim"
      build_from: "demo:{version}-full"
      overrides:
        "3":
          from: "other/demo:3"
          build_from: "bad ref!"
        "4":
          from: "not a ref"
      build:
        repository: "panelalpha/demo"
        stub: "dockerfile/demo"
        runnable: true
    versions:
      - version: "1"
      - "2"
      - 3
      - version: 4
      - 2.5
      - "2"
      - "x.y"
      - version: ""
      - [1, 2]
      - "5-alpine"
  halfbuild:
    default: "9"
    image:
      from: "half:{version}"
      build:
        repository: "panelalpha/half"
    versions:
      - "8"
  nobuild:
    image:
      from: "plain:latest"
      build_from: "  "
  badstub:
    default: "1"
    image:
      from: "stub:{version}"
      build:
        repository: "panelalpha/stub"
        stub: "../escape"
    versions: ["1"]
  noimage:
    image: "string"
    versions: "not a list"
YAML;

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'runtime-images-').'.yaml';
        file_put_contents($this->path, self::CONFIG);
        RuntimeImageCatalog::useConfig($this->path);
    }

    protected function tearDown(): void
    {
        RuntimeImageCatalog::useConfig(null);
        @unlink($this->path);
        parent::tearDown();
    }

    public static function specs(): array
    {
        return [
            'templated' => ['demo', '2', ['demo', '2', 'demo:2-slim', 'panelalpha/demo', 'dockerfile/demo', true, 'demo:2-full']],
            'override with an unsafe build_from' => ['demo', '3', ['demo', '3', 'other/demo:3', 'panelalpha/demo', 'dockerfile/demo', true, null]],
            'override with an unsafe from' => ['demo', '4', null],
            'half a build block' => ['halfbuild', '8', ['halfbuild', '8', 'half:8', null, null, false, null]],
            'no build block, blank build_from' => ['nobuild', 'anything', ['nobuild', 'anything', 'plain:latest', null, null, false, null]],
            'climbing stub' => ['badstub', '1', ['badstub', '1', 'stub:1', null, null, false, null]],
            'image not a map' => ['noimage', '1', null],
            'unknown runtime' => ['missing', '1', null],
        ];
    }

    /** @param list<mixed>|null $expected */
    #[DataProvider('specs')]
    public function test_the_spec_is_pinned(string $runtime, string $version, ?array $expected): void
    {
        $spec = RuntimeImageCatalog::spec($runtime, $version);

        $this->assertSame($expected, $spec === null ? null : [$spec->runtime, $spec->version, $spec->from, $spec->repository, $spec->stub, $spec->runnable, $spec->buildFrom]);
    }

    public static function versionLists(): array
    {
        return [
            'demo' => ['demo', ['1', '2', '3', '4', '2.5', '5-alpine'], '2'],
            'halfbuild' => ['halfbuild', ['8'], null],
            'nobuild' => ['nobuild', [], null],
            'badstub' => ['badstub', ['1'], '1'],
            'noimage' => ['noimage', [], null],
            'missing' => ['missing', [], null],
        ];
    }

    /** @param list<string> $versions */
    #[DataProvider('versionLists')]
    public function test_the_versions_and_default_are_pinned(string $runtime, array $versions, ?string $default): void
    {
        $this->assertSame($versions, RuntimeImageCatalog::versions($runtime));
        $this->assertSame($default, RuntimeImageCatalog::defaultVersion($runtime));
    }
}
