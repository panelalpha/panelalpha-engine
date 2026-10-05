<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use App\Mcp\Tools\Api\Firewall\FirewallRuleUpdateTool;
use App\Mcp\Tools\Api\Projects\ProjectDeployArchiveTool;
use App\Mcp\Tools\Api\Projects\ProjectRebuildTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

/**
 * An explicit null reaches the API only where the tool's schema admits null
 * (`x-mcp-nullable`), as `env_vars: null` clearing a project's variables does
 * over REST. Everywhere else a null is dropped like an omitted argument.
 */
class ApiToolNullArgumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/test-mcp/null-echo', fn (HttpRequest $r) => response()->json([
            'keys' => array_keys($r->all()),
            'json_keys' => array_keys((array)$r->json()->all()),
        ]));
    }

    public function test_an_explicit_null_is_sent_for_a_nullable_argument(): void
    {
        $data = $this->callTool(['env_vars' => null, 'zip_path' => null]);

        $this->assertSame(['env_vars'], $data['keys']);
        $this->assertSame(['env_vars'], $data['json_keys']);
    }

    public function test_an_omitted_nullable_argument_stays_omitted(): void
    {
        $data = $this->callTool(['zip_path' => '/a.zip']);

        $this->assertSame(['zip_path'], $data['keys']);
    }

    public function test_the_tools_whose_api_gives_null_a_meaning_admit_it(): void
    {
        $this->assertSame(['env_vars'], $this->nullable(new ProjectRebuildTool()));
        $this->assertSame(['env_vars'], $this->nullable(new ProjectDeployArchiveTool()));
        // A null scope puts the rule back on its default (host, or both for an inbound deny), as a null direction does.
        $this->assertSame(
            ['scope', 'direction', 'protocol', 'port', 'source', 'destination', 'comment'],
            $this->nullable(new FirewallRuleUpdateTool())
        );
    }

    public function test_the_generator_marks_only_x_mcp_nullable_properties(): void
    {
        $dir = 'storage/framework/testing/mcp-null-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($dir . '/out'), 0755, true);
        try {
            file_put_contents(base_path($dir . '/spec.json'), json_encode(['paths' => [
                '/things' => ['post' => [
                    'summary' => 'Create a thing',
                    'tags' => ['Things'],
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['properties' => [
                        'cleared' => ['type' => 'object', 'nullable' => true, 'x-mcp-nullable' => true],
                        'plain' => ['type' => 'string', 'nullable' => true],
                    ]]]]],
                ]],
            ]]));
            file_put_contents(base_path($dir . '/names.php'), "<?php return ['POST /things' => 'thing_create'];");

            $this->artisan('mcp:tool:generate', [
                '--spec' => $dir . '/spec.json',
                '--out' => $dir . '/out',
                '--names' => $dir . '/names.php',
            ])->assertExitCode(0);

            $tool = (string)file_get_contents(base_path($dir . '/out/Things/ThingCreateTool.php'));
            $this->assertStringContainsString("'cleared' => \$schema->object()->nullable(),", $tool);
            $this->assertStringContainsString("'plain' => \$schema->string(),", $tool);
        } finally {
            File::deleteDirectory(base_path($dir));
        }
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(array $arguments): array
    {
        $tool = new class extends ApiTool {
            protected function method(): string
            {
                return 'POST';
            }

            protected function path(): string
            {
                return '/test-mcp/null-echo';
            }

            protected function bodyParams(): array
            {
                return ['env_vars', 'zip_path'];
            }

            public function schema(JsonSchema $schema): array
            {
                return [
                    'env_vars' => $schema->object()->nullable(),
                    'zip_path' => $schema->string(),
                ];
            }
        };

        /** @var Response $response */
        $response = $tool->handle(new Request($arguments));

        return json_decode((string)$response->content(), true)['data'];
    }

    /** @return list<string> */
    private function nullable(ApiTool $tool): array
    {
        $properties = JsonSchemaFactory::object($tool->schema(...))->toArray()['properties'] ?? [];

        return array_keys(array_filter(
            (array)$properties,
            fn (array $p): bool => in_array('null', (array)($p['type'] ?? []), true)
        ));
    }
}
