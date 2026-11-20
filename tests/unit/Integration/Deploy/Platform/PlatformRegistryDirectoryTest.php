<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Loading manifests from an operator's directory: which files count, the order, the cache and the errors.
 */
class PlatformRegistryDirectoryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-registry-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
        PlatformRegistry::flush();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        PlatformRegistry::flush();
    }

    private function manifest(string $relative, string $id, int $priority): void
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "id: {$id}\nlabel: " . ucfirst($id) . "\npriority: {$priority}\nruntime: command\ndetect:\n  file: {$id}.txt\n");
    }

    public function test_manifests_load_highest_priority_first_then_by_id(): void
    {
        $this->manifest('b.yaml', 'beta', 10);
        $this->manifest('a.YML', 'alpha', 10);
        $this->manifest('c.yaml', 'gamma', 50);
        $this->manifest('nested/panelalpha.yaml', 'delta', 20);
        $this->manifest('_shared.yaml', 'shared', 99);
        $this->manifest('.hidden.yaml', 'hidden', 99);
        $this->manifest('_app/panelalpha.yaml', 'underscored', 99);
        $this->manifest('deep/more/panelalpha.yaml', 'deep', 99);
        $this->manifest('empty-dir/other.yaml', 'other', 99);
        file_put_contents($this->dir . '/notes.txt', 'not a manifest');

        $all = PlatformRegistry::all($this->dir);

        $this->assertSame(
            ['gamma', 'delta', 'alpha', 'beta'],
            array_map(static fn (PlatformManifest $m): string => $m->id, $all)
        );
        $this->assertSame($all, PlatformRegistry::all($this->dir . '/'));
    }

    public function test_the_cache_holds_until_it_is_flushed(): void
    {
        $this->manifest('a.yaml', 'alpha', 10);
        $first = PlatformRegistry::all($this->dir);
        $this->manifest('b.yaml', 'beta', 20);

        $this->assertSame($first, PlatformRegistry::all($this->dir));

        PlatformRegistry::flush();

        $this->assertSame(
            ['beta', 'alpha'],
            array_map(static fn (PlatformManifest $m): string => $m->id, PlatformRegistry::all($this->dir))
        );
    }

    public function test_a_duplicate_id_names_both_files(): void
    {
        $this->manifest('a.yaml', 'alpha', 10);
        $this->manifest('b.yaml', 'alpha', 20);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("Duplicate platform id 'alpha' in {$this->dir}/b.yaml and {$this->dir}/a.yaml");

        PlatformRegistry::all($this->dir);
    }

    public function test_a_missing_directory_is_an_error(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("Platform manifest directory not found: {$this->dir}/nope");

        PlatformRegistry::all($this->dir . '/nope/');
    }

    public function test_finders_resolve_within_the_directory(): void
    {
        $this->manifest('a.yaml', 'alpha', 10);
        $this->manifest('b.yaml', 'beta', 20);

        $this->assertSame('alpha', PlatformRegistry::find('alpha', $this->dir)?->id);
        $this->assertNull(PlatformRegistry::find('nope', $this->dir));
        $this->assertSame('beta', PlatformRegistry::forDecision(['platform' => 'beta'], $this->dir)?->id);
        $this->assertNull(PlatformRegistry::forDecision(['platform' => ''], $this->dir));
        $this->assertSame('beta', PlatformRegistry::findByStrategy(PlatformRegistry::find('beta', $this->dir)->strategy, null, $this->dir)?->id);
    }

    public function test_load_errors_name_the_file(): void
    {
        file_put_contents($this->dir . '/empty.yaml', "  \n");
        file_put_contents($this->dir . '/list.yaml', "just a string\n");
        file_put_contents($this->dir . '/bad.yaml', "id: [unclosed\n");

        foreach ([
            'empty.yaml' => "Unreadable or empty platform manifest: {$this->dir}/empty.yaml",
            'list.yaml' => "Platform manifest {$this->dir}/list.yaml does not contain a mapping",
            'bad.yaml' => "Invalid YAML in platform manifest {$this->dir}/bad.yaml: ",
        ] as $file => $message) {
            try {
                PlatformRegistry::load($this->dir . '/' . $file);
                $this->fail("{$file} loaded");
            } catch (ManifestException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }
}
