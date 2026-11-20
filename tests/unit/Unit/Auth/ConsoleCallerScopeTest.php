<?php

namespace Tests\Unit\Auth;

use App\Auth\ApiSurface;
use App\Auth\SystemProjects;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

/** What a caller without a token is shown, and which routes have no API operation key. */
class ConsoleCallerScopeTest extends TestCase
{
    public function test_the_console_owns_no_project_and_has_none_hidden_or_unlisted(): void
    {
        $scope = SystemProjects::for(null);

        $this->assertNull($scope->own());
        $this->assertSame([], $scope->hidden());
        $this->assertSame([], $scope->unlisted());
    }

    public function test_no_route_and_a_route_outside_the_api_have_no_operation_key(): void
    {
        $this->assertNull(ApiSurface::keyFor(null, 'GET'));
        $this->assertNull(ApiSurface::keyFor(new Route(['GET'], 'health/live', static fn () => 'ok'), 'GET'));
    }

    public function test_only_a_get_reads(): void
    {
        $this->assertSame('read', ApiSurface::access('GET /projects'));
        $this->assertSame('write', ApiSurface::access('POST /projects'));
    }
}
