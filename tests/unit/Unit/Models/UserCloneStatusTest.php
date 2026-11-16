<?php

namespace Tests\Unit\Models;

use App\Lib\Domains\CopyDomain;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A clone is a new active project from the moment its rows are saved, like a create. */
class UserCloneStatusTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_clone_is_active_from_the_start(): void
    {
        $live = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.com');

        $clone = $live->makeClone('shopcopy', CopyDomain::forClone($live->domain, null));

        $this->assertSame('active', $clone->status);
        $this->assertSame('active', $clone->refresh()->status);
    }
}
