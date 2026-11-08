<?php

namespace Tests\Unit\Console;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployBusyException;
use App\Exceptions\DockerErrorException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployFinishLine;
use App\Lib\Deploy\DeployLog\DeployHistory;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Findings\Finding;
use App\Lib\Http\OutboundUrl;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\TaskTrigger;
use App\Models\Admin;
use App\Models\DeployHook;
use App\Models\SecretVaultEntry;
use App\Models\Task;
use App\Models\User;
use App\System\Project\Git\CheckoutRedeploy;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\Unit\DeployHook\DeployHookTestCase;
use Tests\Unit\DeployHook\SpyCheckoutRedeploy;
use Tests\Unit\Git\FakesGitHost;

/**
 * Output, exit codes and side effects of the eleven `git:*` commands, with
 * the real engine code underneath and a fake host ({@see FakesGitHost}).
 */
class GitCommandsTest extends DeployHookTestCase
{
    use FakesGitHost;
    use RendersCommandFailures;

    private const PRETTY = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const REPO = 'https://github.com/octocat/Hello-World.git';

    private SpyCheckoutRedeploy $redeploy;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admins', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        config(['app.debug' => false]);

        $this->fakeGitHost();
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'github.com' ? [TestNetwork::routable(4)] : []);

        $this->redeploy = new SpyCheckoutRedeploy();
        $this->app->instance(CheckoutRedeploy::class, $this->redeploy);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs('alice');
        LocalRoot::answerByBroker();
        $this->restoreHost();
        OutboundUrl::setResolverForTests(null);
        Schema::dropIfExists('secret_vault_entries');
        Schema::dropIfExists('admins');

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{int, string}
     */
    private function runCommand(string $command, array $arguments): array
    {
        $exit = Artisan::call($command, $arguments);

        return [$exit, Artisan::output()];
    }

    /** @param array<string, mixed> $body */
    private function pretty(array $body): string
    {
        return json_encode($body, self::PRETTY) . "\n";
    }

    /** @return array<string, mixed> */
    private function envelope(string $path, string $managedBy, bool $connected, bool $exists = false): array
    {
        return [
            'path' => $path,
            'path_key' => $path,
            'managed_by' => $managedBy,
            'connected' => $connected,
            'repository_exists' => $exists,
            'remote_url' => null,
            'branch' => null,
            'dirty' => false,
            'tracking' => null,
            'commits_ahead' => null,
            'commits_behind' => null,
            'connecting' => false,
            'connecting_since' => null,
        ];
    }

    /** The remote's answer to `ls-remote --heads origin refs/heads/<branch>`: a pull or branch change is checked against it first. */
    private function remoteHas(string ...$branches): void
    {
        foreach ($branches as $branch) {
            $this->respond("'ls-remote' '--heads' 'origin' 'refs/heads/{$branch}'", 0, str_repeat('a', 40) . "\trefs/heads/{$branch}\n");
        }
    }

    /**
     * The account is stopped until the change starts it: docker runs no git in it before, so the check made first, as
     * the endpoint makes it, cannot read the checkout and leaves it to the change. `$onStart` runs as it is started.
     */
    private function stoppedUntilTheStart(?\Closure $onStart = null): void
    {
        $rules = "{$this->shim}/rules";
        $answered = (string) file_get_contents($rules);
        file_put_contents($rules, '');
        $this->respond('safe.directory=', 1, "service \"dind\" is not running\n");
        $refused = (string) file_get_contents($rules);
        file_put_contents($rules, $answered, FILE_APPEND);
        $this->redeploy->onStart = function () use ($rules, $refused, $onStart): void {
            file_put_contents($rules, substr((string) file_get_contents($rules), strlen($refused)));
            if ($onStart !== null) {
                $onStart();
            }
        };
    }

    private function siteGitPublicHtml(?string $token = null): User
    {
        $user = $this->siteGitUser();
        $user->putSiteGit('public_html', ['repo_url' => self::REPO, 'branch' => 'main', 'token' => $token]);

        return $user;
    }

    // -- not found ----------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function everyCommand(): array
    {
        return [
            'branches' => ['git:branches', []],
            'change-branch' => ['git:change-branch', ['--branch' => 'main']],
            'commits' => ['git:commits', []],
            'connect' => ['git:connect', ['--repo-url' => self::REPO, '--branch' => 'main']],
            'deploy-hook' => ['git:deploy-hook', []],
            'deploy-hook rotate' => ['git:deploy-hook', ['--rotate' => true]],
            'deploy-hook delete' => ['git:deploy-hook', ['--delete' => true]],
            'disconnect' => ['git:disconnect', []],
            'pull' => ['git:pull', []],
            'push' => ['git:push', []],
            'revert' => ['git:revert', []],
            'status' => ['git:status', []],
            'update-credentials' => ['git:update-credentials', ['--token' => 'abc']],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('everyCommand')]
    public function test_an_unknown_project_is_not_found(string $command, array $options): void
    {
        $this->assertSame("Project 'nobody' not found.\n", $this->failureOf($command, ['username' => 'nobody'] + $options));
    }

    /**
     * No router stands in front of the lookup, so such a name is looked up
     * like any other.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('everyCommand')]
    public function test_a_username_that_is_not_one_path_segment_is_not_found(string $command, array $options): void
    {
        foreach (['', 'a/b'] as $username) {
            $this->assertSame("Project '{$username}' not found.\n", $this->failureOf($command, ['username' => $username] + $options), $username);
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('everyCommand')]
    public function test_no_command_goes_through_the_router_or_mints_a_root_admin(string $command, array $options): void
    {
        $this->user('main');
        $this->withRepository();
        $routed = 0;
        Event::listen(RouteMatched::class, function () use (&$routed): void {
            $routed++;
        });

        foreach (['alice', 'nobody'] as $username) {
            try {
                $this->runCommand($command, ['username' => $username] + $options);
            } catch (NotFoundException|ValidationException|GitException) {
                // An unknown project, or a refusal; the router question is the same.
            }
        }

        $this->assertSame(0, $routed);
        $this->assertSame(0, Admin::count());
    }

    // -- validation ---------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function invalidInput(): array
    {
        return [
            'change-branch bad ref' => ['git:change-branch', ['--branch' => 'bad..ref'], 'Invalid git ref name.'],
            'commits limit not integer' => ['git:commits', ['--limit' => 'abc'], 'The limit must be an integer.'],
            'commits limit zero' => ['git:commits', ['--limit' => '0'], 'The limit must be at least 1.'],
            'commits bad branch' => ['git:commits', ['--branch' => 'a..b'], 'Invalid git ref name.'],
            'connect bad url' => ['git:connect', ['--repo-url' => 'not-a-url', '--branch' => 'main'], 'The repo url must be a valid URL.'],
            'connect bad branch' => ['git:connect', ['--repo-url' => self::REPO, '--branch' => 'a..b'], 'Invalid git ref name.'],
            'pull bad strategy' => ['git:pull', ['--strategy' => 'rebase'], 'The selected strategy is invalid.'],
            'revert bad ref' => ['git:revert', ['--ref' => 'a..b'], 'Invalid git ref name.'],
            'deploy-hook bad provider' => ['git:deploy-hook', ['--provider' => 'sourceforge'], 'The selected provider is invalid.'],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_refused_before_the_project_is_looked_up(string $command, array $options, string $message): void
    {
        foreach (['alice', 'nobody'] as $username) {
            if ($username === 'alice' && User::findByUsername('alice') === null) {
                $this->user('main');
            }

            $this->assertSame("{$message}\n", $this->failureOf($command, ['username' => $username] + $options));
        }
    }

    public function test_every_validation_message_is_printed_on_its_own_line(): void
    {
        $this->assertSame("The limit must be an integer.\nInvalid git ref name.\n", $this->failureOf('git:commits', ['username' => 'nobody', '--limit' => 'abc', '--branch' => 'a..b']));
    }

    public function test_a_path_outside_the_home_is_refused(): void
    {
        $this->user('main');

        $this->assertSame("Invalid path\n", $this->failureOf('git:status', ['username' => 'alice', '--path' => '../../etc']));
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function missingOption(): array
    {
        return [
            'change-branch' => ['git:change-branch', [], "--branch is required\n"],
            'connect repo-url' => ['git:connect', ['--branch' => 'main'], "--repo-url is required\n"],
            'connect branch' => ['git:connect', ['--repo-url' => self::REPO], "--branch is required\n"],
            'deploy-hook rotate and delete' => ['git:deploy-hook', ['--rotate' => true, '--delete' => true], "--rotate and --delete cannot be used together.\n"],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('missingOption')]
    public function test_the_command_refuses_before_asking_the_engine(string $command, array $options, string $expected): void
    {
        [$exit, $output] = $this->runCommand($command, ['username' => 'nobody'] + $options);

        $this->assertSame(1, $exit);
        $this->assertSame($expected, $output);
        $this->assertSame([], $this->calls());
    }

    // -- status -------------------------------------------------------------

    public function test_status_without_a_repository(): void
    {
        $this->user('main');
        $this->withoutRepository();

        [$exit, $output] = $this->runCommand('git:status', ['username' => 'alice']);

        $this->assertSame(0, $exit);
        $this->assertSame($this->pretty(['data' => $this->envelope('project', 'deploy', true)]), $output);
    }

    public function test_status_of_a_stopped_container_names_it_rather_than_a_missing_repository(): void
    {
        $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");

        $this->assertSame(
            "Git could not run: the project's container is not running (service \"dind\" is not running)\n",
            $this->failureOf('git:status', ['username' => 'alice']),
        );
    }

    public function test_status_raw_prints_compact_json(): void
    {
        $this->siteGitUser();
        $this->withoutRepository();

        [$exit, $output] = $this->runCommand('git:status', ['username' => 'alice', '--path' => 'public_html', '--raw' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(json_encode(['data' => $this->envelope('public_html', 'site_git', true)]) . "\n", $output);
    }

    public function test_status_fetch_fetches_and_reports_tracking(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'config' '--get' 'remote.origin.url'", 0, "https://user:secret@github.com/octocat/Hello-World.git\n");
        $this->respond("'branch' '--show-current'", 0, "main\n");
        $this->respond("'status' '--porcelain'", 0, " M index.php\n");
        $this->respond("'rev-parse' '--abbrev-ref' '@{upstream}'", 0, "origin/main\n");
        $this->respond("'@{upstream}..HEAD'", 0, "1\n");
        $this->respond("'HEAD..@{upstream}'", 0, "3\n");

        [$exit, $output] = $this->runCommand('git:status', ['username' => 'alice', '--fetch' => true]);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'fetch' 'origin'"));
        $this->assertSame($this->pretty(['data' => [
            'path' => 'project',
            'path_key' => 'project',
            'managed_by' => 'deploy',
            'connected' => true,
            'repository_exists' => true,
            'remote_url' => 'https://github.com/octocat/Hello-World.git',
            'branch' => 'main',
            'dirty' => true,
            'tracking' => 'origin/main',
            'commits_ahead' => 1,
            'commits_behind' => 3,
            'connecting' => false,
            'connecting_since' => null,
        ]]), $output);
    }

    public function test_status_without_fetch_does_not_fetch(): void
    {
        $this->user('main');
        $this->withRepository();

        [$exit] = $this->runCommand('git:status', ['username' => 'alice']);

        $this->assertSame(0, $exit);
        $this->assertFalse($this->called("'fetch'"));
    }

    public function test_a_git_failure_prints_what_git_said(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'fetch' 'origin'", 1, "fatal: unable to access 'https://user:pw@github.com/x.git/': timeout");

        $this->assertSame("fatal: unable to access 'https://user:pw@github.com/x.git/': timeout\n", $this->failureOf('git:status', ['username' => 'alice', '--fetch' => true]));
    }

    public function test_status_fetch_on_an_unconnected_checkout_is_refused(): void
    {
        $this->user('main', ['git_repo' => '']);
        $this->withRepository();

        $this->assertSame("Git is not connected.\n", $this->failureOf('git:status', ['username' => 'alice', '--fetch' => true]));
    }

    // -- branches / commits -------------------------------------------------

    public function test_branches(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'for-each-ref'", 0, implode("\n", [
            "refs/heads/main\x1fmain\x1forigin/main\x1f*",
            "refs/remotes/origin/HEAD\x1forigin/HEAD\x1f\x1f",
            "refs/remotes/origin/main\x1forigin/main\x1f\x1f",
            "refs/remotes/origin/dev\x1forigin/dev\x1f\x1f",
        ]) . "\n");

        [$exit, $output] = $this->runCommand('git:branches', ['username' => 'alice']);

        $this->assertSame(0, $exit);
        $this->assertSame($this->pretty(['data' => [
            ['name' => 'main', 'current' => true, 'tracking' => 'origin/main'],
            ['name' => 'dev', 'current' => false, 'tracking' => 'origin/dev'],
        ]]), $output);
    }

    public function test_branches_without_a_repository_is_refused(): void
    {
        $this->user('main');
        $this->withoutRepository();

        $this->assertSame("No git repository at path\n", $this->failureOf('git:branches', ['username' => 'alice']));
    }

    public function test_commits_defaults_to_fifty(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'log'", 0, "abc123\x1fabc\x1fFix it\x1fOcto Cat\x1f2026-09-30T10:00:00+00:00\n");

        [$exit, $output] = $this->runCommand('git:commits', ['username' => 'alice']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'log' '-n' '50' '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI'"));
        $this->assertSame($this->pretty(['data' => [[
            'hash' => 'abc123',
            'short_hash' => 'abc',
            'subject' => 'Fix it',
            'author' => 'Octo Cat',
            'date' => '2026-09-30T10:00:00+00:00',
        ]]]), $output);
    }

    public function test_commits_passes_limit_and_branch(): void
    {
        $this->user('main');
        $this->withRepository();

        [$exit, $output] = $this->runCommand('git:commits', ['username' => 'alice', '--limit' => '5', '--branch' => 'dev']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'log' '-n' '5' '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI' 'dev'"));
        $this->assertSame("{\n    \"data\": []\n}\n", $output);
    }

    public function test_commits_with_an_empty_limit_asks_git_for_zero(): void
    {
        $this->user('main');
        $this->withRepository();

        [$exit] = $this->runCommand('git:commits', ['username' => 'alice', '--limit' => '']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'log' '-n' '0'"));
    }

    // -- connect / disconnect / credentials --------------------------------

    public function test_connect_on_the_deploy_checkout_keeps_origin_in_sync(): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->respond("'config' '--get' 'remote.origin.url'", 0, self::REPO . "\n");

        [$exit, $output] = $this->runCommand('git:connect', [
            'username' => 'alice', '--repo-url' => self::REPO, '--branch' => 'dev', '--token' => 'ghp_x',
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'remote' 'set-url' 'origin' '" . self::REPO . "'"));
        $data = json_decode($output, true)['data'];
        $this->assertSame('deploy', $data['managed_by']);
        $this->assertSame('https://github.com/octocat/Hello-World.git', $data['remote_url']);
        $details = $user->fresh()->getDetails();
        $this->assertSame('dev', $details['git_branch']);
        $this->assertSame('ghp_x', $details['git_token']);
    }

    public function test_connect_to_a_different_origin_is_refused(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'config' '--get' 'remote.origin.url'", 0, "https://github.com/someone/else.git\n");

        $this->assertSame("Remote URL does not match the existing origin.\n", $this->failureOf('git:connect', [
            'username' => 'alice', '--repo-url' => self::REPO, '--branch' => 'main',
        ]));
    }

    public function test_connect_while_the_project_is_being_created_is_refused(): void
    {
        $this->siteGitUser();
        Task::start(DeployProject::class, 'default', 'alice');

        $this->assertSame(
            "Project 'alice' is still being created; try again when its deploy has finished.\n",
            $this->failureOf('git:connect', ['username' => 'alice', '--path' => 'public_html', '--repo-url' => self::REPO, '--branch' => 'main']),
        );
    }

    public function test_connect_repair_needs_no_url_or_branch(): void
    {
        $this->siteGitUser();
        $this->withoutRepository();

        $this->assertSame("Git is not connected.\n", $this->failureOf('git:connect', ['username' => 'alice', '--path' => 'wp-content', '--repair' => true]));
    }

    public function test_disconnect_on_the_deploy_checkout_is_refused(): void
    {
        $this->user('main');

        $this->assertSame("Git is managed by deploy.\n", $this->failureOf('git:disconnect', ['username' => 'alice']));
    }

    public function test_disconnect_forgets_the_checkout_and_its_hook(): void
    {
        $user = $this->siteGitPublicHtml();
        $this->hook($user, 'secret-of-the-site-checkout', 'public_html');
        $this->withoutRepository();

        [$exit, $output] = $this->runCommand('git:disconnect', ['username' => 'alice', '--path' => 'public_html']);

        $this->assertSame(0, $exit);
        $this->assertSame($this->pretty(['data' => $this->envelope('public_html', 'site_git', false)]), $output);
        $this->assertNull($user->fresh()->getSiteGit('public_html'));
        $this->assertSame(0, DeployHook::count());
    }

    public function test_update_credentials_stores_clears_or_leaves_the_token(): void
    {
        $user = $this->siteGitPublicHtml('old');
        $this->withoutRepository();

        [$exit, $output] = $this->runCommand('git:update-credentials', ['username' => 'alice', '--path' => 'public_html']);
        $this->assertSame(0, $exit);
        $this->assertSame($this->pretty(['data' => $this->envelope('public_html', 'site_git', true)]), $output);
        $this->assertSame('old', $user->fresh()->getSiteGit('public_html')['token']);

        [$exit] = $this->runCommand('git:update-credentials', ['username' => 'alice', '--path' => 'public_html', '--token' => 'new']);
        $this->assertSame(0, $exit);
        $this->assertSame('new', $user->fresh()->getSiteGit('public_html')['token']);

        [$exit] = $this->runCommand('git:update-credentials', ['username' => 'alice', '--path' => 'public_html', '--token' => '']);
        $this->assertSame(0, $exit);
        $this->assertNull($user->fresh()->getSiteGit('public_html')['token']);
    }

    /**
     * The token is the project's record, which git is not needed for: on a stopped container it is stored, and the
     * answer says the checkout could not be read rather than failing as if nothing had changed.
     */
    public function test_update_credentials_on_a_stopped_container_stores_the_token_and_says_the_checkout_was_not_read(): void
    {
        $user = $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");

        [$exit, $output] = $this->runCommand('git:update-credentials', ['username' => 'alice', '--token' => 'new-token']);

        $this->assertSame(0, $exit);
        $this->assertSame($this->pretty(['data' => [
            'path' => 'project',
            'path_key' => 'project',
            'managed_by' => 'deploy',
            'connected' => true,
            'warning' => 'The credentials were stored, but the checkout could not be read: '
                . "Git could not run: the project's container is not running (service \"dind\" is not running)",
        ]]), $output);
        $this->assertSame('new-token', $user->fresh()->getGitToken());
        $this->assertSame('new-token', $user->fresh()->getSiteGit('project')['token']);
    }

    /** Without a token nothing is stored, so a container git cannot run in is still the answer. */
    public function test_update_credentials_without_a_token_on_a_stopped_container_names_it(): void
    {
        $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");

        $this->assertSame(
            "Git could not run: the project's container is not running (service \"dind\" is not running)\n",
            $this->failureOf('git:update-credentials', ['username' => 'alice']),
        );
    }

    public function test_a_vault_reference_is_stored_as_its_secret(): void
    {
        $user = $this->siteGitPublicHtml();
        $this->withoutRepository();
        $entry = $this->vaultEntry('ghp_from_vault');

        [$exit] = $this->runCommand('git:update-credentials', ['username' => 'alice', '--path' => 'public_html', '--token' => $entry->reference()]);

        $this->assertSame(0, $exit);
        $this->assertSame('ghp_from_vault', $user->fresh()->getSiteGit('public_html')['token']);
        $this->assertSame('alice', $entry->refresh()->project);
    }

    /** The entry is not read for a project that does not exist, so it is neither claimed nor counted as used. */
    public function test_a_vault_reference_for_an_unknown_project_is_not_read(): void
    {
        $entry = $this->vaultEntry('ghp_from_vault');

        foreach (['git:update-credentials' => [], 'git:connect' => ['--repo-url' => self::REPO, '--branch' => 'main']] as $command => $options) {
            $failure = $this->failureOf($command, ['username' => 'nobody', '--token' => $entry->reference()] + $options);

            $this->assertSame("Project 'nobody' not found.\n", $failure, $command);
        }
        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, $entry->use_count);
    }

    public function test_a_git_token_vault_reference_is_stored_as_its_secret(): void
    {
        $user = $this->siteGitPublicHtml();
        $this->withoutRepository();
        $entry = $this->vaultEntry('ghp_from_git_token', SecretVaultEntry::TYPE_GIT_TOKEN);

        [$exit] = $this->runCommand('git:update-credentials', ['username' => 'alice', '--path' => 'public_html', '--token' => $entry->reference()]);

        $this->assertSame(0, $exit);
        $this->assertSame('ghp_from_git_token', $user->fresh()->getSiteGit('public_html')['token']);
    }

    public function test_an_unknown_vault_reference_is_refused_not_stored(): void
    {
        $user = $this->siteGitPublicHtml('old');
        $this->withoutRepository();
        $this->vaultEntry('unused');

        foreach (['git:update-credentials' => [], 'git:connect' => ['--repo-url' => self::REPO, '--branch' => 'main']] as $command => $options) {
            $failure = $this->failureOf($command, ['username' => 'alice', '--path' => 'public_html', '--token' => 'vault:999999'] + $options);

            $this->assertStringContainsString("No vault entry for 'vault:999999'", $failure, $command);
        }
        $this->assertSame('old', $user->fresh()->getSiteGit('public_html')['token']);
    }

    /** A project-scoped entry no project owns yet, with its secret pasted. */
    private function vaultEntry(string $secret, string $type = 'token'): SecretVaultEntry
    {
        if (!Schema::hasTable('secret_vault_entries')) {
            Schema::create('secret_vault_entries', function ($table) {
                $table->id();
                $table->char('ref_hash', 64)->unique();
                $table->string('ref_key', 64)->nullable()->unique();
                $table->string('type');
                $table->string('scope', 16)->default(SecretVaultEntry::SCOPE_PROJECT);
                $table->string('project', 64)->nullable();
                $table->string('purpose', 255)->nullable();
                $table->json('verify_with')->nullable();
                $table->json('verification')->nullable();
                $table->text('secret_encrypted')->nullable();
                $table->timestamp('filled_at')->nullable();
                $table->timestamp('link_expires_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedInteger('use_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        /** @var SecretVaultEntry */
        $entry = SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref' . bin2hex(random_bytes(8))),
            'type' => $type,
            'link_expires_at' => now()->addHour(),
        ]);
        $entry->setSecret($secret);
        $entry->save();

        return $entry;
    }

    public function test_update_credentials_on_an_unconnected_checkout_is_refused(): void
    {
        $this->siteGitUser();

        $this->assertSame("Git is not connected.\n", $this->failureOf('git:update-credentials', ['username' => 'alice', '--token' => 'x']));
    }

    // -- pull / push / revert / change-branch -------------------------------

    public function test_pull_force_on_the_deploy_checkout_rebuilds_the_app(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');

        [$exit, $output] = $this->runCommand('git:pull', ['username' => 'alice', '--strategy' => 'force']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'reset' '--hard' 'origin/main'"));
        $this->assertSame($this->pretty(['data' => $this->envelope('project', 'deploy', true, true)]), $output);
        $this->assertCount(1, $this->redeploy->calls);
        $this->assertSame(['source' => 'git', 'commit' => null], array_diff_key($this->redeploy->calls[0], ['logger' => true]));
        $this->assertSame(DeployLogger::readLatestFor('alice')['id'], $this->redeploy->calls[0]['logger']?->getDeployId());
    }

    /** A container still not running once its start returned is named, and nothing is rebuilt. */
    public function test_pull_on_a_container_the_start_left_stopped_names_it_and_does_not_rebuild(): void
    {
        $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");

        $this->assertSame(
            "Git could not run: the project's container is not running (service \"dind\" is not running)\n",
            $this->failureOf('git:pull', ['username' => 'alice', '--strategy' => 'force']),
        );
        $this->assertCount(1, $this->redeploy->starts);
        $this->assertFalse($this->called("'reset'"));
        $this->assertSame([], $this->redeploy->calls);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function checkoutChanges(): array
    {
        return [
            'pull' => ['git:pull', ['--strategy' => 'force'], "'reset' '--hard' 'origin/main'"],
            'revert' => ['git:revert', ['--ref' => 'abc123'], "'reset' '--hard' 'abc123'"],
            'change-branch' => ['git:change-branch', ['--branch' => 'dev'], "'checkout' '-B' 'dev' 'origin/dev'"],
        ];
    }

    /** Git runs in the account's container: a change to the Deploy-managed checkout starts a stopped account first, as a push does. */
    #[DataProvider('checkoutChanges')]
    public function test_a_checkout_change_starts_the_account_before_git_runs(string $command, array $options, string $change): void
    {
        $this->user('main');
        $this->withRepository();
        $changedBeforeTheStart = null;
        $this->stoppedUntilTheStart(function () use ($change, &$changedBeforeTheStart): void {
            $changedBeforeTheStart = $this->called($change);
        });

        [$exit] = $this->runCommand($command, ['username' => 'alice'] + $options);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $this->redeploy->starts);
        $this->assertFalse($changedBeforeTheStart, 'the checkout changed before the account was started');
        $this->assertTrue($this->called($change));
        $this->assertCount(1, $this->redeploy->calls);
    }

    /** A stopped account whose start fails answers with the start's own error, and git never runs in it. */
    public function test_a_checkout_change_whose_start_fails_prints_the_start_error_and_runs_no_git(): void
    {
        $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");
        $this->redeploy->startFailure = ProblemException::deploy(
            'sysbox-not-running',
            'The sysbox runtime is not running on the engine host (sysbox-fs failed)',
            'preparing',
        );

        $this->assertSame(
            "The sysbox runtime is not running on the engine host (sysbox-fs failed)\n",
            $this->failureOf('git:pull', ['username' => 'alice', '--strategy' => 'force']),
        );
        $this->assertSame(
            [],
            array_values(array_filter($this->calls(), static fn (string $call): bool => ! str_contains($call, "'--is-inside-work-tree'"))),
            'git ran in an account that did not start, beyond the check docker refused',
        );
        $this->assertSame([], $this->redeploy->calls);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function recordedCheckoutChanges(): array
    {
        return [
            'pull' => ['git:pull', ['--strategy' => 'force'], ProjectRebuild::GIT_PULL],
            'revert' => ['git:revert', ['--ref' => 'abc123'], ProjectRebuild::REVERT],
            'change-branch' => ['git:change-branch', ['--branch' => 'dev'], ProjectRebuild::CHANGE_BRANCH],
        ];
    }

    /**
     * The change runs as the queued job runs it: its deploy holds the account's lock from before the start, so a push
     * or a deploy asked for meanwhile is turned away as it is from the job, and the history records it.
     */
    #[DataProvider('recordedCheckoutChanges')]
    public function test_a_checkout_change_holds_the_deploy_lock_from_the_start_and_is_recorded(string $command, array $options, string $action): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->remoteHas('main', 'dev');
        $whileStarting = [];
        $this->redeploy->onStart = function () use ($user, &$whileStarting): void {
            $whileStarting = [
                'locked' => DeployLogger::isLockedFor('alice'),
                'push' => self::refusalOf(static fn () => DeployLogger::start('alice')),
                'deploy' => self::refusalOf(static fn () => app(ProjectRebuild::class)->assertIdle($user)),
            ];
        };

        [$exit] = $this->runCommand($command, ['username' => 'alice'] + $options);

        $this->assertSame(0, $exit);
        $this->assertSame(['locked' => true, 'push' => DeployAlreadyRunningException::class, 'deploy' => DeployBusyException::class], $whileStarting);
        $this->assertSame($this->redeploy->starts[0]?->getDeployId(), $this->redeploy->calls[0]['logger']?->getDeployId(), 'the start and the rebuild ran under one deploy');
        $history = DeployHistory::of($user);
        $this->assertCount(1, $history);
        $this->assertSame(
            ['status' => DeployLogger::STATUS_SUCCESS, 'trigger' => TaskTrigger::CLI, 'action' => $action],
            array_intersect_key($history[0], array_flip(['status', 'trigger', 'action'])),
        );
        $this->assertFalse(DeployLogger::isLockedFor('alice'), 'the lock went with the deploy');
    }

    /** A start that fails is a failed deploy in the history, beside the project it marks failed. */
    public function test_a_checkout_change_whose_start_fails_is_a_failed_deploy_in_the_history(): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');
        $this->redeploy->startFailure = ProblemException::deploy('sysbox-not-running', 'The sysbox runtime is not running on the engine host', 'preparing');

        $this->failureOf('git:pull', ['username' => 'alice', '--strategy' => 'force']);

        $history = DeployHistory::of($user);
        $this->assertCount(1, $history);
        $this->assertSame(
            [
                'status' => DeployLogger::STATUS_FAILED,
                'error' => 'Could not start the account: The sysbox runtime is not running on the engine host',
                'trigger' => TaskTrigger::CLI,
                'action' => ProjectRebuild::GIT_PULL,
            ],
            array_intersect_key($history[0], array_flip(['status', 'error', 'trigger', 'action'])),
        );
        $this->assertFalse(DeployLogger::isLockedFor('alice'));
    }

    /**
     * The rebuild finishes the change's deploy partial, and the push parked behind it starts its own deploy at once:
     * the command ends without touching that deploy, and the change stays partial with the one verdict it wrote.
     */
    public function test_a_push_deploy_started_once_the_change_finished_is_left_to_itself(): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');
        $push = null;
        $this->redeploy->onRebuild = static function (?DeployLogger $change) use (&$push): void {
            $change?->finishPartial([new Finding('app-not-answering', 'The application did not answer')]);
            $push = DeployLogger::start('alice');
        };

        [$exit] = $this->runCommand('git:pull', ['username' => 'alice', '--strategy' => 'force']);

        $this->assertSame(0, $exit);
        $this->assertTrue($push->isRunning(), 'the push deploy is still its own, running');
        $this->assertNull(DeployLogger::readLatestFor('alice')['finished_at']);
        $change = $this->redeploy->calls[0]['logger'];
        $verdicts = array_values(array_filter($change->entries(), static fn (array $line): bool => DeployFinishLine::find([$line]) !== null));
        $this->assertSame(['Deploy finished with warnings: The application did not answer'], array_column($verdicts, 'msg'));
        $this->assertSame(DeployLogger::STATUS_PARTIAL, DeployHistory::find($user, $change->getDeployId())['status']);
        $push->finish(DeployLogger::STATUS_SUCCESS);
    }

    /** The class of what `$attempt` threw, null when it did not. */
    private static function refusalOf(callable $attempt): ?string
    {
        try {
            $attempt();
        } catch (\Throwable $e) {
            return $e::class;
        }

        return null;
    }

    public function test_pull_on_a_site_git_checkout_does_not_rebuild(): void
    {
        $this->siteGitPublicHtml();
        $this->withRepository();

        [$exit] = $this->runCommand('git:pull', ['username' => 'alice', '--path' => 'public_html', '--strategy' => 'force']);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $this->redeploy->requests);
        $this->assertSame([], $this->redeploy->calls);
        $this->assertSame([], $this->redeploy->starts, 'a Site Git checkout is on the host: no account is started for it');
    }

    public function test_pull_on_an_unconnected_checkout_is_refused_and_does_not_rebuild(): void
    {
        $this->user('main', ['git_repo' => '']);

        $this->assertSame("Git is not connected.\n", $this->failureOf('git:pull', ['username' => 'alice']));
        $this->assertSame(0, $this->redeploy->requests);
    }

    public function test_push_on_an_unconnected_checkout_is_refused(): void
    {
        $this->user('main', ['git_repo' => '']);

        $this->assertSame("Git is not connected.\n", $this->failureOf('git:push', ['username' => 'alice']));
    }

    public function test_push_with_nothing_to_push(): void
    {
        $this->siteGitPublicHtml();
        $this->withRepository();

        [$exit, $output] = $this->runCommand('git:push', ['username' => 'alice', '--path' => 'public_html']);

        $this->assertSame(0, $exit);
        $this->assertSame(
            $this->pretty(['data' => $this->envelope('public_html', 'site_git', true, true) + ['nothing_to_push' => true]]),
            $output,
        );
        $this->assertSame(0, $this->redeploy->requests);
    }

    public function test_revert_to_a_ref_rebuilds_the_deploy_checkout(): void
    {
        $this->user('main');
        $this->withRepository();

        [$exit] = $this->runCommand('git:revert', ['username' => 'alice', '--ref' => 'abc123']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'reset' '--hard' 'abc123'"));
        $this->assertCount(1, $this->redeploy->calls);
    }

    /** Refused as the endpoint refuses it before queueing: on a running account no deploy is opened for it. */
    public function test_revert_with_no_head_is_refused_before_any_deploy(): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->respond("'rev-parse' '--verify'", 128, 'fatal: bad revision');

        $this->assertSame("Nothing to revert\n", $this->failureOf('git:revert', ['username' => 'alice']));
        $this->assertSame(0, $this->redeploy->requests);
        $this->assertSame([], $this->redeploy->starts);
        $this->assertSame([], DeployHistory::of($user), 'a refused change is no failed deploy');
        $this->assertNull(DeployLogger::readLatestFor('alice'));
    }

    public function test_change_branch_switches_and_rebuilds(): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->remoteHas('dev');

        [$exit] = $this->runCommand('git:change-branch', ['username' => 'alice', '--branch' => 'dev']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->called("'checkout' '-B' 'dev' 'origin/dev'"));
        $this->assertSame('dev', $user->fresh()->getDetails()['git_branch']);
        $this->assertCount(1, $this->redeploy->calls);
    }

    public function test_change_branch_on_a_dirty_tree_is_refused(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'status' '--porcelain'", 0, " M index.php\n");

        $this->assertSame("Working tree is dirty.\n", $this->failureOf('git:change-branch', ['username' => 'alice', '--branch' => 'dev']));
    }

    // -- what a failed rebuild turns into -----------------------------------

    public function test_a_running_deploy_prints_its_message(): void
    {
        $this->app->instance(CheckoutRedeploy::class, new SpyCheckoutRedeploy(new DeployAlreadyRunningException('A deploy is already running for alice.')));
        $this->user('main');
        $this->withRepository();

        $this->assertSame("A deploy is already running for alice.\n", $this->failureOf('git:revert', ['username' => 'alice', '--ref' => 'abc123']));
    }

    public function test_a_docker_error_prints_its_message(): void
    {
        $this->app->instance(CheckoutRedeploy::class, new SpyCheckoutRedeploy(new DockerErrorException("Error response from daemon: container alice is restarting\n")));
        $this->user('main');
        $this->withRepository();

        $this->assertSame("Error response from daemon: container alice is restarting\n", $this->failureOf('git:revert', ['username' => 'alice', '--ref' => 'abc123']));
    }

    /** A rebuild failing in a way nothing explains is still a failed deploy, printed in its own words. */
    public function test_an_unexpected_rebuild_failure_fails_its_deploy(): void
    {
        $this->app->instance(CheckoutRedeploy::class, new SpyCheckoutRedeploy(new \RuntimeException('kaboom')));
        $user = $this->user('main');
        $this->withRepository();

        $this->assertSame("kaboom\n", $this->failureOf('git:revert', ['username' => 'alice', '--ref' => 'abc123']));
        $this->assertSame(DeployLogger::STATUS_FAILED, DeployHistory::of($user)[0]['status']);
        $this->assertFalse(DeployLogger::isLockedFor('alice'));
    }

    // -- deploy hook --------------------------------------------------------

    public function test_deploy_hook_raw_create_prints_the_data_compact(): void
    {
        $this->user('main');

        [$exit, $output] = $this->runCommand('git:deploy-hook', ['username' => 'alice', '--raw' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('{"data":{"url":"https:\/\/'.TestNetwork::public(10).'\/hooks\/', $output);
        $hook = DeployHook::firstOrFail();
        $this->assertSame(json_encode(['data' => [
            'url' => $hook->url(),
            'path' => 'project',
            'created_at' => $hook->created_at?->toIso8601String(),
            'updated_at' => $hook->updated_at?->toIso8601String(),
            'warning' => \App\Lib\DeployHook\DeployHooks::FORCE_PULL_WARNING,
            'created' => true,
            'tls' => \App\Lib\DeployHook\EngineTlsAdvisory::forProvider(null),
            'registration' => null,
            'secret' => $hook->secret(),
        ]]) . "\n", $output);
    }

    public function test_deploy_hook_delete_prints_deleted(): void
    {
        $this->hook($this->user('main'));

        [$exit, $output] = $this->runCommand('git:deploy-hook', ['username' => 'alice', '--delete' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame("{\"data\":{\"deleted\":true}}\n", $output);
    }

    public function test_deploy_hook_rotate_or_delete_without_a_hook_is_not_found(): void
    {
        $this->user('main');

        foreach ([['--rotate' => true], ['--delete' => true]] as $options) {
            $this->assertSame("Deploy hook not found for checkout 'project' in project 'alice'.\n", $this->failureOf('git:deploy-hook', ['username' => 'alice'] + $options));
        }
    }

    public function test_deploy_hook_on_an_unconnected_checkout_is_refused(): void
    {
        $this->user('main', ['git_repo' => '']);

        $this->assertSame("The checkout is not connected to git, so there is nothing for a push to deploy.\n", $this->failureOf('git:deploy-hook', ['username' => 'alice']));
        $this->assertSame(0, DeployHook::count());
    }
}
