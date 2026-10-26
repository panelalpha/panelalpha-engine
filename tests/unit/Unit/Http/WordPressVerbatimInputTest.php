<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\TrimStrings;
use App\Http\Requests\Wp\WpConfigCreateRequest;
use App\Http\Requests\Wp\WpConfigSetRequest;
use App\Http\Requests\Wp\WpOptionSetRequest;
use App\Http\Requests\Wp\WpSearchReplaceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Raw WP-CLI arguments and the values of named WordPress writes reach the
 * controller byte for byte: '' stays '' and surrounding whitespace stays.
 */
class WordPressVerbatimInputTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function verbatimRoutes(): array
    {
        return [
            'wp/command' => ['POST', '/api/projects/alice/wp/command'],
            'wp/command, users prefix' => ['POST', '/api/users/alice/wp/command'],
            'legacy wp-cli/command' => ['POST', '/api/projects/alice/wp-cli/command'],
            'legacy wp-cli/command, users prefix' => ['POST', '/api/users/alice/wp-cli/command'],
            'config create' => ['POST', '/api/projects/alice/wp/config'],
            'config set' => ['PUT', '/api/projects/alice/wp/config/WP_DEBUG'],
            'option set' => ['PUT', '/api/users/alice/wp/options/blogname'],
            'search-replace' => ['POST', '/api/projects/alice/wp/search-replace'],
        ];
    }

    #[DataProvider('verbatimRoutes')]
    public function test_empty_and_padded_strings_pass_unchanged(string $method, string $uri): void
    {
        $body = ['args' => ['rewrite', 'structure', ''], 'value' => '  spaced  ', 'replacement' => ''];

        $this->assertSame($body, $this->throughMiddleware($method, $uri, $body));
    }

    /** @return array<string, array{class-string<FormRequest>, array<string, string>}> */
    public static function emptyValueWrites(): array
    {
        return [
            'config set' => [WpConfigSetRequest::class, ['value' => '']],
            'option set' => [WpOptionSetRequest::class, ['value' => '']],
            'search-replace' => [WpSearchReplaceRequest::class, ['search' => 'old', 'replacement' => '']],
        ];
    }

    /**
     * @param class-string<FormRequest> $request
     * @param array<string, string> $body
     */
    #[DataProvider('emptyValueWrites')]
    public function test_an_empty_value_is_valid(string $request, array $body): void
    {
        $this->assertTrue(Validator::make($body, (new $request())->rules())->passes());
        $this->assertFalse(Validator::make([], (new $request())->rules())->passes(), 'the value is still required to be present');
    }

    public function test_config_create_takes_an_empty_password_and_any_wordpress_locale(): void
    {
        $rules = (new WpConfigCreateRequest())->rules();
        $body = ['dbname' => 'db', 'dbuser' => 'u', 'dbpass' => '', 'locale' => 'pt_PT_ao90', 'dbprefix' => 'wp2_'];

        $this->assertTrue(Validator::make($body, $rules)->passes());
        $this->assertFalse(Validator::make(['dbprefix' => 'wp-'] + $body, $rules)->passes(), 'WP-CLI refuses this prefix too');
    }

    public function test_other_routes_are_still_trimmed_and_nulled(): void
    {
        $incoming = $this->throughMiddleware('POST', '/api/projects/alice/wp/plugins/install', [
            'slug' => '  hello-dolly ',
            'path' => '',
        ]);

        $this->assertSame(['slug' => 'hello-dolly', 'path' => null], $incoming);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function throughMiddleware(string $method, string $uri, array $body): array
    {
        $request = Request::create($uri, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body));

        return (new TrimStrings)->handle(
            $request,
            static fn (Request $trimmed) => (new ConvertEmptyStringsToNull)->handle(
                $trimmed,
                static fn (Request $r) => $r->json()->all(),
            ),
        );
    }
}
