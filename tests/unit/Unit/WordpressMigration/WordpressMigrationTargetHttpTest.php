<?php

namespace Tests\Unit\WordpressMigration;

use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;

/**
 * POST /wordpress/migrations takes `domain`, `project` and `project_name`,
 * refuses a target it cannot use before storing the password, and answers
 * where the site lands.
 */
class WordpressMigrationTargetHttpTest extends ImportTargetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Queue::fake();
        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => ['93.184.216.34'],
        );
    }

    public function test_a_start_answers_the_kept_domain_and_the_dns_record(): void
    {
        $response = $this->postJson('/api/wordpress/migrations', $this->body(['url' => 'https://www.example.com/blog']));

        $response->assertStatus(202);
        $response->assertJson([
            'target_label' => 'example.com',
            'target_domain' => 'example.com',
            'target_project' => null,
            'created_project' => true,
            'preview_url' => null,
            'dns' => ['type' => 'A', 'value' => self::ENGINE_IPV4],
        ]);
    }

    public function test_a_taken_domain_is_422_domain_taken_and_stores_nothing(): void
    {
        $this->project('owner', 'example.com');

        $response = $this->postJson('/api/wordpress/migrations', $this->body());

        $response->assertStatus(422);
        $this->assertSame('domain_taken', $response->json('problems.0.code'));
        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_an_ip_source_without_connect_is_422_domain_required(): void
    {
        $this->withoutConnect();

        $response = $this->postJson('/api/wordpress/migrations', $this->body(['url' => 'http://93.184.216.34/']));

        $response->assertStatus(422);
        $this->assertSame('domain_required', $response->json('problems.0.code'));
    }

    public function test_an_invalid_domain_or_project_name_is_422(): void
    {
        $this->postJson('/api/wordpress/migrations', $this->body(['domain' => 'not a domain']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
        $this->postJson('/api/wordpress/migrations', $this->body(['project_name' => 'Bad_Name']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_name');
    }

    public function test_a_given_domain_and_project_name_are_stored(): void
    {
        $response = $this->postJson('/api/wordpress/migrations', $this->body([
            'domain' => 'new.example.org',
            'project_name' => 'newsite',
        ]));

        $response->assertStatus(202);
        $response->assertJson(['target_domain' => 'new.example.org', 'target_project' => 'newsite']);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + ['url' => 'https://example.com', 'username' => 'admin', 'password' => 'secret'];
    }
}
