<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\ApiAccessSection;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ApiAccessFirewall;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use Illuminate\Support\Facades\Queue;
use Laravel\Prompts\Key;
use Tests\Support\InMemoryDatabase;
use Tests\Support\NoProcessHost;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure api-access`: allow, rotate and deny, each confirmed, against a host that only records. */
class ApiAccessSectionTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const CONTAINER_DETAILS = ['template' => 'dind', 'UID' => 1000, 'GID' => 1000];

    private System $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://engine.example.test:2011',
            'env.DIND_EGRESS_GUARD' => false,
        ]);
        $this->app->forgetInstance('encrypter');
        Queue::fake();
        Setting::flushCache();
        $this->host = $this->recordingHost();
        $this->app->instance(System::class, $this->host);
        $this->makeUser('alice', self::CONTAINER_DETAILS);
        $this->makeUser('bob', self::CONTAINER_DETAILS);
        $this->makeUser('classic1');
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_allowing_a_project_asks_for_one_that_exists_then_opens_the_port(): void
    {
        $this->keys([
            Key::ENTER, 'nobody'.Key::ENTER, self::typed('alice', 'nobody'),
            Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER,
            Key::CTRL_E, Key::ENTER,
        ]);

        $section = new ApiAccessSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('There is no project by that name on this engine.');
        $this->assertSame(['Allow alice to use the Engine API, scope self.'], $section->receipt());
        $entries = (new ApiAccess($this->host))->all();
        $this->assertSame(['alice'], array_column($entries, 'project'));
        $this->assertSame(ApiAccess::SCOPE_SELF, $entries[0]['scope']);
        $this->assertFalse($entries[0]['mcp']);
        $this->assertStringContainsString('alice', $this->host->writes[ApiAccessFirewall::FILE][0]);
        $this->assertScreenShows('Allowed: none');
        $this->assertScreenShows('Allowed: 1 project(s) alice scope self REST only');
        $this->assertScreenShows('Rotate a token — replace it without changing the scope');
    }

    public function test_a_list_scope_names_projects_that_exist_and_may_carry_mcp(): void
    {
        $this->keys([
            Key::ENTER, 'alice'.Key::ENTER, ...self::row(2),
            'bob, nobody'.Key::ENTER, self::typed('bob', 'bob, nobody'),
            'y', Key::ENTER, Key::ENTER, Key::ENTER,
            Key::CTRL_E, Key::ENTER,
        ]);

        $section = new ApiAccessSection();
        $section->run(false);

        $this->assertScreenShows("There is no project called 'nobody' on this engine.");
        $this->assertSame(['Allow alice to use the Engine API, scope bob, including MCP tools.'], $section->receipt());
        $entry = (new ApiAccess($this->host))->all()[0];
        $this->assertSame(['bob'], $entry['scope']);
        $this->assertTrue($entry['mcp']);
        $this->assertScreenShows('alice scope bob with MCP tools');
    }

    public function test_every_project_scope_in_a_dry_run_writes_nothing(): void
    {
        $this->keys([Key::ENTER, 'alice'.Key::ENTER, ...self::row(1), Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new ApiAccessSection();
        $section->run(true);

        $this->assertSame(['Allow alice to use the Engine API, scope all.'], $section->receipt());
        $this->assertScreenShows('Nothing was written: this was a dry run.');
        $this->assertScreenShows('Back — nothing has been written');
        $this->assertSame([], (new ApiAccess($this->host))->all());
        $this->assertSame([], $this->host->writes);
    }

    public function test_declining_the_allowance_writes_nothing(): void
    {
        $this->keys([Key::ENTER, 'alice'.Key::ENTER, Key::ENTER, Key::ENTER, 'n', Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new ApiAccessSection();
        $section->run(false);

        $this->assertScreenShows('Nothing was written.');
        $this->assertSame([], $section->receipt());
        $this->assertSame([], (new ApiAccess($this->host))->all());
    }

    public function test_a_project_that_cannot_be_given_access_is_refused_with_the_reason(): void
    {
        $this->keys([Key::ENTER, 'classic1'.Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new ApiAccessSection();
        $section->run(false);

        $this->assertScreenShows("'classic1' is a PHP hosting project");
        $this->assertSame([], $section->receipt());
    }

    public function test_rotating_mints_a_new_token_with_the_same_scope(): void
    {
        $before = (new ApiAccess($this->host))->allow(User::findByUsername('alice'), ['bob'], false);
        $this->keys([...self::row(1), Key::ENTER, Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new ApiAccessSection();
        $section->run(false);

        $after = (new ApiAccess($this->host))->all()[0];
        $this->assertSame(['Mint a new token for alice, keeping its scope.'], $section->receipt());
        $this->assertSame(['bob'], $after['scope']);
        $this->assertNotSame($before['token_id'], $after['token_id']);
        $this->assertScreenShows('alice bob');
    }

    public function test_denying_revokes_and_closes_the_port(): void
    {
        (new ApiAccess($this->host))->allow(User::findByUsername('alice'), ApiAccess::SCOPE_ALL, true);
        $this->keys([...self::row(2), Key::ENTER, Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new ApiAccessSection();
        $section->run(false);

        $this->assertSame(["Deny alice the Engine API: its token is revoked and the port is closed."], $section->receipt());
        $this->assertSame([], (new ApiAccess($this->host))->all());
        $this->assertStringNotContainsString('alice', $this->host->writes[ApiAccessFirewall::FILE][0]);
        $this->assertScreenShows('Takes effect now for a running project');
    }

    public function test_a_dry_run_of_a_rotate_or_deny_changes_nothing(): void
    {
        $before = (new ApiAccess($this->host))->allow(User::findByUsername('alice'), ApiAccess::SCOPE_SELF, false);
        $this->keys([...self::row(1), Key::ENTER, Key::ENTER, ...self::row(2), Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new ApiAccessSection();
        $section->run(true);

        $this->assertSame([
            'Mint a new token for alice, keeping its scope.',
            'Deny alice the Engine API: its token is revoked and the port is closed.',
        ], $section->receipt());
        $this->assertSame($before['token_id'], (new ApiAccess($this->host))->all()[0]['token_id']);
    }

    /** A host that keeps the files it is asked to write and runs nothing. */
    private function recordingHost(): System
    {
        return new class extends NoProcessHost {
            /** @var array<string, array{0: string, 1: ?string, 2: ?string}> */
            public array $writes = [];

            /** @var list<string|list<string>> */
            public array $commands = [];

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;

                return '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->writes[$path] = [$contents, $chown, $chmod];
                    }
                };
            }
        };
    }
}
