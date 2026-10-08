<?php

namespace Tests\Unit\Routing;

use App\Http\Controllers\SourceInspectionController;
use App\Http\Requests\ProjectSourceInspectRequest;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * One question, one word.
 *
 * "What would this deploy as" is asked two ways — of a repository or a path,
 * which no account owns yet, and of a project that already has files. They ran
 * through the same detection and returned the same fields under two different
 * names: `/source/inspect` against `/source-inspection`, `subdirectory`
 * against `path`. These pin the rename, and pin the older spellings still
 * answering, because a deprecation that quietly stops working is just a break
 * with a nicer name.
 */
class InspectRoutesTest extends TestCase
{
    /** @return list<string> */
    private function uris(): array
    {
        $uris = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if ($route->getActionName() === SourceInspectionController::class . '@project') {
                $uris[] = $route->uri();
            }
        }

        sort($uris);

        return $uris;
    }

    public function test_the_project_report_is_served_under_both_prefixes_and_both_spellings(): void
    {
        $this->assertSame(
            [
                'api/projects/{username}/inspect',
                'api/projects/{username}/source-inspection',
                'api/users/{username}/inspect',
                'api/users/{username}/source-inspection',
            ],
            $this->uris()
        );
    }

    /**
     * The canonical route is the one the OpenAPI document describes, and so
     * the one the MCP tool calls. The alias is deliberately undocumented: two
     * documented paths for one operation would generate two tools.
     */
    public function test_only_the_canonical_path_is_documented(): void
    {
        $spec = base_path('storage/api-docs/api-docs.json');

        if (!is_file($spec)) {
            $this->markTestSkipped('No OpenAPI document. Run: php artisan l5-swagger:generate');
        }

        $paths = json_decode((string) file_get_contents($spec), true)['paths'] ?? [];

        $this->assertArrayHasKey('/projects/{username}/inspect', $paths);
        $this->assertArrayNotHasKey('/projects/{username}/source-inspection', $paths);
    }

    public function test_the_directory_parameter_is_named_subdirectory(): void
    {
        $this->assertSame('project/apps/api', $this->resolveDirectory('subdirectory=project/apps/api'));
    }

    /**
     * `path` was this endpoint's own word for it. Folded onto the canonical
     * name before validation, so nothing downstream has to know both.
     */
    public function test_the_deprecated_path_parameter_still_selects_a_directory(): void
    {
        $this->assertSame('public_html', $this->resolveDirectory('path=public_html'));
    }

    public function test_subdirectory_wins_when_a_caller_sends_both(): void
    {
        $this->assertSame(
            'project',
            $this->resolveDirectory('subdirectory=project&path=public_html')
        );
    }

    public function test_neither_is_required(): void
    {
        $this->assertNull($this->resolveDirectory(''));
    }

    /**
     * The directory the controller would read, for a given query string.
     */
    private function resolveDirectory(string $query): ?string
    {
        $request = ProjectSourceInspectRequest::create(
            '/api/projects/acme/inspect' . ($query === '' ? '' : '?' . $query),
            'GET'
        );
        $request->setContainer($this->app);
        $request->validateResolved();

        return $request->validated()['subdirectory'] ?? null;
    }
}
