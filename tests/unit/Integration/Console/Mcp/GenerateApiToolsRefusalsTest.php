<?php

namespace Tests\Integration\Console\Mcp;

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/** `mcp:tool:generate` refuses a missing or unreadable spec, a missing name map, and a map that does not name every operation. */
class GenerateApiToolsRefusalsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/mcp-generate-'.bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir.'/out'), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $names */
    private function generate(array $names, ?array $paths = null): PendingCommand
    {
        if ($paths !== null) {
            file_put_contents(base_path($this->dir.'/spec.json'), json_encode(['paths' => $paths]));
        }
        file_put_contents(base_path($this->dir.'/names.php'), '<?php return '.var_export($names, true).';');

        return $this->artisan('mcp:tool:generate', [
            '--spec' => $this->dir.'/spec.json',
            '--out' => $this->dir.'/out',
            '--names' => $this->dir.'/names.php',
            '--dry-run' => true,
        ]);
    }

    public function test_a_missing_spec_is_refused(): void
    {
        $this->generate([])
            ->expectsOutputToContain('OpenAPI document not found at ')
            ->assertExitCode(1);
    }

    public function test_an_unparsable_spec_is_refused(): void
    {
        file_put_contents(base_path($this->dir.'/spec.json'), '{"openapi": "3.0.0"}');

        $this->generate([])
            ->expectsOutput('OpenAPI document could not be parsed.')
            ->assertExitCode(1);
    }

    public function test_a_missing_name_map_is_refused(): void
    {
        file_put_contents(base_path($this->dir.'/spec.json'), json_encode(['paths' => []]));

        $this->artisan('mcp:tool:generate', ['--spec' => $this->dir.'/spec.json', '--names' => $this->dir.'/none.php', '--dry-run' => true])
            ->expectsOutputToContain('Tool name map not found at ')
            ->assertExitCode(1);
    }

    public function test_unnamed_operations_get_suggestions_in_the_house_style(): void
    {
        $this->generate([], [
            '/projects/{username}/domains' => ['get' => ['tags' => ['Domains']], 'options' => []],
            '/projects/{username}/domains/{domain}' => ['get' => ['tags' => ['Domains']], 'put' => ['tags' => ['Domains']], 'delete' => ['tags' => ['Domains']]],
            '/projects/{username}/suspend' => ['post' => ['tags' => ['Projects']]],
            '/backups' => ['head' => [], 'patch' => ['tags' => ['Backups']]],
        ])
            ->expectsOutputToContain('6 operation(s) have no name in ')
            ->expectsOutput("    'PATCH /backups' => 'backup_update',")
            ->expectsOutput("    'GET /projects/{username}/domains' => 'domain_list',")
            ->expectsOutput("    'DELETE /projects/{username}/domains/{domain}' => 'domain_delete',")
            ->expectsOutput("    'GET /projects/{username}/domains/{domain}' => 'domain_get',")
            ->expectsOutput("    'PUT /projects/{username}/domains/{domain}' => 'domain_update',")
            ->expectsOutput("    'POST /projects/{username}/suspend' => 'project_suspend',")
            ->expectsOutput('Those are suggestions in the house style, not decisions -- read them before pasting.')
            ->assertExitCode(1);
    }

    public function test_one_name_for_two_operations_is_refused(): void
    {
        $this->generate(
            ['GET /things' => 'thing_read', 'GET /things/{id}' => 'thing_read'],
            ['/things' => ['get' => ['tags' => ['Things']]], '/things/{id}' => ['get' => ['tags' => ['Things']]]],
        )
            ->expectsOutput('Name used by more than one operation: thing_read')
            ->assertExitCode(1);
    }
}
