<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Detect\HtmlSite;
use App\Lib\Deploy\Detect\PlaceholderPage;
use PHPUnit\Framework\TestCase;

/**
 * A site of documents, and which document is its front page.
 *
 * The case this exists for is the one nothing claimed: `home.html`,
 * `about.html`, `contact.html` and a `css/` directory, with no index. It used
 * to reach the fallback recipe and be buried under the engine's own "Project
 * not ready yet" page while every file it needed was already on disk.
 *
 * The other half of the class is the refusal. It is an allowlist, so what
 * needs asserting is that one stray program disqualifies a tree however much
 * HTML surrounds it.
 */
class HtmlSiteTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-html-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    private function write(string $relative, string $contents = '<h1>hi</h1>'): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function entry(): ?string
    {
        return HtmlSite::entry($this->dir);
    }

    public function test_a_hand_written_site_with_no_index_is_served_from_its_home_page(): void
    {
        $this->write('home.html');
        $this->write('about.html');
        $this->write('contact.html');
        $this->write('css/site.css', 'body{}');
        $this->write('img/logo.png', 'x');

        $this->assertSame('home.html', $this->entry());
    }

    /**
     * The names are tried in order, so a site carrying both is served from
     * the one a person would have clicked.
     */
    public function test_index_outranks_the_other_conventional_names(): void
    {
        $this->write('start.html');
        $this->write('home.html');
        $this->write('index.html');

        $this->assertSame('index.html', $this->entry());
    }

    public function test_nothing_is_named_like_a_front_page_so_the_first_document_is_it(): void
    {
        $this->write('alpha.html');
        $this->write('beta.html');

        $this->assertSame('alpha.html', $this->entry());
    }

    /**
     * A site that lives one directory down is still a site. `index docs/…`
     * is not something nginx would have found on its own, which is the whole
     * reason the entry is reported rather than assumed.
     */
    public function test_a_site_kept_in_a_subdirectory_is_found(): void
    {
        $this->write('dist/index.html');
        $this->write('dist/app.css', 'body{}');

        $this->assertSame('dist/index.html', $this->entry());
    }

    /**
     * Root wins over depth even when the deeper file has the better name: a
     * page at the root is the site's entrance, and `docs/index.html` is one
     * of its pages.
     */
    public function test_a_root_page_outranks_a_better_named_one_below_it(): void
    {
        $this->write('welcome.html');
        $this->write('docs/index.html');

        $this->assertSame('welcome.html', $this->entry());
    }

    public function test_one_program_disqualifies_the_whole_tree(): void
    {
        $this->write('home.html');
        $this->write('about.html');
        $this->write('mailer.php', '<?php mail();');

        $this->assertNull($this->entry());
    }

    public function test_assets_with_no_document_are_not_a_site(): void
    {
        $this->write('css/site.css', 'body{}');
        $this->write('js/app.js', 'x');

        $this->assertNull($this->entry());
    }

    public function test_an_empty_directory_is_not_a_site(): void
    {
        $this->assertNull($this->entry());
    }

    /**
     * The engine's own welcome page is not a site. Claiming it would deploy
     * a fresh account as a static site serving PanelAlpha's own HTML.
     */
    public function test_the_engine_placeholder_alone_is_not_a_site(): void
    {
        $this->write('index.html', '<html><title>' . PlaceholderPage::WELCOME_TITLE . '</title></html>');

        $this->assertNull($this->entry());
    }

    public function test_a_placeholder_never_wins_over_a_real_page(): void
    {
        $this->write('index.html', '<html><title>' . PlaceholderPage::WELCOME_TITLE . '</title></html>');
        $this->write('home.html');
        $this->write('about.html');

        $this->assertSame('home.html', $this->entry());
    }

    /**
     * The second deploy of a project written into ~/project a file at a time.
     * The compose file and nginx config in that directory are the engine's,
     * and reading them back as "this is not a static site" would have the
     * platform claim the project once and never again.
     */
    public function test_the_engines_own_files_do_not_disqualify_a_site(): void
    {
        $this->write('home.html');
        $this->write('about.html');
        $this->write('docker-compose.yml', "services:\n  app:\n    labels:\n      panelalpha.generated: static-bootstrap\n");
        $this->write('panelalpha.nginx.conf', 'server {}');
        $this->write('panelalpha-entrypoint.sh', '#!/bin/sh');

        $this->assertSame('home.html', $this->entry());
    }

    /** A compose file the customer wrote is theirs, and is not this platform. */
    public function test_a_hand_written_compose_file_disqualifies_the_tree(): void
    {
        $this->write('home.html');
        $this->write('docker-compose.yml', "services:\n  app:\n    image: nginx\n");

        $this->assertNull($this->entry());
    }

    /**
     * Repository housekeeping is neither a page nor a program. A site
     * imported from GitHub Pages arrives with most of these.
     */
    public function test_repository_housekeeping_does_not_disqualify_a_site(): void
    {
        $this->write('home.html');
        $this->write('README.md', '# site');
        $this->write('LICENSE', 'MIT');
        $this->write('CNAME', 'example.com');
        $this->write('robots.txt', '');
        $this->write('.gitignore', '*.log');
        $this->write('.nojekyll', '');

        $this->assertSame('home.html', $this->entry());
    }

    public function test_vendored_trees_are_not_walked(): void
    {
        $this->write('home.html');
        $this->write('node_modules/left-pad/index.js', 'module.exports=1');
        $this->write('vendor/autoload.php', '<?php');

        $this->assertSame('home.html', $this->entry());
    }

    public function test_the_extension_is_matched_however_it_is_spelled(): void
    {
        $this->write('Home.HTML');
        $this->write('About.HTM');

        $this->assertSame('Home.HTML', $this->entry());
    }
}
