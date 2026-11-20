<?php

namespace Tests\Integration\Mcp;

use App\Console\Commands\Mcp\GenerateApiToolsCommand;
use App\Mcp\Servers\EngineServer;
use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\ApiTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use ReflectionClass;
use Tests\TestCase;

class GitToolsTest extends TestCase
{
    /** @var array<string, class-string<ApiTool>> */
    private const GIT_TOOLS = [
        'git_status' => \App\Mcp\Tools\Api\Git\GitStatusTool::class,
        'git_branches' => \App\Mcp\Tools\Api\Git\GitBranchesTool::class,
        'git_commits' => \App\Mcp\Tools\Api\Git\GitCommitsTool::class,
        'git_connect' => \App\Mcp\Tools\Api\Git\GitConnectTool::class,
        'git_disconnect' => \App\Mcp\Tools\Api\Git\GitDisconnectTool::class,
        'git_change_branch' => \App\Mcp\Tools\Api\Git\GitChangeBranchTool::class,
        'git_update_credentials' => \App\Mcp\Tools\Api\Git\GitUpdateCredentialsTool::class,
        'git_pull' => \App\Mcp\Tools\Api\Git\GitPullTool::class,
        'git_push' => \App\Mcp\Tools\Api\Git\GitPushTool::class,
        'git_revert' => \App\Mcp\Tools\Api\Git\GitRevertTool::class,
        'git_deploy_key_show' => \App\Mcp\Tools\Api\Git\GitDeployKeyShowTool::class,
        'git_deploy_hook_create' => \App\Mcp\Tools\Api\Git\GitDeployHookCreateTool::class,
        'git_deploy_hook_show' => \App\Mcp\Tools\Api\Git\GitDeployHookShowTool::class,
        'git_deploy_hook_rotate' => \App\Mcp\Tools\Api\Git\GitDeployHookRotateTool::class,
        'git_deploy_hook_delete' => \App\Mcp\Tools\Api\Git\GitDeployHookDeleteTool::class,
    ];

    /** Tokens every Git description must contain (path default). A tool that selects no checkout has no `path`. */
    private const PATH_LOCK = ['path', 'document root of the main domain'];

    /**
     * Extra lock tokens per tool. `reset --hard` / `Pull first`
     * are matched case-sensitively; everything else case-insensitively.
     *
     * @var array<string, list<string>>
     */
    private const LOCKS = [
        'git_status' => ['fetch', 'managed_by', 'deploy', 'site_git', 'project_rebuild'],
        'git_branches' => [],
        'git_commits' => ['branch', 'limit'],
        'git_connect' => ['repo_url', 'branch', 'token', 'repair', 'deploy'],
        'git_disconnect' => ['deploy', 'origin'],
        'git_change_branch' => ['branch', 'dirty', 'deploy', 'project_rebuild'],
        'git_update_credentials' => ['token', 'omit'],
        'git_pull' => ['ff', 'force', 'push_first', 'untracked', 'diverged', 'reset --hard', 'git_push_rejected', 'git_push_unauthorized'],
        'git_push' => ['commit', 'Pull first', 'deploy', 'git_push_rejected', 'git_push_unauthorized'],
        'git_revert' => ['reset --hard', 'clean', 'HEAD'],
        'git_deploy_key_show' => ['public_key', 'fingerprint', '404'],
        'git_deploy_hook_create' => ['secret', 'once', 'url', 'match the repository'],
        'git_deploy_hook_show' => ['secret', 'never', 'url'],
        'git_deploy_hook_rotate' => ['secret', 'once', 'old url', 'match the repository'],
        'git_deploy_hook_delete' => ['url', '404'],
    ];

    /** @return array<string, string> */
    private function map(): array
    {
        return require base_path('app/Mcp/tool-names.php');
    }

