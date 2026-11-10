<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use PHPUnit\Framework\TestCase;

/**
 * The nginx config the "Project not configured" page is served by: nothing is
 * running, so no monitor, preview check or browser may read the site as up.
 */
class PlaceholderNginxConfigTest extends TestCase
{
    public function test_every_path_answers_not_found_with_the_page(): void
    {
        $conf = NginxConfig::placeholder();

        $this->assertMatchesRegularExpression('/location \/ \{\s*return 404;\s*\}/', $conf);
        $this->assertStringContainsString('error_page 404 /index.html;', $conf);
    }

    /** Asked for by name, the page is a 404 too: it is only ever the body of one. */
    public function test_the_page_is_not_served_as_a_document(): void
    {
        $this->assertMatchesRegularExpression('/location = \/index\.html \{\s*internal;\s*\}/', NginxConfig::placeholder());
    }

    /** Nothing in the document root is the application, so none of the checkout is served. */
    public function test_no_file_of_the_checkout_is_served(): void
    {
        $conf = NginxConfig::placeholder();

        $this->assertStringNotContainsString('try_files', $conf);
        $this->assertStringNotContainsString('autoindex', $conf);
    }
}
