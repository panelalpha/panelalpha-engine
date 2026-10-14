<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Platform\Runtime\Ruby\Gemfile;
use PHPUnit\Framework\TestCase;

/**
 * A gem in the Gemfile is not a gem in the image.
 *
 * The image is built with `BUNDLE_WITHOUT="development:test"` -- in
 * ruby.yaml and in the Dockerfile template -- so a gem declared only in one
 * of those groups is named in the Gemfile and installed nowhere.
 *
 * Redmine puts `gem 'puma'` inside `group :test`. The engine asked only
 * "does the Gemfile mention puma", chose it as the start command, and the
 * container answered
 *
 *     bundler: command not found: puma
 *     Install missing gem executables with `bundle install`
 *
 * ten times in the deploy log, exit 127, restarting forever. Picking a
 * server to exec is exactly the decision that needs the stricter question.
 */
class RubyServerGroupTest extends TestCase
{
    /** Redmine's shape, reduced to the line that matters. */
    private const REDMINE = <<<'RUBY'
        source 'https://rubygems.org'

        gem 'rails', '~> 7.2.0'
        gem 'rack', '~> 3.1.0'

        group :test do
          gem 'rails-dom-testing'
          gem 'puma'
          gem 'simplecov', '~> 0.22.0', require: false
        end
        RUBY;

    public function test_a_gem_only_in_the_test_group_is_not_available(): void
    {
        $gemfile = Gemfile::fromContents(self::REDMINE);

        $this->assertTrue($gemfile->requires('puma'), 'the Gemfile does mention it');
        $this->assertFalse(
            $gemfile->requiresAtRuntime('puma'),
            'BUNDLE_WITHOUT excludes :test, so it is not in the image'
        );
    }

    /** An ordinary top-level gem is unaffected. */
    public function test_an_ungrouped_gem_is_available(): void
    {
        $gemfile = Gemfile::fromContents(self::REDMINE);

        $this->assertTrue($gemfile->requiresAtRuntime('rails'));
        $this->assertTrue($gemfile->requiresAtRuntime('rack'));
    }

    /** So is one in a group that does get installed. */
    public function test_a_gem_in_an_installed_group_is_available(): void
    {
        $gemfile = Gemfile::fromContents("group :production do\n  gem 'puma'\nend\n");

        $this->assertTrue($gemfile->requiresAtRuntime('puma'));
    }

    /** The inline option spelling, both singular and plural. */
    public function test_the_inline_group_option_is_honoured(): void
    {
        $this->assertFalse(
            (Gemfile::fromContents("gem 'puma', group: :test\n"))->requiresAtRuntime('puma')
        );
        $this->assertFalse(
            (Gemfile::fromContents("gem 'puma', groups: [:development, :test]\n"))->requiresAtRuntime('puma')
        );
        $this->assertTrue(
            (Gemfile::fromContents("gem 'puma', groups: [:test, :production]\n"))->requiresAtRuntime('puma'),
            'one installed group is enough'
        );
    }

    /**
     * Declared twice -- once restricted, once not -- is installed. The
     * unrestricted line is the one that puts it in the bundle.
     */
    public function test_a_gem_declared_both_ways_is_available(): void
    {
        $gemfile = Gemfile::fromContents("gem 'puma'\n\ngroup :test do\n  gem 'puma'\nend\n");

        $this->assertTrue($gemfile->requiresAtRuntime('puma'));
    }

    /** A gem nowhere in the file is still absent. */
    public function test_an_absent_gem_stays_absent(): void
    {
        $this->assertFalse((Gemfile::fromContents(self::REDMINE))->requiresAtRuntime('falcon'));
    }

    /** Nested groups close correctly rather than swallowing what follows. */
    public function test_a_gem_after_a_closed_group_is_available(): void
    {
        $gemfile = Gemfile::fromContents("group :test do\n  gem 'rspec'\nend\n\ngem 'puma'\n");

        $this->assertTrue($gemfile->requiresAtRuntime('puma'));
    }
}