    public function test_the_map_names_the_fifteen_git_operations(): void
    {
        $expected = [
            'GET /projects/{username}/git/status' => 'git_status',
            'GET /projects/{username}/git/branches' => 'git_branches',
            'GET /projects/{username}/git/commits' => 'git_commits',
            'POST /projects/{username}/git/connect' => 'git_connect',
            'POST /projects/{username}/git/disconnect' => 'git_disconnect',
            'PUT /projects/{username}/git/change-branch' => 'git_change_branch',
            'PUT /projects/{username}/git/update-credentials' => 'git_update_credentials',
            'POST /projects/{username}/git/pull' => 'git_pull',
            'POST /projects/{username}/git/push' => 'git_push',
            'POST /projects/{username}/git/revert' => 'git_revert',
            'GET /projects/{username}/git/deploy-key' => 'git_deploy_key_show',
            'POST /projects/{username}/git/deploy-hook' => 'git_deploy_hook_create',
            'GET /projects/{username}/git/deploy-hook' => 'git_deploy_hook_show',
            'POST /projects/{username}/git/deploy-hook/rotate' => 'git_deploy_hook_rotate',
            'DELETE /projects/{username}/git/deploy-hook' => 'git_deploy_hook_delete',
        ];

        $map = $this->map();

        foreach ($expected as $operation => $name) {
            $this->assertArrayHasKey($operation, $map, "Add {$operation} to app/Mcp/tool-names.php");
            $this->assertSame($name, $map[$operation], "{$operation} must be named {$name}");
        }
    }

    public function test_server_instructions_tell_the_agent_how_to_use_git(): void
    {
        $attrs = (new ReflectionClass(EngineServer::class))->getAttributes(Instructions::class);
        $this->assertNotEmpty($attrs, 'EngineServer has no #[Instructions]');
        $text = (string) $attrs[0]->getArguments()[0];

        foreach (['git_status', 'managed_by', 'deploy', 'project_rebuild', 'git_push'] as $token) {
            $this->assertStringContainsString(
                $token,
                $text,
                "EngineServer #[Instructions] must mention {$token}"
            );
        }
    }

    public function test_generated_git_tools_exist_and_are_registered(): void
    {
        foreach (self::GIT_TOOLS as $name => $class) {
            $this->assertTrue(class_exists($class), "Missing {$class}. Run: php artisan mcp:tool:generate");
            $this->assertTrue(is_subclass_of($class, ApiTool::class), "{$class} is not an ApiTool");
            $this->assertSame($name, (new $class())->name());
        }

        config(['mcp-tools.tool_search' => false]);

        $server = new EngineServer(new FakeTransporter());
        $merged = (new ReflectionClass($server))->getProperty('tools');
        $merged->setAccessible(true);
        /** @var array<int, class-string> $registered */
        $registered = $merged->getValue($server);

        foreach (self::GIT_TOOLS as $class) {
            $this->assertContains($class, $registered, "{$class} is not registered on EngineServer");
        }
    }

