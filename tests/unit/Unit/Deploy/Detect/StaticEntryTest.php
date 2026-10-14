<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Detect\StaticEntry;
use PHPUnit\Framework\TestCase;

/**
 * The document a site with no build step is served from.
 *
 * The subtlety is the engine's own placeholder. It writes one into an empty
 * account, so on the next deploy that file is sitting in the checkout - and a
 * project detected as "static" by reading back the engine's own output would
 * serve the placeholder forever, which looks like a successful deploy to
 * everything except the person visiting the site.
 *
 * StaticEntryProbe covers the same ground through the platform; these are the
 * finder's own edges.
 */
class StaticEntryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-entry-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $contents = '<h1>hi</h1>'): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    private function find(): ?string
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return StaticEntry::find($this->dir, $files);
    }

    public function test_an_index_is_the_entry(): void
    {
        $this->write('index.html');

        $this->assertSame('index.html', $this->find());
    }

    public function test_index_html_is_preferred_over_index_htm(): void
    {
        // Both are real; one of them has to win, and .html is what every
        // server's own default already lists first.
        $this->write('index.html');
        $this->write('index.htm');

        $this->assertSame('index.html', $this->find());
    }

    public function test_a_single_html_file_under_another_name_is_the_entry(): void
    {
        $this->write('home.html');

        $this->assertSame('home.html', $this->find());
    }

    public function test_two_candidates_and_no_index_is_no_answer(): void
    {
        // Serving the wrong one is a deploy that succeeds and shows the wrong
        // page, which is worse than one that says it could not decide.
        $this->write('about.html');
        $this->write('contact.html');

        $this->assertNull($this->find());
    }

    public function test_a_real_page_beats_the_engines_own_placeholder(): void
    {
        $this->write('index.html', '<title>' . PlaceholderPage::WELCOME_TITLE . '</title>');
        $this->write('site.html');

        $this->assertSame('site.html', $this->find());
    }

    public function test_both_placeholder_pages_are_recognised(): void
    {
        foreach ([PlaceholderPage::WELCOME_TITLE, PlaceholderPage::NOT_CONFIGURED_TITLE] as $title) {
            $this->write('index.html', '<title>' . $title . '</title>');
            $this->write('site.html');

            $this->assertSame('site.html', $this->find(), $title);
            unlink($this->dir . '/site.html');
        }
    }

    public function test_the_placeholder_is_still_better_than_no_entry(): void
    {
        // Last resort: an account that has only ever had the placeholder
        // written into it still has something to serve.
        $this->write('index.html', '<title>' . PlaceholderPage::WELCOME_TITLE . '</title>');

        $this->assertSame('index.html', $this->find());
    }

    public function test_a_placeholder_is_not_counted_as_the_sole_html_file(): void
    {
        // If it were, a project with one real page plus the placeholder would
        // look ambiguous and get no entry at all.
        $this->write('index.html', '<title>' . PlaceholderPage::WELCOME_TITLE . '</title>');
        $this->write('page.html');
        $this->write('other.html');

        $this->assertSame('index.html', $this->find());
    }

    public function test_a_directory_named_like_a_document_is_not_one(): void
    {
        mkdir($this->dir . '/index.html');

        $this->assertNull($this->find());
    }

    public function test_a_directory_with_no_html_has_no_entry(): void
    {
        $this->write('README.md', '# nothing to serve');

        $this->assertNull($this->find());
    }

    public function test_a_trailing_slash_on_the_directory_is_tolerated(): void
    {
        $this->write('index.html');

        $this->assertSame('index.html', StaticEntry::find($this->dir . '/', ['index.html' => true]));
    }
}
