<?php

namespace Tests\Unit\System\Services;

use PHPUnit\Framework\TestCase;

class ModsecShippedRulesetsTest extends TestCase
{
    private function templates(): string
    {
        return dirname(__DIR__, 4) . '/../templates/config';
    }

    public function test_wordpress_ruleset_is_laid_out_like_one(): void
    {
        // Modsec::getRulesets() only lists a directory that has rules/*.conf.
        $files = glob($this->templates() . '/modsecurity/rulesets/panelalpha-wordpress/rules/*.conf');
        $this->assertNotEmpty($files);
    }

    public function test_author_enumeration_is_denied_on_the_query_string_only(): void
    {
        $conf = (string) file_get_contents(
            $this->templates() . '/modsecurity/rulesets/panelalpha-wordpress/rules/PANELALPHA-WORDPRESS.conf'
        );

        $this->assertStringContainsString('SecRule ARGS_GET:author', $conf);
        // A POST `author` is the commenter's name in wp-comments-post.php.
        $this->assertStringNotContainsString('SecRule ARGS:author', $conf);
        $this->assertStringContainsString('deny', $conf);
        $this->assertStringContainsString('!@contains /wp-admin/', $conf);
    }

    public function test_rest_user_listing_is_denied_only_to_anonymous_visitors(): void
    {
        $conf = (string) file_get_contents(
            $this->templates() . '/modsecurity/rulesets/panelalpha-wordpress/rules/PANELALPHA-WORDPRESS.conf'
        );

        // Both routes: pretty permalinks and ?rest_route= without them.
        $this->assertStringContainsString('/wp-json/wp/v2/users', $conf);
        $this->assertStringContainsString('SecRule ARGS_GET:rest_route', $conf);
        // The block editor lists authors signed in; it must keep working.
        $this->assertSame(2, substr_count($conf, '&REQUEST_COOKIES:/^wordpress_logged_in_/ "@eq 0"'));
    }

    public function test_rule_ids_are_unique_across_everything_shipped(): void
    {
        $files = array_merge(
            glob($this->templates() . '/modsecurity/rulesets/*/rules/*.conf') ?: [],
            [$this->templates() . '/modsecurity-main.conf.blade.php'],
        );

        $seen = [];
        foreach ($files as $file) {
            preg_match_all('/\bid:(\d+)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $id) {
                $this->assertArrayNotHasKey($id, $seen, "rule id {$id} in {$file} is also in " . ($seen[$id] ?? ''));
                $seen[$id] = basename($file);
            }
        }
        foreach (['1000101', '1000102', '1000103'] as $id) {
            $this->assertArrayHasKey($id, $seen);
        }
    }
}
