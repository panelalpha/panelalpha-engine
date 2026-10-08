<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\AppInspector;
use PHPUnit\Framework\TestCase;

/**
 * The inspection endpoint's report, built from a temp directory.
 *
 * The class has no Laravel dependencies, so this extends the plain PHPUnit
 * TestCase. What is worth asserting is not that detection works — that is
 * DetectProjectStrategyTest's job and this calls the same code — but that the
 * report says the same thing the deploy would do, and that it never carries a
 * value out of an .env file.
 */
class AppInspectorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/app-inspect-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function writeFile(string $relative, string $contents = ''): void
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public function test_it_reports_the_stack_of_a_next_app(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'shop',
            'engines' => ['node' => '>=20'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'dependencies' => ['next' => '14.2.0'],
        ]));
        $this->writeFile('pnpm-lock.yaml');
        $this->writeFile('next.config.js');

        $report = AppInspector::inspect($this->tmpDir);
        $app = $report['application'];

        $this->assertSame('nextjs', $app['strategy']);
        $this->assertSame('Next.js', $app['label']);
        $this->assertSame('node', $app['runtime']);
        $this->assertSame('pnpm', $app['package_manager']);
        $this->assertTrue($app['deployable']);
        $this->assertNull($app['issue']);
        $this->assertSame(3000, $report['ports']['primary']);
        $this->assertSame('platform', $report['ports']['source']);
        $this->assertContains('package.json', $report['files']);
        $this->assertContains('pnpm-lock.yaml', $report['files']);
    }

    public function test_it_names_the_toolchain_and_where_the_version_came_from(): void
    {
        $this->writeFile('package.json', json_encode(['dependencies' => ['express' => '4.19.0']]));
        $this->writeFile('.nvmrc', "22\n");
        $this->writeFile('index.js', 'require("express")');

        $toolchain = AppInspector::inspect($this->tmpDir)['application']['toolchain'];

        $node = null;
        foreach ($toolchain as $requirement) {
            if ($requirement['id'] === 'node') {
                $node = $requirement;
            }
        }

        $this->assertNotNull($node, 'Node should be in the toolchain of a package.json project');
        $this->assertSame('22', $node['version']);
        $this->assertSame('.nvmrc', $node['source']);
    }

    public function test_stage_commands_are_the_ones_that_will_run(): void
    {
        $this->writeFile('package.json', json_encode([
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'dependencies' => ['next' => '14.2.0'],
        ]));
        $this->writeFile('package-lock.json', '{}');

        $stages = AppInspector::inspect($this->tmpDir)['application']['stages'];

        $this->assertArrayHasKey('build', $stages);
        $runs = array_column($stages['build'], 'run');
        foreach ($runs as $run) {
            $this->assertStringNotContainsString('{{', $run, 'A stage command still carries a placeholder');
        }
        $this->assertStringContainsString('npm ci', implode(' ', $runs));
    }

    public function test_it_reports_compose_services_and_ports(): void
    {
        $this->writeFile('docker-compose.yml', <<<YAML
        services:
          web:
            image: myapp:latest
            ports:
              - "8080:8080"
          db:
            image: postgres:16
            ports:
              - "5432:5432"
        YAML);

        $report = AppInspector::inspect($this->tmpDir);

        $this->assertSame('compose', $report['application']['strategy']);
        $this->assertSame('docker-compose.yml', $report['application']['compose_file']);
        $this->assertContains(8080, $report['ports']['compose']);
        $this->assertNotContains(5432, $report['ports']['compose'], 'A database port is not the app port');

        $byName = [];
        foreach ($report['services'] as $service) {
            $byName[$service['name']] = $service;
        }
        $this->assertSame('datastore', $byName['db']['role']);
        $this->assertSame('postgres', $byName['db']['engine']);
        $this->assertSame('compose', $byName['db']['origin']);
        $this->assertSame('application', $byName['web']['role']);
    }

    public function test_it_reports_the_datastores_only_an_env_file_admits_to(): void
    {
        $this->writeFile('package.json', json_encode(['dependencies' => ['express' => '4.19.0']]));
        $this->writeFile('index.js', '');
        $this->writeFile('.env.example', "DATABASE_URL=postgres://app:secret@localhost:5432/app\nREDIS_URL=redis://localhost:6379\n");

        $services = AppInspector::inspect($this->tmpDir)['services'];

        $engines = array_column($services, 'engine');
        $this->assertContains('postgres', $engines);
        $this->assertContains('redis', $engines);
        foreach ($services as $service) {
            $this->assertSame('env', $service['origin']);
        }
    }

    public function test_it_reports_env_variable_names_and_never_their_values(): void
    {
        $this->writeFile('index.html', '<h1>hi</h1>');
        $this->writeFile('.env.example', "APP_KEY=base64:supersecret\nSTRIPE_SECRET=sk_live_do_not_leak\n");

        $report = AppInspector::inspect($this->tmpDir);

        $this->assertSame(['.env.example'], $report['environment']['files']);
        $this->assertSame(['APP_KEY', 'STRIPE_SECRET'], $report['environment']['variables']);
        $this->assertStringNotContainsString('sk_live_do_not_leak', json_encode($report));
        $this->assertStringNotContainsString('supersecret', json_encode($report));
    }

    public function test_an_empty_directory_is_reported_rather_than_thrown(): void
    {
        $report = AppInspector::inspect($this->tmpDir);

        $this->assertFalse($report['application']['deployable']);
        $this->assertNotNull($report['application']['issue']);
        $this->assertSame([], $report['files']);
    }

    /**
     * The YAML app config is the format the engine reads first, so an inspection
     * that only knew the markdown page would report "no app config" for a project
     * that has one — and then predict a deploy that is not going to happen.
     */
    public function test_a_yaml_app_config_is_found_like_the_deploy_finds_it(): void
    {
        $this->writeFile('package.json', json_encode(['dependencies' => ['next' => '14.2.0']]));
        $this->writeFile('panelalpha.yaml', "description: demo\nenv:\n  DEMO: '1'\n");

        $app = AppInspector::inspect($this->tmpDir)['application'];

        $this->assertSame('repository', $app['app_config']);
    }

    /**
     * `platform:` settles detection at deploy time. The report has to follow it
     * rather than re-deciding from the files, or it describes a deploy the
     * engine will not run.
     */
    public function test_an_app_config_command_appears_in_the_stage_it_runs_in(): void
    {
        $this->writeFile('package.json', json_encode([
            'scripts' => ['build' => 'next build'],
            'dependencies' => ['next' => '14.2.0'],
        ]));
        $this->writeFile('package-lock.json', '{}');
        $this->writeFile('panelalpha.yaml', <<<YAML
        commands:
          - id: fetch-assets
            stage: prepare
            run: 'curl -sSf https://example.test/assets.tar.gz | tar xz'
        YAML);

        $stages = AppInspector::inspect($this->tmpDir)['application']['stages'];

        $this->assertArrayHasKey('prepare', $stages, 'An app config prepare command is part of the deploy');
        $this->assertSame(['fetch-assets'], array_column($stages['prepare'], 'id'));
        // The manifest's own build schedule is still there alongside it.
        $this->assertArrayHasKey('build', $stages);
    }

    public function test_the_dockerfile_expose_port_is_reported_next_to_the_platform_port(): void
    {
        $this->writeFile('Dockerfile', "FROM node:20\nEXPOSE 8081\nCMD [\"node\", \"server.js\"]\n");

        $report = AppInspector::inspect($this->tmpDir);

        $this->assertSame('dockerfile', $report['application']['strategy']);
        $this->assertSame('Dockerfile', $report['application']['dockerfile']);
        $this->assertSame(8081, $report['ports']['dockerfile_expose']);
    }

    public function test_it_reports_which_application_a_laravel_project_is(): void
    {
        $this->writeFile('composer.json', json_encode([
            'name' => 'acme/shop',
            'description' => 'Storefront',
            'require' => ['php' => '^8.3', 'laravel/framework' => '^11.0'],
            'scripts' => ['post-autoload-dump' => 'x'],
        ]));
        $this->writeFile('composer.lock', json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => 'v11.31.0']],
        ]));
        $this->writeFile('artisan');
        $this->writeFile('package.json', json_encode([
            'name' => 'shop-web',
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^6.0.0'],
        ]));

        $report = AppInspector::inspect($this->tmpDir);

        // `application` says how the engine will deploy this; `metadata` says
        // which application it is. Both are true and neither implies the other.
        $this->assertSame('laravel', $report['application']['platform']);

        $metadata = $report['metadata'];
        $this->assertSame('acme/shop', $metadata['name']);
        $this->assertSame('Storefront', $metadata['description']);
        $this->assertSame('composer.json', $metadata['source']);
        $this->assertSame('Laravel', $metadata['framework']['name']);
        $this->assertSame('11.31.0', $metadata['framework']['version']);

        // The asset pipeline is real and is reported, but it is not what the
        // application is.
        $this->assertSame(['php', 'node'], array_column($metadata['packages'], 'ecosystem'));
        $this->assertSame('shop-web', $metadata['packages'][1]['name']);
    }

    public function test_metadata_never_carries_a_script_body(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'shop',
            'scripts' => ['deploy' => 'curl -H "Authorization: Bearer hunter2" https://deploy.test'],
        ]));

        $report = AppInspector::inspect($this->tmpDir);

        $this->assertSame(['deploy'], $report['metadata']['packages'][0]['scripts']);
        $this->assertStringNotContainsString('hunter2', json_encode($report));
    }

    public function test_a_project_with_no_package_files_reports_empty_metadata(): void
    {
        $this->writeFile('index.html', '<h1>hi</h1>');

        $report = AppInspector::inspect($this->tmpDir);

        $this->assertNull($report['metadata']['name']);
        $this->assertSame([], $report['metadata']['packages']);
    }
}
