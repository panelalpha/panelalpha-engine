<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\PnpmMajor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which pnpm a project with no `packageManager` is installed with.
 *
 * pnpm refuses a lockfile written by another major
 * (`ERR_PNPM_LOCKFILE_BREAKING_CHANGE`, hologram-particles' 6.0 lockfile under
 * pnpm 10) and refuses an unmet `engines.pnpm` (`ERR_PNPM_UNSUPPORTED_ENGINE`,
 * illa-builder's `^8` in a workspace member), so the default only applies when
 * the project says nothing.
 */
class PnpmMajorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-pnpm-major-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function lockfile(string $version): void
    {
        $this->write('pnpm-lock.yaml', "lockfileVersion: {$version}\n\nsettings:\n  autoInstallPeers: true\n");
    }

    /** @param array<string, mixed> $package */
    private function install(array $package = []): string
    {
        return JsPackageManager::installCommand('pnpm', ['pnpm-lock.yaml' => true], $package, $this->dir);
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function lockfileVersions(): iterable
    {
        yield 'pnpm 6 writes 5.3' => ["'5.3'", 7];
        yield 'pnpm 7 writes 5.4' => ['5.4', 7];
        yield 'pnpm 8 writes 6.0' => ["'6.0'", 8];
        yield 'pnpm 9 and 10 write 9.0' => ["'9.0'", 10];
        yield 'double-quoted' => ['"6.0"', 8];
    }

    #[DataProvider('lockfileVersions')]
    public function test_the_lockfile_names_the_major(string $version, int $major): void
    {
        $this->lockfile($version);

        $this->assertSame($major, PnpmMajor::forProject([], $this->dir));
        $this->assertStringContainsString("corepack prepare pnpm@{$major} --activate", $this->install());
    }

    public function test_a_pnpm_8_lockfile_gets_no_pnpm_10_flag(): void
    {
        $this->lockfile("'6.0'");

        $this->assertStringEndsWith('pnpm install --frozen-lockfile', $this->install());
    }

    public function test_nothing_declared_is_the_default(): void
    {
        $this->assertSame(PnpmMajor::DEFAULT, PnpmMajor::forProject([], $this->dir));
        $this->assertSame(PnpmMajor::DEFAULT, PnpmMajor::forProject([], null));
    }

    public function test_an_unknown_lockfile_version_is_the_default(): void
    {
        $this->lockfile("'42.0'");

        $this->assertSame(PnpmMajor::DEFAULT, PnpmMajor::forProject([], $this->dir));
    }

    public function test_package_manager_outranks_the_lockfile(): void
    {
        $this->lockfile("'6.0'");

        $this->assertStringContainsString(
            'corepack prepare pnpm@9.15.0 --activate',
            $this->install(['packageManager' => 'pnpm@9.15.0'])
        );
    }

    public function test_engines_pnpm_names_the_major(): void
    {
        $this->lockfile("'9.0'");

        $this->assertSame(9, PnpmMajor::forProject(['engines' => ['pnpm' => '^9.1.0']], $this->dir));
    }

    public function test_an_open_engines_range_keeps_the_lockfile_major(): void
    {
        $this->lockfile("'6.0'");

        $this->assertSame(8, PnpmMajor::forProject(['engines' => ['pnpm' => '>=8']], $this->dir));
    }

    public function test_an_open_engines_range_without_a_lockfile_takes_the_newest(): void
    {
        $this->assertSame(10, PnpmMajor::forProject(['engines' => ['pnpm' => '>=7']], $this->dir));
    }

    public function test_a_workspace_member_engines_pnpm_counts(): void
    {
        $this->lockfile("'9.0'");
        $this->write('pnpm-workspace.yaml', "packages:\n  - \"apps/*\"\n  - 'tools/cli' # the CLI\n");
        $this->write('apps/builder/package.json', (string) json_encode(['engines' => ['pnpm' => '^9']]));
        $this->write('tools/cli/package.json', (string) json_encode(['engines' => ['pnpm' => '>=8 <10']]));

        $this->assertSame(9, PnpmMajor::forProject([], $this->dir));
    }

    public function test_an_npm_workspaces_member_counts(): void
    {
        $this->write('packages/ui/package.json', (string) json_encode(['engines' => ['pnpm' => '8.x']]));

        $this->assertSame(8, PnpmMajor::forProject(['workspaces' => ['packages/*']], $this->dir));
    }

    public function test_an_engines_range_nothing_satisfies_falls_back_to_the_lockfile(): void
    {
        $this->lockfile("'6.0'");

        $this->assertSame(8, PnpmMajor::forProject(['engines' => ['pnpm' => '^3']], $this->dir));
    }

    /** @return iterable<string, array{0: string, 1: int, 2: bool}> */
    public static function ranges(): iterable
    {
        yield 'caret' => ['^8.6.0', 8, true];
        yield 'caret other major' => ['^8', 10, false];
        yield 'tilde' => ['~9.1', 9, true];
        yield 'x range' => ['8.x', 8, true];
        yield 'floor' => ['>=8', 10, true];
        yield 'floor below' => ['>=9', 8, false];
        yield 'strict floor' => ['>8', 8, false];
        yield 'strict floor with minor' => ['>8.1', 8, true];
        yield 'ceiling' => ['<9', 9, false];
        yield 'ceiling with minor' => ['<9.5', 9, true];
        yield 'bounded' => ['>=7 <9', 8, true];
        yield 'bounded above' => ['>=7 <9', 9, false];
        yield 'alternatives' => ['^7 || ^9', 9, true];
        yield 'hyphen' => ['7.0.0 - 8.5.0', 8, true];
        yield 'any' => ['*', 6, true];
        yield 'exact' => ['8.15.4', 8, true];
    }

    #[DataProvider('ranges')]
    public function test_satisfies(string $range, int $major, bool $expected): void
    {
        $this->assertSame($expected, PnpmMajor::satisfies($major, $range));
    }
}
