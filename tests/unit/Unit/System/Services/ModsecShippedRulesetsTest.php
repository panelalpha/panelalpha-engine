<?php

namespace Tests\Unit\System\Services;

use PHPUnit\Framework\TestCase;

class ModsecShippedRulesetsTest extends TestCase
{
    private function templates(): string
    {
        return dirname(__DIR__, 5).'/templates/config';
    }

    private function crsRulesDir(): string
    {
        return $this->templates().'/modsecurity/rulesets/owasp-crs/rules';
    }

    public function test_wordpress_ruleset_is_laid_out_like_one(): void
    {
        // Modsec::getRulesets() only lists a directory that has rules/*.conf.
        $files = glob($this->templates().'/modsecurity/rulesets/panelalpha-wordpress/rules/*.conf');
        $this->assertNotEmpty($files);
    }

    public function test_author_enumeration_is_denied_on_the_query_string_only(): void
    {
        $conf = (string) file_get_contents(
            $this->templates().'/modsecurity/rulesets/panelalpha-wordpress/rules/PANELALPHA-WORDPRESS.conf'
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
            $this->templates().'/modsecurity/rulesets/panelalpha-wordpress/rules/PANELALPHA-WORDPRESS.conf'
        );

        // Both routes: pretty permalinks and ?rest_route= without them.
        $this->assertStringContainsString('/wp-json/wp/v2/users', $conf);
        $this->assertStringContainsString('SecRule ARGS_GET:rest_route', $conf);
        // The block editor lists authors signed in; it must keep working.
        $this->assertSame(2, substr_count($conf, '&REQUEST_COOKIES:/^wordpress_logged_in_/ "@eq 0"'));
    }

    public function test_rest_api_methods_are_allowed_before_crs_sets_its_default(): void
    {
        $setter = $this->firstShippedRuleSettingAllowedMethods();
        $conf = (string) file_get_contents($this->crsRulesDir().'/'.$setter);

        $this->assertLessThan(0, strcmp($setter, 'REQUEST-901-INITIALIZATION.conf'));
        $this->assertStringContainsString('SecRule &TX:allowed_methods "@eq 0"', $conf);
        $this->assertMatchesRegularExpression(
            "/setvar:'tx\.allowed_methods=GET HEAD POST OPTIONS PUT PATCH DELETE'/",
            $conf
        );
    }

    /** The shipped rule that first sets tx.allowed_methods, in the order the rules load. */
    private function firstShippedRuleSettingAllowedMethods(): string
    {
        $files = glob($this->crsRulesDir().'/*.conf') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), "setvar:'tx.allowed_methods=")) {
                return basename($file);
            }
        }

        $this->fail('No shipped CRS rule sets tx.allowed_methods');
    }

    public function test_elementor_saves_skip_only_the_two_json_rules_for_signed_in_elementor_ajax_calls(): void
    {
        $conf = $this->shippedCrsRuleRemoving('ctl:ruleRemoveTargetById=942290;ARGS:actions');

        $this->assertStringContainsString('SecRule REQUEST_FILENAME "@endsWith /wp-admin/admin-ajax.php"', $conf);
        $this->assertStringContainsString('"id:1000071,phase:2,', $conf);
        $this->assertStringContainsString('SecRule &ARGS:/^\s*action$/ "@eq 1"', $conf);
        $this->assertStringContainsString('SecRule ARGS:action "@streq elementor_ajax"', $conf);
        $this->assertStringContainsString('SecRule &REQUEST_COOKIES:/^wordpress_logged_in_/ "@gt 0"', $conf);
        $this->assertStringContainsString('ctl:ruleRemoveTargetById=932370;ARGS:actions', $conf);
        $this->assertSame(2, substr_count($conf, 'ctl:'));
    }

    /** A shipped rule file of its own, so an update copies it onto a host that keeps its older files. */
    private function shippedCrsRuleRemoving(string $ctl): string
    {
        $files = glob($this->crsRulesDir().'/*.conf') ?: [];
        foreach ($files as $file) {
            $conf = (string) file_get_contents($file);
            if (str_contains($conf, $ctl)) {
                $this->assertStringNotContainsString('wc-ajax', $conf);

                return $conf;
            }
        }

        $this->fail("No shipped CRS rule file has {$ctl}");
    }

    public function test_rule_ids_are_unique_across_everything_shipped(): void
    {
        $files = array_merge(
            glob($this->templates().'/modsecurity/rulesets/*/rules/*.conf') ?: [],
            [$this->templates().'/modsecurity-main.conf.blade.php'],
        );

        $seen = [];
        foreach ($files as $file) {
            preg_match_all('/\bid:(\d+)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $id) {
                $this->assertArrayNotHasKey($id, $seen, "rule id {$id} in {$file} is also in ".($seen[$id] ?? ''));
                $seen[$id] = basename($file);
            }
        }
        foreach (['1000101', '1000102', '1000103'] as $id) {
            $this->assertArrayHasKey($id, $seen);
        }
    }
}
