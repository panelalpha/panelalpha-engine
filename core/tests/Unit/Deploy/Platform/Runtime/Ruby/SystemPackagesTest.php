<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Ruby\Gemfile;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;
use PHPUnit\Framework\TestCase;

/**
 * What a Ruby image needs installed before `bundle install` can finish.
 */
class SystemPackagesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-rbpkg-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /** Gemfile is only constructible from a project, so write one. */
    private function gemfile(string $body): Gemfile
    {
        file_put_contents($this->dir . '/' . Gemfile::FILENAME, $body);

        return Gemfile::of(ProjectContext::make($this->dir, ['gemfile' => true]));
    }

    /**
     * The regression this exists to hold.
     *
     * psych is Ruby's YAML parser. It builds a native extension against
     * libyaml and, since Ruby 3.4, is no longer bundled with the interpreter —
     * so bundler resolves and compiles it. Everything that pulls in rdoc does,
     * which is railties, which is every Rails app.
     *
     * Measured on 10.10.10.25 with digitalocean/sample-rails: without
     * libyaml-dev the deploy stops at `An error occurred while installing
     * psych (5.2.6), and Bundler cannot continue`, four dependency levels away
     * from anything the Gemfile names.
     */
    public function test_libyaml_is_always_installed(): void
    {
        $packages = SystemPackages::for($this->gemfile("source 'https://rubygems.org'\ngem 'rails'\n"));

        $this->assertContains('libyaml-dev', $packages, 'psych cannot build without it, so no Rails app installs');
    }

    public function test_libyaml_is_there_even_for_a_gemfile_that_names_nothing(): void
    {
        $this->assertContains('libyaml-dev', SystemPackages::for($this->gemfile("source 'x'\n")));
    }

    public function test_a_compiler_is_always_present(): void
    {
        $packages = SystemPackages::for($this->gemfile("source 'x'\n"));

        $this->assertContains('build-essential', $packages);
    }

    public function test_the_database_client_follows_the_gemfile(): void
    {
        $this->assertContains(
            'default-libmysqlclient-dev',
            SystemPackages::for($this->gemfile("source 'x'\ngem 'mysql2'\n"))
        );
        $this->assertContains(
            'libsqlite3-dev',
            SystemPackages::for($this->gemfile("source 'x'\ngem 'sqlite3'\n"))
        );
    }

    /**
     * Postgres is the Rails 7+ default and the cheapest safe guess; an unused
     * -dev package only costs build time.
     */
    public function test_an_unrecognised_gemfile_still_gets_a_database_client(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for($this->gemfile("source 'x'\ngem 'sinatra'\n")));
    }

    /**
     * Diaspora (#147): twitter-text pulls in idn-ruby, whose extconf needs the
     * libidn headers. The Gemfile never names it; only the lock does.
     */
    public function test_a_native_gem_resolved_only_by_the_lock_gets_its_library(): void
    {
        $lock = "GEM\n  remote: https://rubygems.org/\n  specs:\n    idn-ruby (0.1.5)\n"
            . "    twitter-text (3.1.0)\n      idn-ruby\n      unf (~> 0.1.0)\n";
        file_put_contents($this->dir . '/' . Gemfile::LOCKFILE, $lock);

        $packages = SystemPackages::for($this->gemfile("source 'x'\ngem 'twitter-text', '3.1.0'\n"));

        $this->assertContains('libidn-dev', $packages);
    }

    /** A dependency line (six spaces) is not a resolved spec, and no lock means no guess. */
    public function test_a_gem_the_lock_only_mentions_as_a_dependency_is_not_resolved(): void
    {
        $this->assertNotContains('libidn-dev', SystemPackages::for($this->gemfile("source 'x'\ngem 'rails'\n")));
        $this->assertFalse(Gemfile::fromContents('', "  specs:\n    twitter-text (3.1.0)\n      idn-ruby\n")->locks('idn-ruby'));
        $this->assertTrue(Gemfile::fromContents("gem 'idn-ruby'\n")->requires('idn-ruby'));
        $this->assertContains('libidn-dev', SystemPackages::for(Gemfile::fromContents("gem 'idn-ruby'\n")));
    }
}
