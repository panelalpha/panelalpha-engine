<?php

namespace Tests\Integration\Mcp;

use App\Mcp\Servers\EngineServer;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\Api\ApiTool;
use App\Mcp\Tools\Api\Backups\BackupRestoreTool;
use App\Mcp\Tools\Api\Containers\AppHealthCheckTool;
use App\Mcp\Tools\Api\Deploy\ProjectInspectTool;
use App\Mcp\Tools\Api\MySQLServer\PhpmyadminSsoTokenCreateTool;
use App\Mcp\Tools\Api\Projects\ProjectCreateSyncTool;
use App\Mcp\Tools\Api\Projects\ProjectCreateTool;
use App\Mcp\Tools\Api\Projects\ProjectDeployArchiveTool;
use App\Mcp\Tools\Api\Projects\ProjectGetTool;
use App\Mcp\Tools\Api\SSLCertificates\SslCertRemoveTool;
use App\Mcp\Tools\Api\Tasks\TaskGetTool;
use App\Mcp\Tools\Api\WPCLI\WpDatabaseImportTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use ReflectionClass;
use Tests\TestCase;

/**
 * The instructions and descriptions are the whole advice an assistant gets
 * before it calls a tool, so the sentences that keep it from destroying data
 * without asking or abandoning a deploy are part of the surface.
 */
class AssistantGuidanceTest extends TestCase
{
    /** Overwrites that are not a DELETE and so cannot be found by their verb. */
    private const OVERWRITING = [
        'backup_restore' => BackupRestoreTool::class,
        'project_deploy_archive' => ProjectDeployArchiveTool::class,
        'wp_database_import' => WpDatabaseImportTool::class,
    ];

    /**
     * Every tool whose endpoint is a DELETE, read off the registry the server
     * is built from, so a route added without the sentences fails here.
     *
     * @return array<string, ApiTool>
     */
    private function deleteTools(): array
    {
        $tools = [];

        foreach (ToolRegistry::all() as $class) {
            $tool = new $class();
            if ($tool instanceof ApiTool && $tool->httpMethod() === 'DELETE') {
                $tools[$tool->name()] = $tool;
            }
        }

        return $tools;
    }

    public function test_every_delete_tool_says_what_is_lost_and_to_ask_first(): void
    {
        $tools = $this->deleteTools();

        $this->assertArrayHasKey('project_delete', $tools, 'the DELETE tools could not be read off the registry');
        $this->assertArrayHasKey('ssl_cert_remove', $tools);

        foreach ($tools as $name => $tool) {
            $description = $tool->description();

            $this->assertStringContainsString('cannot be undone', $description, "{$name} does not say it cannot be undone");
            $this->assertStringContainsString('confirm first', $description, "{$name} does not ask the operator first");
        }
    }

    public function test_every_overwriting_tool_says_what_is_lost_and_to_ask_first(): void
    {
        foreach (self::OVERWRITING as $name => $class) {
            $description = (new $class())->description();

            $this->assertStringContainsString('cannot be undone', $description, "{$name} does not say it cannot be undone");
            $this->assertStringContainsString('confirm first', $description, "{$name} does not ask the operator first");
        }
    }

    public function test_the_certificate_remove_says_the_installed_key_goes_with_it(): void
    {
        $description = (new SslCertRemoveTool())->description();

        $this->assertStringContainsString('private key', $description);
        $this->assertStringContainsString('gone for good', $description);
        $this->assertStringContainsString('keeps no copy', $description);
    }

    public function test_task_get_says_how_long_a_deploy_takes_and_to_poll_until_it_ends(): void
    {
        $description = (new TaskGetTool())->description();

        $this->assertStringContainsString('usually takes a few minutes', $description);
        $this->assertStringContainsString('keep polling', $description);
        $this->assertStringContainsString('`completed` or `failed`', $description);
        $this->assertStringContainsString('do not answer the user before that', $description);
    }

    public function test_the_create_points_at_the_synchronous_create_and_it_is_not_called_legacy(): void
    {
        $this->assertStringContainsString('project_create_sync', (new ProjectCreateTool())->description());
        $this->assertStringNotContainsString('Legacy', (new ProjectCreateSyncTool())->description());
    }

    public function test_the_pointer_tools_name_the_one_that_answers_the_question(): void
    {
        $this->assertStringContainsString('project_usage', (new ProjectGetTool())->description());
        $this->assertStringContainsString('only after a deploy', (new ProjectInspectTool())->description());

        $health = (new AppHealthCheckTool())->description();
        $this->assertStringContainsString('never the page', $health);
        $this->assertStringContainsString('ssh_run', $health);
    }

    public function test_the_phpmyadmin_link_lifetime_is_stated(): void
    {
        $description = (new PhpmyadminSsoTokenCreateTool())->description();

        $this->assertStringContainsString('single use', $description);
        $this->assertStringContainsString('5 minutes', $description);
    }

    public function test_the_instructions_ask_before_every_destructive_call_and_for_a_read_back(): void
    {
        $attributes = (new ReflectionClass(EngineServer::class))->getAttributes(Instructions::class);
        $instructions = $attributes[0]->newInstance()->value;

        $this->assertStringContainsString('before any destructive call ask the', $instructions);
        $this->assertStringContainsString('not only a production one', $instructions);
        $this->assertStringContainsString('After a change, read the state back', $instructions);
        $this->assertStringContainsString('vault_secret_create', $instructions);
    }
}
