<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Platform\Probes\StaticEntryProbe;

/**
 * Which document nginx is pointed at for a site with no build step.
 *
 * The interesting case is the engine's own placeholder page. The engine
 * writes one into empty accounts, so a project that ships nothing would
 * otherwise be "detected" as a static site by reading back the engine's own
 * output — a real index anywhere in the root has to beat it.
 */
class StaticEntryProbeTest extends ProbeTestCase
{
    private function probe(): StaticEntryProbe
    {
        return new StaticEntryProbe();
    }

    public function test_a_root_index_is_the_entry(): void
    {
        $this->write('index.html', '<h1>hello</h1>');

        $this->assertSame(['static_index' => 'index.html'], $this->probe()->evaluate($this->context()));
    }

    public function test_index_htm_counts_too(): void
    {
        $this->write('index.htm', '<h1>hello</h1>');

        $this->assertSame(['static_index' => 'index.htm'], $this->probe()->evaluate($this->context()));
    }

    public function test_a_directory_with_no_html_is_not_a_static_site(): void
    {
        $this->write('README.md', '# nothing to serve');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_lone_html_file_is_the_entry_even_when_not_named_index(): void
    {
        $this->write('home.html', '<h1>hello</h1>');

        $this->assertSame(['static_index' => 'home.html'], $this->probe()->evaluate($this->context()));
    }

    public function test_several_non_index_html_files_are_ambiguous(): void
    {
        // Two candidates and no index: picking either would be a guess, and
        // serving the wrong one is a working deploy of the wrong page.
        $this->write('about.html', '<h1>about</h1>');
        $this->write('contact.html', '<h1>contact</h1>');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_real_page_beats_the_engines_placeholder(): void
    {
        $this->write('index.html', '<title>' . PlaceholderPage::WELCOME_TITLE . '</title>');
        $this->write('site.html', '<h1>the actual site</h1>');

        $this->assertSame(['static_index' => 'site.html'], $this->probe()->evaluate($this->context()));
    }

    public function test_the_placeholder_alone_is_still_an_entry(): void
    {
        // Last resort. Nothing else is there to serve, and reporting no entry
        // at all would fail deployability instead of serving the page the
        // engine itself just wrote.
        $this->write('index.html', '<title>' . PlaceholderPage::NOT_CONFIGURED_TITLE . '</title>');

        $this->assertSame(['static_index' => 'index.html'], $this->probe()->evaluate($this->context()));
    }

    public function test_a_directory_named_index_html_is_not_a_document(): void
    {
        mkdir($this->dir . '/index.html');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }
}
