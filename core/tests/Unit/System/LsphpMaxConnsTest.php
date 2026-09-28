<?php

namespace Tests\Unit\System;

use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LsphpMaxConnsTest extends TestCase
{
    private function user(?string $lsphpSettings): User
    {
        $user = new User();
        $user->username = 'lsuser';
        $user->setDetails(['lsphp_settings' => $lsphpSettings]);

        return $user;
    }

    public function test_max_conns_follows_the_plans_lsphp_children(): void
    {
        $this->assertSame(4, $this->user("PHP_LSAPI_CHILDREN=4\nPHP_LSAPI_MAX_REQUESTS=500")->getLsPhpMaxConns());
        $this->assertSame(8, $this->user('PHP_LSAPI_CHILDREN=8')->getLsPhpMaxConns());
    }

    public function test_max_conns_keeps_35_without_a_plan_value_and_never_drops_below_one(): void
    {
        $this->assertSame(35, $this->user(null)->getLsPhpMaxConns());
        $this->assertSame(35, $this->user('PHP_LSAPI_CHILDREN=many')->getLsPhpMaxConns());
        $this->assertSame(1, $this->user('PHP_LSAPI_CHILDREN=0')->getLsPhpMaxConns());
    }

    /**
     * @return array<string, mixed>
     */
    private function vars(?int $maxConns): array
    {
        $vars = [
            'domain' => 'example.test',
            'aliases' => [],
            'relative_document_root' => '/example.test/public_html',
            'user' => 'lsuser',
            'suspended' => false,
            'php_port' => '9083',
            'cache_engine' => '7',
            'cache_store_path' => '/tmp/lscache',
            'cache_check_public' => '1',
            'cache_check_private' => '1',
            'cache_enabled' => '1',
        ];
        if ($maxConns !== null) {
            $vars['lsphp_max_conns'] = $maxConns;
        }

        return $vars;
    }

    private function render(string $webserver, ?int $maxConns): string
    {
        $template = (string) file_get_contents(dirname(base_path()) . "/templates/virtualHostConfig-{$webserver}.blade.php");

        return Blade::render($template, $this->vars($maxConns));
    }

    public function test_litespeed_vhost_sets_lsphp_max_conns_from_the_plan(): void
    {
        $out = $this->render('litespeed', 4);

        $this->assertMatchesRegularExpression('#<name>lsuser-lsphp</name>\s*<address>lsuser:9083</address>\s*<maxConns>4</maxConns>#', $out);
        $this->assertStringContainsString('<env>PHP_LSAPI_CHILDREN=4</env>', $out);
        $this->assertStringContainsString('<maxConns>10</maxConns>', $out, 'phpMyAdmin proxy keeps its own limit');
        $this->assertStringNotContainsString('<maxConns>35</maxConns>', $out);
    }

    public function test_openlitespeed_vhost_sets_lsphp_max_conns_from_the_plan(): void
    {
        $out = $this->render('openlitespeed', 4);

        $this->assertMatchesRegularExpression('#extprocessor lsuser-example\.test \{\s*type\s+lsapi\s*address\s+lsuser:9083\s*maxConns\s+4\n#', $out);
        $this->assertMatchesRegularExpression('#extprocessor lsuser-phpmyadmin \{[^}]*maxConns\s+10\n#', $out);
        $this->assertDoesNotMatchRegularExpression('#maxConns\s+35\b#', $out);
    }

    public function test_vhosts_default_to_35_when_the_value_is_not_passed(): void
    {
        $this->assertStringContainsString('<maxConns>35</maxConns>', $this->render('litespeed', null));
        $this->assertMatchesRegularExpression('#maxConns\s+35\n#', $this->render('openlitespeed', null));
    }

    public function test_both_drivers_pass_the_plans_limit_to_the_vhost(): void
    {
        foreach (['Litespeed', 'Openlitespeed'] as $driver) {
            $src = (string) file_get_contents(app_path("System/Services/Webserver/{$driver}.php"));
            $this->assertStringContainsString("'lsphp_max_conns' => \$user->getLsPhpMaxConns()", $src, $driver);
        }
    }
}
