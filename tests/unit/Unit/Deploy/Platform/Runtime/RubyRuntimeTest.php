<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Which Ruby a project needs.
 *
 * Ruby is stricter than most about this: a Gemfile.lock resolved under 3.3
 * will refuse to install under 3.2, and native gem extensions are compiled
 * per minor. So the project's own `.ruby-version` is the first thing asked,
 * and the answer has to survive the several ways people write that file.
 */
class RubyRuntimeTest extends TestCase
{
    private string $dir = '';

    private RubyRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = new RubyRuntime();
        $this->dir = sys_get_temp_dir() . '/pa-ruby-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        // scandir rather than glob: `.ruby-version` is a dotfile, and glob
        // would leave it behind and fail the rmdir.
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    private function resolve(): ?Requirement
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return $this->runtime->resolve(ProjectContext::make($this->dir, $files));
    }

    public function test_a_project_with_no_gemfile_is_not_a_ruby_project(): void
    {
        $this->write('main.rb', 'puts "hi"');

        $this->assertNull($this->resolve());
    }

    public function test_the_projects_declared_version_is_used(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('.ruby-version', "3.4.1\n");

        $requirement = $this->resolve();

        $this->assertSame('3.4.1', $requirement->version);
        $this->assertSame('.ruby-version', $requirement->source);
        $this->assertSame('ruby:3.4.1-slim-bookworm', $this->runtime->image($requirement));
    }

    public function test_an_engine_prefixed_version_is_read(): void
    {
        // rbenv and rvm both write `ruby-3.3.0` into that file.
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('.ruby-version', "ruby-3.3.0\n");

        $this->assertSame('3.3.0', $this->resolve()->version);
    }

    public function test_a_comment_after_the_version_is_dropped(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('.ruby-version', "3.3.0 # pinned for the native gems\n");

        $this->assertSame('3.3.0', $this->resolve()->version);
    }

    public function test_the_raw_declaration_is_kept_as_the_constraint(): void
    {
        // What the project literally wrote, for the report - separate from
        // the version the engine resolved it to.
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('.ruby-version', "ruby-3.3.0\n");

        $this->assertSame('ruby-3.3.0', $this->resolve()->constraint);
    }

    public function test_a_dockerfiles_base_image_is_consulted_next(): void
    {
        // A project that pinned its Ruby in a Dockerfile rather than a
        // version file said which one it wants just as clearly.
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('Dockerfile', "FROM ruby:3.2.2-slim\nWORKDIR /app\n");

        $requirement = $this->resolve();

        $this->assertSame('3.2.2', $requirement->version);
        $this->assertStringContainsString('Dockerfile', $requirement->source);
    }

    public function test_a_version_file_beats_a_dockerfile(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('.ruby-version', '3.4.1');
        $this->write('Dockerfile', 'FROM ruby:3.2.2-slim');

        $this->assertSame('3.4.1', $this->resolve()->version);
    }

    public function test_a_dockerfile_on_another_base_says_nothing_about_ruby(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('Dockerfile', "FROM debian:bookworm\nRUN apt-get install -y ruby\n");

        $requirement = $this->resolve();

        $this->assertSame(RubyRuntime::DEFAULT_MINOR, $requirement->version);
        $this->assertSame('engine default', $requirement->source);
    }

    public function test_a_project_that_says_nothing_gets_the_engines_default(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');

        $requirement = $this->resolve();

        $this->assertSame(RubyRuntime::DEFAULT_MINOR, $requirement->version);
        $this->assertSame('', $requirement->constraint);
        $this->assertSame(RubyRuntime::IMAGE, $this->runtime->image($requirement));
    }

    public function test_the_default_is_one_the_engine_seeds_into_accounts(): void
    {
        // A default outside the seeded set is a cold image pull on every
        // Ruby deploy.
        $this->assertContains(RubyRuntime::DEFAULT_MINOR, RubyRuntime::MINORS);
    }

    public function test_an_empty_version_file_is_treated_as_absent(): void
    {
        $this->write('Gemfile', 'source "https://rubygems.org"');
        $this->write('.ruby-version', "\n");

        $this->assertSame('engine default', $this->resolve()->source);
    }

    // -------------------------------------------------------------------------
    // The fold: Ruby\RubyImage answered this same question for the deploy while
    // resolve() answered it for the manifest layer, and they disagreed four
    // ways. Each case below is one of those disagreements, pinned so the two
    // cannot drift apart again now that there is only one of them.
    // -------------------------------------------------------------------------

    /**
     * The worst of the four: rbenv and rvm both write the prefixed form, and
     * the deploy resolver did not strip it — so a project pinning `ruby-3.3.0`
     * was served `ruby:ruby-3.3.0-slim-bookworm`, a tag no registry has.
     */
    public function test_the_rbenv_prefix_is_stripped_for_the_deploy_image_too(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('.ruby-version', "ruby-3.3.0\n");

        $this->assertSame('ruby:3.3.0-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    /**
     * Huginn's Gemfile says `ruby ">=3.4.0"`, and the engine asked Docker for
     * `ruby:3.4.0-slim-bookworm` -- a tag the official image has already
     * pruned, so the deploy died at the FROM line before anything was built.
     * A floor is not a version.
     */
    public function test_a_floor_constraint_resolves_to_its_minor_line(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\nruby '>=3.4.0'\n");

        $requirement = $this->resolve();

        $this->assertSame('3.4', $requirement?->version);
        $this->assertSame('ruby:3.4-slim-bookworm', $this->runtime->image($requirement));
    }

    public function test_a_pessimistic_constraint_resolves_to_its_minor_line(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\nruby '~> 3.4.0'\n");

        $this->assertSame('3.4', $this->resolve()?->version);
    }

    /** `= 3.4.0` asks for that patch and means it. */
    public function test_an_exact_constraint_keeps_its_patch(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\nruby '= 3.4.0'\n");

        $this->assertSame('3.4.0', $this->resolve()?->version);
    }

    /** A constraint the default already satisfies is not moved at all. */
    public function test_a_satisfied_constraint_leaves_the_default_alone(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\nruby '>= 3.2'\n");

        $this->assertSame(RubyRuntime::DEFAULT_MINOR, $this->resolve()?->version);
    }

    /**
     * Rails has generated `ARG RUBY_VERSION=` since 7.1, and its FROM line then
     * names no version at all — so a resolver reading only FROM saw nothing and
     * fell back to the engine default, deploying a Rails app on a Ruby its
     * Gemfile.lock was not resolved under.
     */
    public function test_the_rails_generated_arg_is_read(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('Dockerfile', "ARG RUBY_VERSION=3.2.2\nFROM docker.io/library/ruby:\$RUBY_VERSION-slim\n");

        $requirement = $this->resolve();

        $this->assertSame('3.2.2', $requirement?->version);
        $this->assertSame('ruby:3.2.2-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    public function test_a_quoted_arg_is_read(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('Dockerfile', "ARG RUBY_VERSION=\"3.4.1\"\nFROM ruby:\$RUBY_VERSION\n");

        $this->assertSame('ruby:3.4.1-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    public function test_a_production_dockerfile_is_read_for_the_deploy_image_too(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('Dockerfile.prod', "FROM ruby:3.2.2-slim\n");

        $this->assertSame('ruby:3.2.2-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    /**
     * resolve() answers "is this project mine" and a directory with no Gemfile
     * is not a Ruby application. imageFor() answers a different question, asked
     * by callers already inside a Ruby deploy, so it always has an answer.
     */
    public function test_only_resolve_requires_a_gemfile(): void
    {
        $this->write('.ruby-version', "3.4.1\n");

        $this->assertNull($this->resolve());
        $this->assertSame('ruby:3.4.1-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    public function test_the_declared_version_wins_over_the_supported_set(): void
    {
        // 3.1 is end of life and deliberately not in minors(). Substituting a
        // version the engine prefers would break the install outright: a
        // Gemfile.lock resolved under 3.1 refuses to install under 3.3, and
        // native extensions are compiled per minor. Better an unwarmed pull.
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('.ruby-version', "3.1.3\n");

        $this->assertNotContains('3.1', RubyRuntime::minors());
        $this->assertSame('ruby:3.1.3-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    public function test_a_project_stating_nothing_gets_the_configured_default(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");

        $this->assertSame(
            'ruby:' . RubyRuntime::defaultMinor() . '-slim-bookworm',
            RubyRuntime::imageFor($this->context())
        );
    }

    /** The same context resolve() builds, so both halves see one project. */
    private function context(): ProjectContext
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return ProjectContext::make($this->dir, $files);
    }

    // -------------------------------------------------------------------------
    // The Gemfile's own `ruby` directive
    // -------------------------------------------------------------------------

    /**
     * The bug: only .ruby-version and the Dockerfile were read, so a Gemfile
     * pinning a version exactly was ignored and the project silently got the
     * engine default. bundler then refuses to install --
     * `Your Ruby version is 3.3.0, but your Gemfile specified 3.2.2`.
     */
    public function test_an_exact_gemfile_pin_is_honoured(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\nruby '3.2.2'\n");

        $requirement = $this->resolve();

        $this->assertSame('3.2.2', $requirement?->version);
        $this->assertSame('Gemfile ruby directive', $requirement?->source);
        $this->assertSame('ruby:3.2.2-slim-bookworm', RubyRuntime::imageFor($this->context()));
    }

    /**
     * A range constrains rather than dictates. heroku/ruby-getting-started
     * says `>= 3.2, < 4.0`, which the engine default already satisfies —
     * moving to the range's floor would hand the project an older Ruby than
     * either it or the engine asked for.
     */
    public function test_a_satisfied_range_leaves_the_chosen_version_alone(): void
    {
        $this->write('Gemfile', "source 'x'\nruby '>= 3.2', '< 4.0'\n");

        $this->assertSame(RubyRuntime::defaultMinor(), $this->resolve()?->version);
    }

    public function test_a_ruby_version_file_that_satisfies_the_gemfile_still_wins(): void
    {
        $this->write('Gemfile', "source 'x'\nruby '>= 3.2'\n");
        $this->write('.ruby-version', "3.4.5\n");

        $requirement = $this->resolve();

        $this->assertSame('3.4.5', $requirement?->version);
        $this->assertSame('.ruby-version', $requirement?->source);
        // Both statements are reported: showing one alone makes the other look
        // absent.
        $this->assertStringContainsString('3.4.5', (string) $requirement?->constraint);
        $this->assertStringContainsString('>= 3.2', (string) $requirement?->constraint);
    }

    /**
     * When the two disagree the Gemfile has to win, because bundler enforces
     * it and the deploy fails otherwise.
     */
    public function test_the_gemfile_overrides_a_version_file_it_forbids(): void
    {
        $this->write('Gemfile', "source 'x'\nruby '3.4.1'\n");
        $this->write('.ruby-version', "3.2\n");

        $this->assertSame('3.4.1', $this->resolve()?->version);
    }

    public function test_a_twiddle_constraint_is_honoured(): void
    {
        $this->write('Gemfile', "source 'x'\nruby '~> 3.3.0'\n");

        // `3.3` rather than `3.3.0`: both honour the constraint, and only one
        // of them is a tag Docker Hub still has. See the floor tests above.
        $this->assertSame('3.3', $this->resolve()?->version);
    }

    /**
     * A floor that is not the start of its line keeps every digit: the minor
     * line's latest patch could be below it, which would be a Ruby older than
     * the project said it could run on.
     */
    public function test_a_floor_below_its_own_line_is_not_collapsed(): void
    {
        $this->write('Gemfile', "source 'x'\nruby '~> 3.4.9'\n");

        $this->assertSame('3.4.9', $this->resolve()?->version);
    }

    /**
     * `ruby file: '.ruby-version'` is a pointer, not a constraint, and it
     * points at something already read first.
     */
    public function test_a_gemfile_deferring_to_the_version_file_changes_nothing(): void
    {
        $this->write('Gemfile', "source 'x'\nruby file: '.ruby-version'\n");
        $this->write('.ruby-version', "3.4.5\n");

        $requirement = $this->resolve();

        $this->assertSame('3.4.5', $requirement?->version);
        $this->assertSame('.ruby-version', $requirement?->source);
    }

    /**
     * resolve() and imageFor() must never disagree — that split is what
     * folding Ruby\RubyImage into this class existed to end, and the Gemfile
     * directive is exactly the kind of addition that could reopen it.
     */
    public function test_both_entry_points_agree_about_the_gemfile(): void
    {
        $this->write('Gemfile', "source 'x'\nruby '3.2.2'\n");

        $this->assertSame(
            (new RubyRuntime())->image($this->resolve()),
            RubyRuntime::imageFor($this->context())
        );
    }
}
