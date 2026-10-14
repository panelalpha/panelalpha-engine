<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\Runtime\Ruby\FrontendStage;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyEnvironment;
use Tests\TestCase;

/**
 * What a Ruby deploy adds around the Ruby itself: the environment the
 * framework reads, and the Node stage a project with a JS frontend needs.
 *
 * The environment matters more than it looks. Without RAILS_LOG_TO_STDOUT a
 * Rails app writes its log to a file inside the container, so `docker logs`
 * is empty and a failing deploy has nothing to show; without
 * RAILS_SERVE_STATIC_FILES it 404s its own compiled assets.
 */
class RubyStagesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-rubystage-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function app(): RubyApp
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return RubyApp::at($this->dir, $files);
    }

    public function test_a_rails_app_is_told_to_log_where_docker_can_see_it(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\ngem 'rails'\n");
        $this->write('config/application.rb', 'module Shop; end');

        $env = RubyEnvironment::for($this->app());

        $this->assertSame('production', $env['RAILS_ENV']);
        $this->assertSame('1', $env['RAILS_LOG_TO_STDOUT']);
        $this->assertSame('1', $env['RAILS_SERVE_STATIC_FILES']);
    }

    public function test_a_plain_rack_app_gets_only_what_rack_reads(): void
    {
        // Setting RAILS_* on a Sinatra app is noise at best and misleading
        // in a report at worst.
        $this->write('Gemfile', "source 'https://rubygems.org'\ngem 'sinatra'\n");
        $this->write('config.ru', 'run Sinatra::Application');

        $env = RubyEnvironment::for($this->app());

        $this->assertSame(['RACK_ENV' => 'production'], $env);
    }

    public function test_a_project_with_a_frontend_build_gets_a_node_stage(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\ngem 'rails'\n");
        $this->write('config/application.rb', 'module Shop; end');
        $this->write('package.json', (string) json_encode(['scripts' => ['build' => 'vite build']]));

        $stage = FrontendStage::render($this->app());

        $this->assertStringContainsString('FROM node:', $stage);
        $this->assertStringContainsString('AS assets', $stage);
        // Invoked through the package manager, not by pasting the script's
        // body: `npm run build` picks up the project's own env and hooks.
        $this->assertStringContainsString('RUN npm run build', $stage);
    }

    public function test_the_frontend_stage_builds_for_production(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('package.json', (string) json_encode(['scripts' => ['build' => 'vite build']]));

        $stage = FrontendStage::render($this->app());

        $this->assertStringContainsString('ENV NODE_ENV=production', $stage);
        $this->assertStringContainsString('ENV RAILS_ENV=production', $stage);
    }

    public function test_a_project_with_no_build_script_gets_no_node_stage(): void
    {
        // Most Rails apps use sprockets or importmaps and need no Node at
        // all; adding the stage would put an image pull on every deploy.
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('package.json', (string) json_encode(['dependencies' => ['vite' => '^5.0']]));

        $this->assertSame('', FrontendStage::render($this->app()));
    }

    public function test_a_project_with_no_package_json_gets_no_node_stage(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');

        $this->assertSame('', FrontendStage::render($this->app()));
    }

    public function test_a_pnpm_project_does_not_run_its_own_install_scripts(): void
    {
        // pnpm runs postinstall by default; in a Rails repo those routinely
        // shell out to bundle or rails, neither of which exists in the Node
        // stage, and the build fails on a step nobody asked for.
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('package.json', (string) json_encode(['scripts' => ['build' => 'vite build']]));
        $this->write('pnpm-lock.yaml', 'lockfileVersion: 9.0');

        $stage = FrontendStage::render($this->app());

        $this->assertStringContainsString('pnpm install', $stage);
        $this->assertStringContainsString('--ignore-scripts', $stage);
    }

    public function test_the_frontend_stage_installs_before_copying_the_source(): void
    {
        // So a Ruby-only change leaves the npm install cached.
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('package.json', (string) json_encode(['scripts' => ['build' => 'vite build']]));

        $stage = FrontendStage::render($this->app());

        $this->assertLessThan(strpos($stage, 'COPY . .'), strpos($stage, 'COPY package.json ./'));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
