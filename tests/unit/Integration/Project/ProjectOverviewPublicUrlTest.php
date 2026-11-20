<?php

namespace Tests\Integration\Project;

use App\Lib\Project\ProjectOverview;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The public URL a project is listed with: none for a project not saved yet or without a name of its own.
 */
class ProjectOverviewPublicUrlTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_project_without_a_domain_or_tunnel_has_no_public_url(): void
    {
        $this->assertNull(ProjectOverview::publicUrl($this->makeUser('shop', [], '')));
        $this->assertNull(ProjectOverview::publicUrl(new User(['username' => 'draft', 'domain' => 'draft.example.test'])));
    }

    public function test_a_project_without_its_domain_row_is_on_https(): void
    {
        $this->assertSame('https://shop.example.test', ProjectOverview::publicUrl($this->makeUser('shop', [], 'shop.example.test')));
    }
}
