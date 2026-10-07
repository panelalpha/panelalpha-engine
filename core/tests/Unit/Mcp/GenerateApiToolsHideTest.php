<?php

namespace Tests\Unit\Mcp;

use App\Http\Controllers\TaskController;
use App\Http\Controllers\User\AppUserController;
use App\Http\Controllers\User\MysqlController;
use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `x-mcp-hide` on an operation keeps it in the REST API and out of MCP: the
 * generator writes no tool for it and the name map must not name it.
 */
class GenerateApiToolsHideTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/mcp-hide-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir . '/out'), 0755, true);
        file_put_contents(base_path($this->dir . '/spec.json'), json_encode(['paths' => [
            '/things' => ['get' => ['summary' => 'List things', 'tags' => ['Things']]],
            '/things/stream' => ['get' => ['summary' => 'Stream things', 'tags' => ['Things'], 'x-mcp-hide' => true]],
        ]]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $names */
    private function generate(array $names): int
    {
        file_put_contents(base_path($this->dir . '/names.php'), '<?php return ' . var_export($names, true) . ';');

        return $this->artisan('mcp:tool:generate', [
            '--spec' => $this->dir . '/spec.json',
            '--out' => $this->dir . '/out',
            '--names' => $this->dir . '/names.php',
        ])->run();
    }

    public function test_a_hidden_operation_gets_no_tool(): void
    {
        $this->assertSame(0, $this->generate(['GET /things' => 'thing_list']));

        $registry = (string)file_get_contents(base_path($this->dir . '/out/generated-tools.php'));
        $this->assertStringContainsString('ThingListTool', $registry);
        $this->assertStringNotContainsString('Stream', $registry);
    }

    public function test_naming_a_hidden_operation_is_refused_as_stale(): void
    {
        $this->assertSame(1, $this->generate([
            'GET /things' => 'thing_list',
            'GET /things/stream' => 'thing_stream',
        ]));
    }

    /**
     * A tool answers once, so a stream can only ever come back as an error.
     */
    public function test_the_task_log_stream_is_hidden_from_mcp(): void
    {
        $attribute = (new ReflectionMethod(TaskController::class, 'streamLogs'))
            ->getAttributes(OA\Get::class)[0]->newInstance();

        $this->assertSame(['mcp-hide' => true], $attribute->x);
    }

    /**
     * Only the phpMyAdmin container may redeem an SSO token (PmaSso), so a
     * tool call always got 404 -- and a success would hand the agent MySQL
     * credentials and burn the token the browser URL needs.
     */
    public function test_the_phpmyadmin_token_exchange_is_hidden_from_mcp(): void
    {
        $attribute = (new ReflectionMethod(MysqlController::class, 'usePhpmyadminSsoToken'))
            ->getAttributes(OA\Put::class)[0]->newInstance();

        $this->assertSame(['mcp-hide' => true], $attribute->x);
    }

    /**
     * The app SSO redeem is a browser's: as a tool it burned the single-use
     * token the link needs and put the session cookie in a tool result.
     */
    public function test_the_app_sso_token_redeem_is_hidden_from_mcp(): void
    {
        $attribute = (new ReflectionMethod(AppUserController::class, 'useAppSsoToken'))
            ->getAttributes(OA\Get::class)[0]->newInstance();

        $this->assertSame(['mcp-hide' => true], $attribute->x);
    }
}
