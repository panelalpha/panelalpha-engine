<?php

namespace Tests\Integration\Console;

use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Prompt;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `system:example:delete` when there is nothing to delete, when what the setting promises is gone, and when the
 * operator says no.
 */
class ExampleDomainDeletePathsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
    }

    protected function tearDown(): void
    {
        Prompt::interactive(false);
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    private function created(): void
    {
        Setting::set('example-domain-created', '1');
        Setting::set('vhost-default-ip-domain', 'example.test');
    }

    private function exampleDomain(): Domain
    {
        $user = $this->makeUser('example', ['template' => 'dind']);

        return Domain::query()->create([
            'user_id' => $user->id,
            'domain' => 'example.test',
            'type' => 'main',
            'details' => ['is_example_domain' => true],
        ]);
    }

    public function test_nothing_happens_when_no_example_was_created(): void
    {
        $this->artisan('system:example:delete --force')
            ->expectsOutput('Example domain has not been created or has already been deleted.')
            ->assertExitCode(0);
    }

    public function test_a_missing_example_domain_is_forgotten(): void
    {
        $this->created();

        $this->artisan('system:example:delete --force')
            ->expectsOutput('Could not find example domain to delete.')
            ->assertExitCode(0);

        $this->assertSame('', (string) Setting::get('example-domain-created'));
        $this->assertSame('', (string) Setting::get('vhost-default-ip-domain'));
    }

    public function test_an_example_domain_whose_project_is_gone_is_forgotten(): void
    {
        $this->created();
        $this->exampleDomain();
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('users')->where('username', 'example')->delete();

        $this->artisan('system:example:delete --force')
            ->expectsOutput('Example domain found but its project was not found.')
            ->assertExitCode(0);

        $this->assertSame('', (string) Setting::get('example-domain-created'));
    }

    public function test_declining_the_prompt_deletes_nothing(): void
    {
        $this->created();
        $this->exampleDomain();

        $this->artisan('system:example:delete')
            ->expectsOutput('Found example domain: example.test (project: example)')
            ->expectsConfirmation("This will completely delete the example project 'example' and all associated data. Continue?", 'no')
            ->expectsOutput('Deletion cancelled.')
            ->assertExitCode(0);

        $this->assertSame('1', (string) Setting::get('example-domain-created'));
        $this->assertNotNull(Domain::findExampleDomain());
    }
}
