<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\Ruby\RailsProxy;
use App\Lib\Deploy\Platform\Runtime\Ruby\RailsSecret;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyDockerfile;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyServer;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;
use PHPUnit\Framework\TestCase;

class RubyDockerfileTest extends TestCase
{
    public function test_rails_host_initializer_allows_only_public_host(): void
    {
        $ruby = RailsProxy::hostAuthorizationInitializer('https://app.example.test/path');

        $this->assertStringContainsString("hosts << 'app.example.test'", $ruby);
        // An empty config.hosts means "any Host" in production; appending to it
        // would narrow the app to a single name.
        $this->assertStringContainsString('unless hosts.empty?', $ruby);
        $this->assertStringNotContainsString('config.hosts.clear', $ruby);
        $this->assertStringNotContainsString('/.+/', $ruby);
    }

    public function test_rails_behind_the_proxy_does_not_pin_hsts_on_a_self_signed_cert(): void
    {
        $this->assertSame(
            ['RAILS_FORCE_SSL' => 'false', 'RAILS_ASSUME_SSL' => 'true'],
            RailsProxy::sslEnvironment('https://app.example.test')
        );
        $this->assertSame(
            ['RAILS_FORCE_SSL' => 'false', 'RAILS_ASSUME_SSL' => 'false'],
            RailsProxy::sslEnvironment('http://app.example.test')
        );
        $this->assertSame(
            ['RAILS_FORCE_SSL' => 'false', 'RAILS_ASSUME_SSL' => 'false'],
            RailsProxy::sslEnvironment(null)
        );
    }

    public function test_generates_vite_build_without_bin_vite_or_rails_precompile(): void
    {
        $dir = $this->projectDir([
            '.ruby-version' => "4.0.1\n",
            'Gemfile' => "source 'https://rubygems.org'\ngem 'falcon'\n",
            'Gemfile.lock' => "GEM\n",
            'package.json' => (string) json_encode([
                'scripts' => ['build' => 'vite build --outDir public'],
            ]),
            'pnpm-lock.yaml' => 'lockfileVersion: 9',
            'config/application.rb' => "module Demo\nclass Application < Rails::Application\nend\nend\n",
        ]);

        $docker = RubyDockerfile::generate($dir, ['gemfile' => true, 'package.json' => true, 'pnpm-lock.yaml' => true]);

        $this->assertStringContainsString('FROM ruby:4.0.1-slim-bookworm AS base', $docker);
        $this->assertStringContainsString('FROM node:22-bookworm-slim AS assets', $docker);
        $this->assertStringContainsString('pnpm run build', $docker);
        $this->assertStringContainsString('--ignore-scripts', $docker);
        $this->assertStringNotContainsString('assets:precompile', $docker);
        $this->assertStringNotContainsString('bin/vite', $docker);
        $this->assertStringContainsString('"bundle","exec","falcon","host"', $docker);

        // NODE_ENV=production must not precede install (skips Vite in
        // devDependencies → "vite: not found"). It belongs only on the build.
        $installPos = strpos($docker, 'pnpm install');
        $nodeEnvPos = strpos($docker, 'NODE_ENV=production');
        $buildPos = strpos($docker, 'pnpm run build');
        $this->assertNotFalse($installPos);
        $this->assertNotFalse($nodeEnvPos);
        $this->assertNotFalse($buildPos);
        $this->assertGreaterThan($installPos, $nodeEnvPos);
        $this->assertGreaterThan($nodeEnvPos, $buildPos);
    }

