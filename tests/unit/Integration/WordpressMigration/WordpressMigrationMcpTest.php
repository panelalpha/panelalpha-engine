<?php

namespace Tests\Integration\WordpressMigration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * tool-names.php lists the migration operations, and a dry-run of
 * mcp:tool:generate accepts that map against the generated OpenAPI document.
 */
class WordpressMigrationMcpTest extends WordpressMigrationTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/wp-migration-mcp-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    public function test_dry_run_covers_the_migration_operations(): void
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->dir)]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));
        $specPath = base_path($this->dir . '/api-docs.json');

        /** @var mixed $spec */
        $spec = json_decode((string) file_get_contents($specPath), true);
        $this->assertIsArray($spec);
        $paths = $spec['paths'] ?? null;
        $this->assertIsArray($paths);
        $this->assertArrayHasKey('/wordpress/migrations', $paths);
        $this->assertArrayHasKey('post', $paths['/wordpress/migrations']);
        $this->assertArrayHasKey('/wordpress/migrations/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/wordpress/migrations/{id}']);
        $this->assertArrayHasKey('/wordpress/migrations/{id}/resume', $paths);
        $this->assertArrayHasKey('post', $paths['/wordpress/migrations/{id}/resume']);
        $this->assertArrayHasKey('post', $paths['/wordpress/migrations/{id}/cancel'] ?? []);
        $this->assertArrayHasKey('delete', $paths['/wordpress/migrations/{id}']);
        $this->assertArrayHasKey('post', $paths['/wordpress/migrations/test'] ?? []);
        $this->assertArrayHasKey('post', $paths['/wordpress/migrations/plugin-package'] ?? []);
        $this->assertArrayNotHasKey('/wordpress/migrations/plugin-package/download', $paths);

        /** @var array<string, string> $map */
        $map = require base_path('app/Mcp/tool-names.php');
        $this->assertArrayHasKey('POST /wordpress/migrations', $map);
        $this->assertArrayHasKey('GET /wordpress/migrations/{id}', $map);
        $this->assertArrayHasKey('POST /wordpress/migrations/{id}/resume', $map);
        $this->assertSame('wordpress_migration_cancel', $map['POST /wordpress/migrations/{id}/cancel'] ?? null);
        $this->assertSame('wordpress_migration_delete', $map['DELETE /wordpress/migrations/{id}'] ?? null);
        $this->assertSame('wordpress_migration_test', $map['POST /wordpress/migrations/test'] ?? null);
        $this->assertSame('wordpress_migration_package_create', $map['POST /wordpress/migrations/plugin-package'] ?? null);

        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationCreateTool.php'));
        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationGetTool.php'));
        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationResumeTool.php'));
        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationCancelTool.php'));
        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationDeleteTool.php'));
        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationTestTool.php'));
        $this->assertFileExists(app_path('Mcp/Tools/Api/WordPressMigrations/WordpressMigrationPackageCreateTool.php'));

        $exit = Artisan::call('mcp:tool:generate', ['--dry-run' => true, '--spec' => $this->dir . '/api-docs.json']);
        $this->assertSame(
            0,
            $exit,
            'Dry-run must accept the map once the migration operations are in the spec. ' . Artisan::output()
        );
    }
}
