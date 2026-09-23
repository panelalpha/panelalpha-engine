<?php

namespace Tests\Unit\Mcp;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * docs/mcp-catalogue.html is generated and committed. That combination rots
 * quietly -- the page keeps describing tools that were renamed or removed, and
 * nothing complains. This is what complains.
 */
class CatalogueTest extends TestCase
{
    private function path(): string
    {
        return base_path('../docs/mcp-catalogue.html');
    }

    public function test_the_committed_catalogue_matches_the_current_tools(): void
    {
        $this->assertFileExists($this->path(), 'Run: php artisan mcp:catalogue');

        $temp = tempnam(sys_get_temp_dir(), 'mcp-catalogue-');

        try {
            $this->assertSame(0, Artisan::call('mcp:catalogue', ['--out' => $temp]));

            $this->assertSame(
                file_get_contents($this->path()),
                file_get_contents($temp),
                'docs/mcp-catalogue.html is out of date. Run: php artisan mcp:catalogue'
            );
        } finally {
            @unlink($temp);
        }
    }

    public function test_the_catalogue_lists_every_tool(): void
    {
        $expected = count(require base_path('app/Mcp/Tools/Api/generated-tools.php')) + 2;
        $html = (string)file_get_contents($this->path());

        $this->assertSame(
            $expected,
            preg_match_all('/class="tool /', $html),
            'The catalogue does not list every registered tool'
        );

        // A tool the page names but the server does not register would send a
        // reader to call something that answers "tool not found".
        foreach (['project_suspend', 'project_clone', 'mysql_database_get', 'metrics_latest'] as $name) {
            $this->assertStringContainsString('data-name="' . $name . '"', $html, "{$name} is missing from the catalogue");
        }
    }

    public function test_the_catalogue_is_a_self_contained_document(): void
    {
        $html = (string)file_get_contents($this->path());

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('</html>', $html);

        // Google Fonts is the one external host allowed; anything else would
        // make the page depend on something that may not be reachable.
        //
        // The others are prose, not dependencies: a URL inside a parameter's
        // example or description is text the page prints, and the regex
        // cannot tell that from an href. `example.com.` was already here for
        // that reason; `github.com` arrived with the `git_repo` example.
        preg_match_all('#https?://[^/"\s]+#', $html, $matches);
        $hosts = array_values(array_unique(array_diff(
            $matches[0],
            [
                'https://fonts.googleapis.com',
                'https://fonts.gstatic.com',
                'https://example.com.',
                'https://github.com',
            ]
        )));

        $this->assertSame([], $hosts, 'The catalogue references an external host');
    }
}
