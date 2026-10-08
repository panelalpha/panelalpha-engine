<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\MarkerFiles;

/**
 * The root files the report names back. The list decides what a reader is
 * told the stack is, so what it leaves out matters as much as what it finds.
 */
class MarkerFilesTest extends ReportTestCase
{
    public function test_it_names_the_marker_files_that_are_present(): void
    {
        $this->write('composer.json', '{}');
        $this->write('artisan');
        $this->write('README.md');

        $found = MarkerFiles::presentIn($this->tmpDir);

        $this->assertContains('composer.json', $found);
        $this->assertContains('artisan', $found);
        $this->assertNotContains('README.md', $found, 'only stack-deciding files are reported');
    }

    public function test_it_reports_them_in_the_declared_order_not_the_directory_order(): void
    {
        $this->write('Dockerfile');
        $this->write('package.json', '{}');

        // package.json is declared first, and is listed first however the
        // filesystem happened to hand the entries back.
        $this->assertSame(['package.json', 'Dockerfile'], MarkerFiles::presentIn($this->tmpDir));
    }

    /**
     * The class documents itself as case-sensitive, and that is load-bearing:
     * `gemfile` is not a Gemfile, and reporting it as one would name a Ruby
     * stack for a project that has none.
     */
    public function test_a_marker_spelled_differently_is_not_a_marker(): void
    {
        $this->write('gemfile', 'source "https://rubygems.org"');

        $this->assertSame([], MarkerFiles::presentIn($this->tmpDir));
    }

    public function test_a_directory_named_like_a_marker_is_not_one(): void
    {
        mkdir($this->tmpDir . '/Dockerfile');

        $this->assertSame([], MarkerFiles::presentIn($this->tmpDir));
    }

    public function test_a_trailing_slash_on_the_project_dir_changes_nothing(): void
    {
        $this->write('go.mod', 'module x');

        $this->assertSame(['go.mod'], MarkerFiles::presentIn($this->tmpDir . '/'));
    }

    public function test_an_empty_directory_reports_nothing(): void
    {
        $this->assertSame([], MarkerFiles::presentIn($this->tmpDir));
    }
}
