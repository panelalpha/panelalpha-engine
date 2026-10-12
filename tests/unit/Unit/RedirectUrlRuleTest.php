<?php

namespace Tests\Unit;

use App\Rules\RedirectUrl;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The redirect URL ends up inside nginx's `return 301 "<url>"` in the shared
 * reverse proxy vhost, so a value with `$`, whitespace or a quote there is a
 * directive to nginx, not part of the URL (engine#852). `url` alone accepts
 * all of these; every path that writes a redirect pairs it with this rule.
 */
class RedirectUrlRuleTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return [
            'plain' => ['https://example.com'],
            'path and query' => ['https://example.com/a/b?x=1&y=2'],
            'trailing slash' => ['https://example.com/'],
            'port' => ['https://example.com:8443/x'],
            'fragment' => ['https://example.com/#section'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_a_safe_url_passes(string $url): void
    {
        $validator = Validator::make(['redirect_url' => $url], ['redirect_url' => ['url', new RedirectUrl()]]);

        $this->assertFalse($validator->fails(), $url . ': ' . (string) $validator->errors());
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            // An unknown nginx variable makes it refuse the whole config.
            'unknown nginx variable' => ['https://x/$undefinedvar'],
            // `$http_cookie` would forward every visitor's cookies.
            'cookie variable in query' => ['https://x/?c=$http_cookie'],
            'semicolon' => ['https://x/a;b'],
            // `url` accepts a single trailing newline (no D modifier).
            'trailing newline' => ["https://x/a\n"],
            'space' => ['https://x/a b'],
            'double quote' => ['https://x/a"b'],
            'single quote' => ["https://x/a'b"],
            'backslash' => ['https://x/a\\b'],
            'open brace' => ['https://x/{a'],
            'backtick' => ['https://x/`id`'],
        ];
    }

    #[DataProvider('refused')]
    public function test_an_unsafe_url_is_refused(string $url): void
    {
        $validator = Validator::make(['redirect_url' => $url], ['redirect_url' => ['url', new RedirectUrl()]]);

        $this->assertTrue($validator->fails(), json_encode($url));
        $this->assertArrayHasKey('redirect_url', $validator->errors()->toArray());
    }

    public function test_project_create_reads_the_same_rule(): void
    {
        $rules = \App\Lib\Project\NewProjectRules::rules('username', 'example.com');

        $this->assertContains(
            RedirectUrl::class,
            array_map(
                static fn ($rule) => is_object($rule) ? $rule::class : $rule,
                $rules['domain_redirect_url'],
            ),
        );
    }
}
