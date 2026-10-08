<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\SourceBundle;
use PHPUnit\Framework\TestCase;

/**
 * The tree walk. SourceBundlePolicyTest covers *which* files may travel; this
 * covers whether the walk actually honours that on a real directory — pruning,
 * caps, symlinks, and relative paths.
 *
 * plan() is tested directly because it needs no ext-zip; the tests that write
 * an actual archive skip where the extension is absent (it is present in the
 * core container, which is the only place this runs for real).
 */
class SourceBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-bundle-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/project', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function project(): string
    {
        return $this->dir . '/project';
    }

    private function write(string $relative, string $contents = 'x'): void
    {
        $path = $this->project() . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @return list<string>
     */
    private function planned(int $maxBytes = 1048576, int $maxFiles = 1000, int $maxFileBytes = 65536): array
    {
        $plan = SourceBundle::plan($this->project(), $maxBytes, $maxFiles, $maxFileBytes);
        if (isset($plan['skipped'])) {
            return ['SKIPPED:' . $plan['skipped']];
        }
        $names = array_keys($plan['files']);
        sort($names);

        return $names;
    }

    public function test_it_takes_the_source_and_leaves_the_secrets_and_dependencies(): void
    {
        $this->write('package.json', '{"name":"app"}');
        $this->write('src/index.ts', 'console.log(1)');
        $this->write('docker/Dockerfile', 'FROM node:22');
        $this->write('.env.example', 'API_URL=');
        $this->write('.env', 'API_KEY=supersecret');
        $this->write('deploy/id_rsa', 'PRIVATE');
        $this->write('certs/tls.pem', 'CERT');
        $this->write('node_modules/react/index.js', 'module.exports={}');
        $this->write('.git/config', '[remote]');
        $this->write('dist/bundle.js', 'minified');

        $this->assertSame(
            ['.env.example', 'docker/Dockerfile', 'package.json', 'src/index.ts'],
            $this->planned()
        );
    }

    public function test_relative_paths_are_kept_intact(): void
    {
        $this->write('apps/web/src/app/page.tsx', 'export default () => null');

        $this->assertSame(['apps/web/src/app/page.tsx'], $this->planned());
    }

    public function test_a_nested_node_modules_is_pruned_too(): void
    {
        $this->write('apps/web/package.json', '{}');
        $this->write('apps/web/node_modules/dep/index.js', 'x');

        $this->assertSame(['apps/web/package.json'], $this->planned());
    }

    public function test_a_file_over_the_per_file_cap_is_left_out_without_failing_the_bundle(): void
    {
        $this->write('package.json', '{}');
        $this->write('assets/video.mp4', str_repeat('a', 5000));

        $this->assertSame(['package.json'], $this->planned(1048576, 1000, 1000));
    }

    /**
     * Half a repository is a misleading bug report, so busting a cap produces
     * no bundle rather than a truncated one.
     */
    public function test_busting_the_total_size_cap_produces_no_bundle(): void
    {
        $this->write('a.txt', str_repeat('a', 600));
        $this->write('b.txt', str_repeat('b', 600));

        $this->assertSame(['SKIPPED:' . SourceBundle::SKIP_TOO_LARGE], $this->planned(1000));
    }

    public function test_busting_the_file_count_cap_produces_no_bundle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->write("file{$i}.txt", 'x');
        }

        $this->assertSame(['SKIPPED:' . SourceBundle::SKIP_TOO_MANY_FILES], $this->planned(1048576, 3));
    }

    /**
     * A symlink can point anywhere, including out of the account's home
     * directory and into another customer's files.
     */
    public function test_symlinks_are_never_followed_into_the_bundle(): void
    {
        $this->write('package.json', '{}');
        file_put_contents($this->dir . '/outside-secret.txt', 'not yours');
        symlink($this->dir . '/outside-secret.txt', $this->project() . '/link.txt');
        mkdir($this->dir . '/elsewhere', 0700, true);
        file_put_contents($this->dir . '/elsewhere/other.txt', 'also not yours');
        symlink($this->dir . '/elsewhere', $this->project() . '/linkdir');

        $planned = $this->planned();

        $this->assertSame(['package.json'], $planned);
    }

    public function test_a_missing_project_directory_is_reported_as_empty(): void
    {
        $result = SourceBundle::create($this->dir . '/nope', $this->dir . '/out.zip', 1048576, 100, 65536);

        $this->assertFalse($result['available']);
        $this->assertSame(SourceBundle::SKIP_EMPTY, $result['skipped']);
    }

    public function test_a_project_with_nothing_sendable_produces_no_bundle(): void
    {
        $this->write('.env', 'SECRET=1');
        $this->write('node_modules/x/index.js', 'x');

        $result = SourceBundle::create($this->project(), $this->dir . '/out.zip', 1048576, 100, 65536);

        $this->assertFalse($result['available']);
        $this->assertFileDoesNotExist($this->dir . '/out.zip');
    }

    public function test_it_writes_a_readable_archive_with_a_checksum(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not installed (it is present in the core container image)');
        }

        $this->write('package.json', '{"name":"app"}');
        $this->write('src/index.ts', 'console.log(1)');
        $this->write('.env', 'API_KEY=supersecret');

        $zipPath = $this->dir . '/out.zip';
        $result = SourceBundle::create($this->project(), $zipPath, 1048576, 100, 65536);

        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['files']);
        $this->assertFileExists($zipPath);
        $this->assertSame(hash_file('sha256', $zipPath), $result['sha256']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $zip->close();

        $this->assertSame(['package.json', 'src/index.ts'], $names);
        $this->assertStringNotContainsString('supersecret', (string) file_get_contents($zipPath));
    }
}
