<?php

namespace Tests\Integration\Auth;

use App\Auth\ApiSurface;
use Tests\TestCase;

class ApiSurfaceListingTest extends TestCase
{
    public function test_every_operation_is_listed_once_across_its_groups(): void
    {
        $all = ApiSurface::all();

        $this->assertNotEmpty($all);
        $this->assertSame(array_merge(...array_values(ApiSurface::byGroup())), $all);
        $this->assertSame($all, array_values(array_unique($all)));
        $this->assertContains('GET /projects', $all);
    }
}
