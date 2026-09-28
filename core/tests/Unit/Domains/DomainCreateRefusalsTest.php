<?php

namespace Tests\Unit\Domains;

use App\Http\Middleware\Authenticate;
use App\Models\Domain;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What `POST /projects/{project}/domains` and `domain:create` refuse, and how
 * each words it. Every case stops before anything touches the host.
 */
class DomainCreateRefusalsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    private function store(array $body)
    {
        return $this->postJson('/api/projects/alice/domains', $body);
    }

    public function test_http_refuses_an_addon_over_the_limit(): void
    {
        $user = $this->makeUser('alice', ['addon_domains_limit' => 1]);
        $this->addDomain($user, 'one.test', 'addon');

        $this->store(['domain' => 'two.test', 'type' => 'addon'])
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'Addon domains limit of 1 reached.',
                'error_type' => 'addon_domains_limit_reached',
            ]);
    }

    public function test_http_refuses_a_subdomain_over_the_limit(): void
    {
        $this->makeUser('alice', ['subdomains_limit' => 0]);

        $this->store(['domain' => 'a.alice.example.test', 'type' => 'subdomain'])
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'Subdomains limit of 0 reached.',
                'error_type' => 'subdomains_limit_reached',
            ]);
    }

    public function test_http_refuses_a_taken_domain_and_a_taken_alias(): void
    {
        $user = $this->makeUser('alice');
        $this->addDomain($user, 'taken.test', 'addon');

        $this->store(['domain' => 'taken.test', 'type' => 'addon'])
            ->assertStatus(422)
            ->assertJsonPath('errors.domain.0', 'Domain already exists.');

        $this->store(['domain' => 'free.test', 'type' => 'addon', 'aliases' => ['taken.test']])
            ->assertStatus(422)
            ->assertJsonPath('errors.aliases.0', 'Domain already exists.');
    }

    public function test_http_refuses_an_invalid_alias(): void
    {
        $this->makeUser('alice');

        $this->store(['domain' => 'free.test', 'type' => 'addon', 'aliases' => ['not a name']])
            ->assertStatus(422)
            ->assertJsonPath('errors.aliases.0', 'Invalid alias domain name.');
    }

    /** The www name is folded into an alias before the checks, so its alias is what collides. */
    public function test_http_checks_the_www_alias_it_adds(): void
    {
        $user = $this->makeUser('alice');
        $this->addDomain($user, 'other.test', 'addon', ['www.shop.test']);

        $this->store(['domain' => 'www.shop.test', 'type' => 'addon'])
            ->assertStatus(422)
            ->assertJsonPath('errors.aliases.0', 'Domain already exists.');
    }

    public function test_cli_refuses_each_bad_input_with_its_own_message(): void
    {
        $user = $this->makeUser('alice', ['addon_domains_limit' => 1]);
        $this->makeMainDomain($user, 'alice.example.test');
        $this->addDomain($user, 'taken.test', 'addon');
        $this->makeUser('bob');

        $cases = [
            ['one.test --domain=two.test --project=alice', "Conflicting domain names: argument 'one.test' vs --domain='two.test'."],
            ['--project=alice', 'Domain name is required (positional argument or --domain).'],
            ['one.test', '--project is required.'],
            ['one.test --project=alice --type=main', "--type must be 'addon' or 'sub'."],
            ['one.test --project=nobody', "Project 'nobody' not found."],
            ['one.test --project=alice --proxy-to=nope:x', "Invalid --proxy-to 'nope:x'. Expected port (e.g. 8080) or host:port (e.g. myproject:8080)."],
            ['one.test --project=alice', 'Addon domains limit of 1 reached.'],
            ['a.alice.example.test --project=alice --type=sub', '--parent-domain is required when --type=sub.'],
            ['a.x.test --project=alice --type=sub --parent-domain=x.test', "Parent domain 'x.test' not found for project 'alice'."],
            ['a.other.test --project=alice --type=subdomain --parent-domain=alice.example.test', "Domain 'a.other.test' must end with parent domain 'alice.example.test'."],
            ['taken.test --project=bob', "Domain 'taken.test' already exists."],
            ['free.test --project=bob --alias=taken.test', "Alias 'taken.test' already exists."],
            ["'bad name' --project=bob", "Invalid domain name 'bad name'."],
        ];

        foreach ($cases as [$args, $message]) {
            $this->artisan('domain:create ' . $args)
                ->expectsOutputToContain($message)
                ->assertExitCode(1);
        }
    }

    public function test_cli_shows_the_folded_www_name_and_creates_nothing_when_declined(): void
    {
        $this->makeUser('alice');

        $this->artisan('domain:create www.shop.test --project=alice --alias=a.shop.test --alias=a.shop.test')
            ->expectsOutputToContain('Create domain')
            ->expectsConfirmation('Create this domain?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(0, Domain::query()->count());
    }

    /** @param list<string> $aliases */
    private function addDomain(User $user, string $name, string $type, array $aliases = []): void
    {
        Domain::make([
            'user_id' => $user->id,
            'domain' => $name,
            'type' => $type,
            'details' => ['document_root' => "/{$name}/public_html", 'aliases' => $aliases],
        ])->save();
    }
}
