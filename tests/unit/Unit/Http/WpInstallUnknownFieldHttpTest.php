<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Requests\Wp\WpPluginInstallRequest;
use App\Http\Requests\Wp\WpThemeInstallRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /wp/plugins/install and /wp/themes/install take slug, force, path,
 * service and skip_plugins. A field they do not take -- the panel used to send
 * `url` -- is a 422 that names it; it used to be dropped without a word, and
 * the install came from wordpress.org instead.
 */
class WpInstallUnknownFieldHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return [
            'plugins' => ['plugins'],
            'themes' => ['themes'],
        ];
    }

    /** @return array<string, array{string, class-string<FormRequest>}> */
    public static function installs(): array
    {
        return [
            'plugins' => ['plugins', WpPluginInstallRequest::class],
            'themes' => ['themes', WpThemeInstallRequest::class],
        ];
    }

    /** @return list<array{string, string}> */
    private function problems(TestResponse $response): array
    {
        return array_map(
            static fn (array $problem): array => [$problem['field'], $problem['code']],
            $response->json('problems') ?? [],
        );
    }

    #[DataProvider('kinds')]
    public function test_a_field_the_body_does_not_take_is_a_422_that_names_it(string $kind): void
    {
        $response = $this->postJson("/api/projects/fe118/wp/{$kind}/install", [
            'slug' => 'hello-dolly',
            'url' => 'https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip',
        ]);

        $response->assertStatus(422);
        $this->assertSame([['url', 'unknown_field']], $this->problems($response));
        $this->assertSame(['`url` is not a field of this body.'], $response->json('errors.url'));
    }

    #[DataProvider('kinds')]
    public function test_each_field_the_body_does_not_take_is_named(string $kind): void
    {
        $response = $this->postJson("/api/projects/fe118/wp/{$kind}/install", [
            'slug' => 'hello-dolly',
            'url' => 'https://example.test/x.zip',
            'version' => '1.7.2',
        ]);

        $response->assertStatus(422);
        $this->assertSame([['url', 'unknown_field'], ['version', 'unknown_field']], $this->problems($response));
    }

    #[DataProvider('kinds')]
    public function test_the_refusal_comes_before_the_project_is_read(string $kind): void
    {
        $missing = $this->postJson("/api/projects/fe118-missing/wp/{$kind}/install", ['slug' => 'hello-dolly']);
        $missing->assertStatus(404);

        $refused = $this->postJson("/api/projects/fe118-missing/wp/{$kind}/install", [
            'slug' => 'hello-dolly',
            'url' => 'https://example.test/x.zip',
        ]);

        $this->assertSame([['url', 'unknown_field']], $this->problems($refused));
        $this->assertSame(422, $refused->getStatusCode());
    }

    /**
     * @param  class-string<FormRequest>  $request
     */
    #[DataProvider('installs')]
    public function test_every_field_of_the_body_still_validates(string $kind, string $request): void
    {
        $form = $request::create("/api/projects/fe118/wp/{$kind}/install", 'POST', [
            'slug' => 'hello-dolly',
            'force' => true,
            'path' => 'blog',
            'service' => 'wordpress',
            'skip_plugins' => true,
        ]);
        $form->setContainer($this->app);
        $form->validateResolved();

        $this->assertSame([
            'slug' => 'hello-dolly',
            'force' => true,
            'path' => 'blog',
            'service' => 'wordpress',
            'skip_plugins' => true,
        ], $form->validated());
    }
}
