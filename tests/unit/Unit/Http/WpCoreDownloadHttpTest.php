<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Requests\Wp\WpCoreDownloadRequest;
use Illuminate\Support\Facades\Validator;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /wp/core/download: the locale and version grammar, and the refusals
 * that answer before WP-CLI or the account's files are touched.
 */
class WpCoreDownloadHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_wordpress_locales_and_versions_are_accepted(): void
    {
        $rules = (new WpCoreDownloadRequest())->rules();

        foreach (['en_US', 'pl_PL', 'de_DE_formal', 'pt_PT_ao90', 'fi', 'kab'] as $locale) {
            $this->assertTrue(Validator::make(['locale' => $locale], $rules)->passes(), $locale);
        }
        foreach (['latest', '6.7', '6.7.1'] as $version) {
            $this->assertTrue(Validator::make(['version' => $version], $rules)->passes(), $version);
        }
        $this->assertTrue(Validator::make([], $rules)->passes(), 'every field is optional');
        $this->assertFalse(Validator::make(['locale' => "en_US\n"], $rules)->passes(), 'no trailing newline');
        $this->assertFalse(Validator::make(['version' => "6.7\n"], $rules)->passes(), 'no trailing newline');
    }

    public function test_a_locale_outside_the_grammar_is_a_validation_error(): void
    {
        $user = $this->makeUser('wpdl-locale');

        foreach (['PL', 'pl-PL', 'pl_PLL', 'en_US --force', '../x'] as $locale) {
            $this->postJson("/api/projects/{$user->username}/wp/core/download", ['locale' => $locale])
                ->assertStatus(422)
                ->assertJsonValidationErrors('locale');
        }
    }

    public function test_a_version_outside_the_grammar_is_a_validation_error(): void
    {
        $user = $this->makeUser('wpdl-version');

        foreach (['nightly', '6', '6.7.1.2', 'v6.7', '6.7 --force'] as $version) {
            $this->postJson("/api/projects/{$user->username}/wp/core/download", ['version' => $version])
                ->assertStatus(422)
                ->assertJsonValidationErrors('version');
        }
    }

    public function test_a_project_running_in_its_own_containers_is_refused(): void
    {
        $user = $this->makeUser('wpdl-dind', ['template' => 'dind']);

        $response = $this->postJson("/api/projects/{$user->username}/wp/core/download", ['locale' => 'pl_PL']);

        $response->assertStatus(422);
        $this->assertStringContainsString('PHP hosting', (string) $response->json('message'));
    }

    public function test_an_unknown_project_is_not_found(): void
    {
        $this->postJson('/api/projects/wpdl-missing/wp/core/download')->assertStatus(404);
    }
}
