<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Inspecting a project's own checkout, through /projects/{project}/inspect and through /source/inspect with a
 * project name: the report reads the files in the home, and a source the engine cannot read is refused by field.
 */
class ProjectSourceInspectionHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->home = sys_get_temp_dir() . '/pa-inspect-home-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/project/public', 0777, true);
        file_put_contents($this->home . '/project/composer.json', json_encode(['require' => ['php' => '^8.2']]));
        file_put_contents($this->home . '/project/public/index.php', "<?php echo 'hi';\n");
        $this->makeUser('shop', ['template' => 'dind', 'home_dir' => $this->home, 'git_repo' => 'https://github.com/acme/shop']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->home));
        parent::tearDown();
    }

    public function test_a_project_is_inspected_from_its_checkout(): void
    {
        $response = $this->getJson('/api/projects/shop/inspect');

        $response->assertOk()
            ->assertJsonPath('data.source.type', 'project')
            ->assertJsonPath('data.source.reference', 'shop')
            ->assertJsonPath('data.source.path', realpath($this->home . '/project'))
            ->assertJsonPath('data.source.subdirectory', 'project')
            ->assertJsonStructure(['data' => ['deployment', 'drift', 'ports', 'application']]);
    }

    public function test_a_project_named_as_a_source_is_inspected_the_same_way(): void
    {
        $this->postJson('/api/source/inspect', ['source' => 'shop'])
            ->assertOk()
            ->assertJsonPath('data.source.type', 'project')
            ->assertJsonPath('data.source.path', realpath($this->home . '/project'));
    }

    public function test_a_subdirectory_of_the_project_is_inspected_on_its_own(): void
    {
        $this->getJson('/api/projects/shop/inspect?subdirectory=project/public')
            ->assertOk()
            ->assertJsonPath('data.source.path', realpath($this->home . '/project/public'))
            ->assertJsonPath('data.source.subdirectory', 'project/public');
    }

    public function test_a_subdirectory_that_is_not_there_is_refused(): void
    {
        $this->getJson('/api/projects/shop/inspect?subdirectory=missing')
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.field', 'project');
    }

    public function test_a_server_directory_is_not_inspected(): void
    {
        $this->postJson('/api/source/inspect', ['source' => $this->home])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'source_type_unsupported');
    }

    public function test_a_source_that_names_nothing_is_refused(): void
    {
        $this->postJson('/api/source/inspect', ['source' => 'Not A Source!'])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'source_unrecognised');
    }

    public function test_an_unknown_project_is_not_found(): void
    {
        $this->getJson('/api/projects/nobody/inspect')->assertNotFound();
    }
}