    /** Production Rails does not compile assets on the fly; the image has to carry them. */
    public function test_a_rails_app_on_the_asset_pipeline_precompiles_its_assets(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails', '~> 7.2'\ngem 'sprockets-rails'\n",
            'config/application.rb' => "module App; end\n",
        ]);

        $dockerfile = RubyDockerfile::generate($dir, ['gemfile' => true], 3000);

        $this->assertStringContainsString('RUN SECRET_KEY_BASE_DUMMY=1 bundle exec rails assets:precompile', $dockerfile);
        // After the source is in place, and not fatal for an app that cannot boot without its database.
        $this->assertGreaterThan(strpos($dockerfile, 'COPY . .'), strpos($dockerfile, 'assets:precompile'));
        $this->assertStringContainsString('|| echo "PANELALPHA: rails assets:precompile failed', $dockerfile);
    }

    /** Rails 6/7.0 pull sprockets-rails in through the rails gem, so only the lockfile names it. */
    public function test_the_asset_pipeline_is_also_found_in_the_lockfile(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails', '~> 7.0'\n",
            'Gemfile.lock' => "GEM\n  specs:\n    rails (7.0.8)\n      sprockets-rails (>= 2.0.0)\n    sprockets-rails (3.4.2)\n",
            'config/application.rb' => "module App; end\n",
        ]);
        $withPipeline = RubyDockerfile::generate($dir, ['gemfile' => true], 3000);

        $apiOnly = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails', '~> 8.0'\n",
            'config/application.rb' => "module App; end\n",
        ]);

        $this->assertStringContainsString('assets:precompile', $withPipeline);
        $this->assertStringNotContainsString('assets:precompile', RubyDockerfile::generate($apiOnly, ['gemfile' => true], 3000));
    }

    public function test_is_rails_app_requires_gemfile_and_application_rb(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'config/application.rb' => "module X\nclass Application < Rails::Application\nend\nend\n",
        ]);

        $this->assertTrue(RubyApp::at($dir, ['gemfile' => true])->isRails());

        $withoutApp = $this->projectDir(['Gemfile' => "source 'https://rubygems.org'\n"]);
        $this->assertFalse(RubyApp::at($withoutApp, ['gemfile' => true])->isRails());
    }

    public function test_fallback_rails_server_uses_detected_port(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails'\n",
            'config/application.rb' => "module X\nclass Application < Rails::Application\nend\nend\n",
        ]);

        $docker = RubyDockerfile::generate($dir, ['gemfile' => true], 8080);

        $this->assertStringContainsString('EXPOSE 8080', $docker);
        $this->assertStringContainsString('"-p","8080"', $docker);
    }

    /** Bundler reads `ruby file: ".ruby-version"` while parsing the Gemfile, before the source is copied. */
    public function test_version_files_are_in_place_before_bundle_install(): void
    {
        $dir = $this->projectDir([
            '.ruby-version' => "3.3.6\n",
            'Gemfile' => "source 'https://rubygems.org'\nruby file: \".ruby-version\"\ngem 'rails'\n",
            'config/application.rb' => "module App; end\n",
        ]);

        $dockerfile = RubyDockerfile::generate($dir, ['gemfile' => true], 3000);

        $copy = strpos($dockerfile, 'COPY .ruby-version* .tool-versions* ./');
        $this->assertNotFalse($copy);
        $this->assertLessThan(strpos($dockerfile, 'RUN bundle install'), $copy);
    }

    public function test_deployment_mode_only_when_a_lockfile_is_present(): void
    {
        $withLock = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'Gemfile.lock' => "GEM\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);
        $withoutLock = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);

        // BUNDLE_DEPLOYMENT without a lockfile makes `bundle install` abort.
        $this->assertStringContainsString('BUNDLE_DEPLOYMENT=1', RubyDockerfile::generate($withLock, ['gemfile' => true]));
        $this->assertStringNotContainsString('BUNDLE_DEPLOYMENT', RubyDockerfile::generate($withoutLock, ['gemfile' => true]));
    }

    public function test_serves_static_files_so_the_copied_public_dir_is_reachable(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);

        $this->assertStringContainsString(
            'RAILS_SERVE_STATIC_FILES=1',
            RubyDockerfile::generate($dir, ['gemfile' => true])
        );
    }

    public function test_installs_the_client_library_of_the_declared_database_gem(): void
    {
        $mysql = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'mysql2'\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);
        $sqlite = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'sqlite3'\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);
        $none = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails'\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);

        $this->assertContains('default-libmysqlclient-dev', SystemPackages::for(RubyApp::at($mysql)->gemfile()));
        $this->assertNotContains('libpq-dev', SystemPackages::for(RubyApp::at($mysql)->gemfile()));
        $this->assertContains('libsqlite3-dev', SystemPackages::for(RubyApp::at($sqlite)->gemfile()));
        $this->assertContains('libpq-dev', SystemPackages::for(RubyApp::at($none)->gemfile()));
    }

    /**
     * we-promise/sure ships credentials.yml.enc but not master.key, which is
     * gitignored — so the clone holds ciphertext it cannot decrypt and Rails
     * still aborts. Only the key, or an explicitly supplied secret, counts.
     */
    public function test_encrypted_credentials_alone_do_not_supply_a_secret(): void
    {
        $withCredentials = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'config/application.rb' => "module Demo\nend\n",
            'config/credentials.yml.enc' => "ciphertext\n",
        ]);

        $this->assertTrue(RailsSecret::mustBeGenerated($withCredentials));
    }

    public function test_a_master_key_in_the_repo_settles_it(): void
    {
        $withKey = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'config/application.rb' => "module Demo\nend\n",
            'config/master.key' => "0123456789abcdef\n",
        ]);

        $this->assertFalse(RailsSecret::mustBeGenerated($withKey));
    }

    public function test_a_key_the_customer_supplied_outranks_the_generated_one(): void
    {
        $bare = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'config/application.rb' => "module Demo\nend\n",
        ]);

        $this->assertTrue(RailsSecret::mustBeGenerated($bare));
        $this->assertFalse(RailsSecret::mustBeGenerated($bare, ['RAILS_MASTER_KEY' => 'abc']));
        $this->assertFalse(RailsSecret::mustBeGenerated($bare, ['SECRET_KEY_BASE' => 'xyz']));
        $this->assertTrue(RailsSecret::mustBeGenerated($bare, ['RAILS_MASTER_KEY' => '  ']));
    }

    /**
     * @param array<string, string> $files
     */
    public function test_a_rack_app_is_claimed_even_without_rails(): void
    {
        $sinatra = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'sinatra'\n",
            'config.ru' => "run Sinatra::Application\n",
        ]);

        $this->assertTrue(RubyApp::at($sinatra, ['gemfile' => true, 'config.ru' => true])->isDeployable());
        $this->assertFalse(RubyApp::at($sinatra, ['gemfile' => true])->isRails());
    }

    public function test_a_procfile_app_is_claimed_and_keeps_its_own_command(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\n",
            'Procfile' => "web: bundle exec ruby server.rb -p \$PORT\nworker: bundle exec rake jobs:work\n",
        ]);

        $this->assertTrue(RubyApp::at($dir, ['gemfile' => true, 'procfile' => true])->isDeployable());
        $this->assertSame('bundle exec ruby server.rb -p $PORT', RubyApp::at($dir)->webCommand());
        $this->assertStringContainsString('server.rb', RubyServer::command(RubyApp::at($dir), 3000));
    }

    /**
     * A Gemfile we cannot work out how to start is better left to Railpack
     * than turned into a container that builds and exits.
     */
    public function test_a_gemfile_with_no_way_to_start_is_left_to_railpack(): void
    {
        $libraryOnly = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngemspec\n",
        ]);

        $this->assertFalse(RubyApp::at($libraryOnly, ['gemfile' => true])->isDeployable());
    }

    public function test_no_gemfile_is_never_a_ruby_app(): void
    {
        $dir = $this->projectDir(['config.ru' => "run ->(env) {}\n"]);

        $this->assertFalse(RubyApp::at($dir, ['config.ru' => true])->isDeployable());
    }

    public function test_rack_app_starts_with_rackup_not_rails_server(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'sinatra'\n",
            'config.ru' => "run Sinatra::Application\n",
        ]);

        $start = RubyServer::command(RubyApp::at($dir), 3000);

        $this->assertStringContainsString('rackup', $start);
        $this->assertStringNotContainsString('rails', $start);
        $this->assertStringContainsString('0.0.0.0', $start);
    }

    /**
     * `-C config/puma.rb` is a Rails convention; puma exits if pointed at a
     * config file that is not there.
     */
    public function test_puma_without_a_config_file_binds_directly(): void
    {
        $rack = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'puma'\n",
            'config.ru' => "run ->(env) {}\n",
        ]);

        $this->assertStringContainsString('tcp://0.0.0.0:3000', RubyServer::command(RubyApp::at($rack), 3000));
        $this->assertStringNotContainsString('config/puma.rb', RubyServer::command(RubyApp::at($rack), 3000));

        $withConfig = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'puma'\n",
            'config/puma.rb' => "port 3000\n",
            'config.ru' => "run ->(env) {}\n",
        ]);

        $this->assertStringContainsString('config/puma.rb', RubyServer::command(RubyApp::at($withConfig), 3000));
    }

    public function test_rack_app_gets_rack_env_and_no_rails_env(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'sinatra'\n",
            'config.ru' => "run Sinatra::Application\n",
        ]);

        $dockerfile = RubyDockerfile::generate($dir, ['gemfile' => true, 'config.ru' => true], 3000);

        $this->assertStringContainsString('RACK_ENV=production', $dockerfile);
        $this->assertStringNotContainsString('RAILS_ENV', $dockerfile);
    }

    public function test_rails_app_keeps_its_rails_environment(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'rails'\n",
            'config/application.rb' => "module App; end\n",
        ]);

        $dockerfile = RubyDockerfile::generate($dir, ['gemfile' => true], 3000);

        $this->assertStringContainsString('RAILS_ENV=production', $dockerfile);
        $this->assertStringContainsString('RAILS_SERVE_STATIC_FILES=1', $dockerfile);
        $this->assertStringNotContainsString('RACK_ENV', $dockerfile);
    }

    /**
     * The whole point of claiming these: the image comes from the seeded
     * ruby tag instead of mise compiling ruby from source.
     */
    public function test_a_rack_app_builds_on_the_seeded_ruby_image(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'sinatra'\n",
            '.ruby-version' => "3.3.6\n",
            'config.ru' => "run Sinatra::Application\n",
        ]);

        $dockerfile = RubyDockerfile::generate($dir, ['gemfile' => true, 'config.ru' => true], 3000);

        $this->assertStringContainsString('FROM ruby:3.3.6-slim-bookworm', $dockerfile);
        $this->assertStringContainsString('bundle install', $dockerfile);
        $this->assertStringNotContainsString('mise', $dockerfile);
    }

    public function test_a_prebuilt_base_replaces_the_apt_layer(): void
    {
        $dir = $this->projectDir([
            'Gemfile' => "source 'https://rubygems.org'\ngem 'sinatra'\n",
            'config.ru' => "run Sinatra::Application\n",
        ]);
        $files = ['gemfile' => true, 'config.ru' => true];

        $stock = RubyDockerfile::generate($dir, $files, 3000);
        $prebuilt = RubyDockerfile::generate($dir, $files, 3000, 'panelalpha/ruby:3.3.6-slim-bookworm-pa1234abcd');

        $this->assertStringContainsString('apt-get install', $stock);
        $this->assertStringContainsString('FROM panelalpha/ruby:3.3.6-slim-bookworm-pa1234abcd', $prebuilt);
        $this->assertStringNotContainsString('apt-get install', $prebuilt);
        // The rest of the build is unchanged.
        $this->assertStringContainsString('bundle install', $prebuilt);
        $this->assertStringContainsString('BUNDLE_PATH=/usr/local/bundle', $prebuilt);
    }

    private function projectDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/pa-rails-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        mkdir($dir . '/config', 0777, true);
        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($path, $contents);
        }
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            $this->deleteTree($dir);
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