    public function test_git_tool_descriptions_contain_the_lock_tokens(): void
    {
        $caseSensitive = ['reset --hard', 'Pull first'];

        foreach (self::LOCKS as $name => $extra) {
            $class = self::GIT_TOOLS[$name];
            $this->assertTrue(class_exists($class), "Missing {$class}. Run: php artisan mcp:tool:generate");
            $tool = new $class();
            $description = $tool->description();
            $schema = $tool->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory());
            $tokens = array_key_exists('path', $schema) ? array_merge(self::PATH_LOCK, $extra) : $extra;

            foreach ($tokens as $token) {
                if (in_array($token, $caseSensitive, true)) {
                    $this->assertStringContainsString(
                        $token,
                        $description,
                        "{$name} description must contain {$token}"
                    );
                    continue;
                }

                $this->assertStringContainsString(
                    strtolower($token),
                    strtolower($description),
                    "{$name} description must contain {$token}"
                );
            }
        }
    }

    /**
     * The three checkout changes a Deploy-managed project queues say a stopped container is started by the task, so an
     * assistant neither waits for a refusal nor starts the project itself first.
     */
    public function test_a_queued_checkout_change_says_its_task_starts_a_stopped_container(): void
    {
        foreach (['git_pull', 'git_revert', 'git_change_branch'] as $name) {
            $description = (new (self::GIT_TOOLS[$name])())->description();

            $this->assertStringContainsString('the answer is a task `id` at once (202)', $description, $name);
            $this->assertStringContainsString(
                'A stopped container does not refuse the change: the task starts the container before it, so do not '
                . 'start the project first, and a start that fails fails the task with its own error.',
                $description,
                $name,
            );
        }
    }

    /**
     * git_status is called first, so it is where an assistant meets a stopped container: it says the three checkout
     * changes start it themselves, so the user is not sent to start the project first.
     */
    public function test_git_status_says_a_stopped_container_is_started_by_the_checkout_changes(): void
    {
        $this->assertStringContainsString(
            'A stopped project container answers 503 "Git could not run: the project\'s container is not running (...)". '
            . 'On a managed_by deploy checkout git_pull, git_revert and git_change_branch start it themselves, so do not '
            . 'tell the user to start the project first.',
            (new (self::GIT_TOOLS['git_status'])())->description(),
        );
    }

    public function test_readonly_mode_keeps_only_the_git_gets(): void
    {
        foreach (self::GIT_TOOLS as $class) {
            $this->assertTrue(class_exists($class), "Missing {$class}. Run: php artisan mcp:tool:generate");
        }

        $policy = new ToolPolicy([
            'toolsets' => 'all',
            'permission_mode' => 'readonly',
        ]);
        $names = array_map(
            fn (string $c): string => $policy->nameOf($c),
            $policy->filter(array_values(self::GIT_TOOLS))
        );

        // Show is the one deploy-hook tool a read-only token keeps: it can
        // learn that a hook exists and where it points, never its secret.
        $this->assertEqualsCanonicalizing(
            ['git_status', 'git_branches', 'git_commits', 'git_deploy_hook_show', 'git_deploy_key_show'],
            $names
        );
    }

    /**
     * The modes follow the verbs: create and rotate hand out a secret, so
     * they are writes; delete is a DELETE and needs full.
     */
    public function test_deploy_hook_tools_follow_their_verbs_across_permission_modes(): void
    {
        $hookTools = array_filter(
            self::GIT_TOOLS,
            fn (string $name): bool => str_starts_with($name, 'git_deploy_hook_'),
            ARRAY_FILTER_USE_KEY
        );

        $kept = function (string $mode) use ($hookTools): array {
            $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => $mode]);

            return array_map(
                fn (string $c): string => $policy->nameOf($c),
                $policy->filter(array_values($hookTools))
            );
        };

        $this->assertEqualsCanonicalizing(['git_deploy_hook_show'], $kept('readonly'));
        $this->assertEqualsCanonicalizing(
            ['git_deploy_hook_show', 'git_deploy_hook_create', 'git_deploy_hook_rotate'],
            $kept('modify')
        );
        $this->assertEqualsCanonicalizing(array_keys($hookTools), $kept('full'));
    }

    public function test_deploy_hook_tools_expose_an_optional_path_and_never_a_secret_argument(): void
    {
        foreach (['git_deploy_hook_create', 'git_deploy_hook_show', 'git_deploy_hook_rotate', 'git_deploy_hook_delete'] as $name) {
            $class = self::GIT_TOOLS[$name];
            $schema = (new $class())->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory());

            $this->assertArrayHasKey('name', $schema, "{$name} takes the project as `name`");
            $this->assertArrayHasKey('path', $schema, "{$name} selects a checkout with `path`");
            $this->assertArrayNotHasKey('secret', $schema, "{$name} must not accept a secret");
        }
    }

    public function test_no_git_operation_is_declared_read_only_post(): void
    {
        foreach (GenerateApiToolsCommand::READ_ONLY_OPERATIONS as $operation) {
            $this->assertStringNotContainsString(
                '/git/',
                $operation,
                "{$operation} must not be in READ_ONLY_OPERATIONS"
            );
        }
    }
}
