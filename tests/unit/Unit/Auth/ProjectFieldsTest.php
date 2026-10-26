<?php

namespace Tests\Unit\Auth;

use App\Auth\ProjectFields;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The projects a request names outside its path. An inspected source is the
 * one field whose meaning depends on the request: a username is a project, a
 * repository URL is not.
 */
class ProjectFieldsTest extends TestCase
{
    public function test_a_declared_project_source_names_that_project(): void
    {
        $this->assertSame(['bob'], $this->inspecting(['source' => 'bob', 'type' => 'project']));
    }

    public function test_a_bare_username_is_read_as_a_project_without_a_type(): void
    {
        $this->assertSame(['bob'], $this->inspecting(['source' => 'bob']));
    }

    public function test_a_repository_names_no_project(): void
    {
        $this->assertSame([], $this->inspecting(['source' => 'https://github.com/bob/app']));
        $this->assertSame([], $this->inspecting(['source' => 'github.com/bob/app']));
        $this->assertSame([], $this->inspecting(['source' => 'bob', 'type' => 'git']));
    }

    public function test_the_push_diff_target_is_a_named_project(): void
    {
        $request = Request::create('/api/projects/alice/push/diff', 'GET', ['target' => 'bob']);

        $this->assertSame(['bob'], ProjectFields::namedIn($request, 'GET /projects/{username}/push/diff'));
    }

    public function test_the_name_a_clone_or_staging_copy_is_created_under_is_a_named_project(): void
    {
        foreach (['clone', 'staging'] as $action) {
            $request = Request::create('/api/projects/alice/' . $action, 'POST', ['new_username' => 'bob']);

            $this->assertSame(['bob'], ProjectFields::namedIn($request, 'POST /projects/{username}/' . $action));
        }
    }

    public function test_a_creation_that_gives_no_name_reports_the_field_it_left_out(): void
    {
        $clone = 'POST /projects/{username}/clone';

        $this->assertSame('new_username', ProjectFields::missingCreationName(Request::create('/api/projects/alice/clone', 'POST'), $clone));
        $this->assertSame('new_username', ProjectFields::missingCreationName(Request::create('/api/projects/alice/clone', 'POST', ['new_username' => '']), $clone));
        $this->assertNull(ProjectFields::missingCreationName(Request::create('/api/projects/alice/clone', 'POST', ['new_username' => 'bob']), $clone));
        $this->assertNull(ProjectFields::missingCreationName(Request::create('/api/projects/alice/push', 'POST'), 'POST /projects/{username}/push'));
    }

    public function test_an_operation_with_no_such_field_names_nothing(): void
    {
        $request = Request::create('/api/projects/alice', 'PUT', ['target' => 'bob']);

        $this->assertSame([], ProjectFields::namedIn($request, 'PUT /projects/{username}'));
        $this->assertSame([], ProjectFields::namedIn($request, null));
    }

    /**
     * @param array<string, string> $body
     * @return list<string>
     */
    private function inspecting(array $body): array
    {
        return ProjectFields::namedIn(Request::create('/api/source/inspect', 'POST', $body), 'POST /source/inspect');
    }
}
