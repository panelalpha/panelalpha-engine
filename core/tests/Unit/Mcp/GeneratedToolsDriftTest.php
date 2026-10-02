<?php

namespace Tests\Unit\Mcp;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The tools under app/Mcp/Tools/Api are generated from the #[OA\...]
 * attributes. A hand edit there is undone by the next regeneration, and a
 * controller change nobody regenerated leaves clients reading stale text.
 * Either shows up here as a file that differs from a fresh generation.
 */
class GeneratedToolsDriftTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/mcp-drift-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir . '/spec'), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    public function test_the_committed_tools_match_a_fresh_generation(): void
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->dir . '/spec')]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));
        $this->assertSame(0, Artisan::call('mcp:tool:generate', [
            '--spec' => $this->dir . '/spec/api-docs.json',
            '--out' => $this->dir . '/out',
        ]));

        $fresh = base_path($this->dir . '/out');
        $stale = [];
        foreach (File::allFiles($fresh) as $file) {
            $committed = app_path('Mcp/Tools/Api/' . $file->getRelativePathname());
            if (!is_file($committed) || file_get_contents($committed) !== $file->getContents()) {
                $stale[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $stale, 'Out of date; run: php artisan l5-swagger:generate && php artisan mcp:tool:generate');
    }
}
