<?php

namespace Tests\Unit\Http;

use App\Http\Requests\Wp\WpApplicationPasswordCreateRequest;
use App\Http\Requests\Wp\WpDatabaseExportRequest;
use App\Http\Requests\Wp\WpDatabaseImportRequest;
use App\Http\Requests\Wp\WpLanguageSetRequest;
use App\Http\Requests\Wp\WpPluginInstallRequest;
use App\Http\Requests\Wp\WpPluginSearchRequest;
use App\Http\Requests\Wp\WpThemeInstallRequest;
use App\Http\Requests\Wp\WpThemeSearchRequest;
use App\Http\Requests\Wp\WpUserCreateRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Values the named WordPress routes pass to WP-CLI as positional arguments.
 * One starting with '-' would be read as an option, `--exec` included, so a
 * token allowed one named operation could run any PHP. The body fields are
 * refused by the FormRequests, the path parameters by the routes.
 */
class WpCliArgumentInputTest extends TestCase
{
    private const OPTION = '--exec=phpinfo();';

    /** @return array<string, array{class-string<FormRequest>, array<string, string>, string, string}> */
    public static function bodyFields(): array
    {
        return [
            'plugin install' => [WpPluginInstallRequest::class, [], 'slug', 'hello-dolly'],
            'theme install' => [WpThemeInstallRequest::class, [], 'slug', 'twentytwentyfour'],
            'plugin search' => [WpPluginSearchRequest::class, [], 'term', 'contact form'],
            'theme search' => [WpThemeSearchRequest::class, [], 'term', 'blog'],
            'user login' => [WpUserCreateRequest::class, ['email' => 'jdoe@example.test', 'password' => 'secret123'], 'login', 'jdoe'],
            'user email' => [WpUserCreateRequest::class, ['login' => 'jdoe', 'password' => 'secret123'], 'email', 'jdoe@example.test'],
            'application password name' => [WpApplicationPasswordCreateRequest::class, [], 'name', 'ci deploy'],
            'database export file' => [WpDatabaseExportRequest::class, [], 'file', '/home/alice/backup.sql'],
            'database import file' => [WpDatabaseImportRequest::class, [], 'file', 'backup.sql'],
            'language' => [WpLanguageSetRequest::class, [], 'language', 'pt_PT_ao90'],
        ];
    }

    /**
     * @param  class-string<FormRequest>  $request
     * @param  array<string, string>  $rest
     */
    #[DataProvider('bodyFields')]
    public function test_a_body_value_starting_with_a_dash_is_refused(string $request, array $rest, string $field, string $valid): void
    {
        $rules = (new $request)->rules();

        $this->assertTrue(Validator::make([$field => $valid] + $rest, $rules)->passes(), $valid);
        $this->assertFalse(Validator::make([$field => self::OPTION] + $rest, $rules)->passes(), self::OPTION);
    }

    public function test_an_email_that_is_also_a_wp_cli_option_is_refused(): void
    {
        $validator = Validator::make(
            ['login' => 'jdoe', 'email' => '--require=/home/alice/x.php@a.test', 'password' => 'secret123'],
            (new WpUserCreateRequest)->rules(),
        );

        $this->assertSame(
            ["The email must not start with '-': WP-CLI would read it as an option."],
            $validator->errors()->get('email'),
        );
    }

    public function test_a_slug_is_a_plugin_or_theme_name_not_a_url_or_a_file(): void
    {
        $rules = (new WpPluginInstallRequest)->rules();

        foreach (['akismet', 'Divi', 'my_plugin.v2', 'hello-dolly'] as $slug) {
            $this->assertTrue(Validator::make(['slug' => $slug], $rules)->passes(), $slug);
        }
        foreach (['https://example.test/x.zip', '/home/alice/x.zip', '../x', "akismet\n"] as $slug) {
            $this->assertFalse(Validator::make(['slug' => $slug], $rules)->passes(), $slug);
        }
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function pathParameters(): array
    {
        $base = '/api/projects/alice/wp';

        return [
            'plugin' => ['POST', "{$base}/plugins/%s/activate", 'akismet', 'WpPluginController@activate'],
            'plugin, read' => ['GET', "{$base}/plugins/%s", 'akismet', 'WpPluginController@show'],
            'theme' => ['POST', "{$base}/themes/%s/activate", 'Divi', 'WpThemeController@activate'],
            'user' => ['PUT', "{$base}/users/%s/password", 'jdoe', 'WpUserController@setPassword'],
            'user by id' => ['DELETE', "{$base}/users/%s", '5', 'WpUserController@destroy'],
            'user sso' => ['POST', "{$base}/users/%s/sso", 'admin@example.test', 'WpUserController@createSso'],
            'application password user' => ['POST', "{$base}/users/%s/application-passwords", 'admin', 'WpApplicationPasswordController@store'],
            'application password uuid' => ['DELETE', "{$base}/users/admin/application-passwords/%s", '6d0c5d1e-5b4b-4f8a-9f3e-0e1f2a3b4c5d', 'WpApplicationPasswordController@destroy'],
            'constant' => ['PUT', "{$base}/config/%s", 'WP_DEBUG', 'WpConfigController@update'],
            'option' => ['PUT', "{$base}/options/%s", 'theme_mods_twentytwenty-one', 'WpOptionController@update'],
            'option, read' => ['GET', "{$base}/options/%s", 'blogname', 'WpOptionController@show'],
        ];
    }

    #[DataProvider('pathParameters')]
    public function test_a_path_value_starting_with_a_dash_matches_no_wordpress_route(string $method, string $uri, string $valid, string $action): void
    {
        $this->assertStringEndsWith($action, (string) $this->actionFor($method, sprintf($uri, rawurlencode($valid))));
        $this->assertStringNotContainsString('\\Wp', (string) $this->actionFor($method, sprintf($uri, rawurlencode(self::OPTION))));
        $this->assertStringNotContainsString('\\Wp', (string) $this->actionFor($method, sprintf($uri, '-x')));
    }

    private function actionFor(string $method, string $uri): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create($uri, $method))->getActionName();
        } catch (HttpException) {
            return null;
        }
    }
}
