<?php

namespace Tests\Unit\Copy;

use App\Models\User;
use App\System\Projects;
use Tests\TestCase;

class GuardsTest extends TestCase
{
    private function user(string $username, ?string $template, ?int $id = 1, ?int $stagingOf = null, string $status = 'active'): User
    {
        $u = new User();
        $u->id = $id;
        $u->username = $username;
        $u->status = $status;
        $u->staging = $stagingOf;
        $u->details = $template === null ? [] : ['template' => $template];
        return $u;
    }

    public function test_same_template_accepts_two_dind(): void
    {
        Projects::assertSameTemplate($this->user('a', 'dind', 1), $this->user('b', 'dind', 2));
        $this->assertTrue(true);
    }

    public function test_same_template_accepts_two_null(): void
    {
        Projects::assertSameTemplate($this->user('a', null, 1), $this->user('b', null, 2));
        $this->assertTrue(true);
    }

    public function test_same_template_rejects_mixed_and_names_both(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            Projects::assertSameTemplate($this->user('live', 'dind', 1), $this->user('stg', 'default', 2));
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('live', $e->getMessage());
            $this->assertStringContainsString('stg', $e->getMessage());
            $this->assertStringContainsString('dind', $e->getMessage());
            $this->assertStringContainsString('default', $e->getMessage());
            throw $e;
        }
    }

    public function test_assert_can_create_allows_non_dind_live(): void
    {
        Projects::assertCanCreate($this->user('wp', 'default', 1));
        $this->assertTrue(true);
    }

    public function test_assert_can_create_rejects_staging_source(): void
    {
        $this->expectException(\RuntimeException::class);
        Projects::assertCanCreate($this->user('stg', 'dind', 2, 1));
    }

    public function test_create_problems_empty_when_source_not_persisted(): void
    {
        $live = $this->user('live', 'dind', 1);
        $this->assertFalse($live->exists);
        $this->assertSame([], Projects::createProblems($live));
    }

    public function test_assert_can_copy_into_staging_allows_pending_dest(): void
    {
        $live = $this->user('live', 'dind', 1);
        $dest = $this->user('stg', 'dind', 2, 1, 'pending');
        Projects::assertCanCopyIntoStaging($live, $dest);
        $this->assertTrue(true);
    }

    public function test_assert_can_copy_into_staging_rejects_wrong_pair(): void
    {
        $live = $this->user('live', 'dind', 1);
        $dest = $this->user('stg', 'dind', 2, 99, 'pending');
        $this->expectException(\RuntimeException::class);
        Projects::assertCanCopyIntoStaging($live, $dest);
    }

    public function test_assert_can_copy_into_staging_rejects_non_pending_dest(): void
    {
        $live = $this->user('live', 'dind', 1);
        $dest = $this->user('stg', 'dind', 2, 1, 'active');
        $this->expectException(\RuntimeException::class);
        Projects::assertCanCopyIntoStaging($live, $dest);
    }

    public function test_assert_can_push_accepts_fpm_pair(): void
    {
        $live = $this->user('live', 'default', 1);
        $stg = $this->user('stg', 'default', 2, 1);
        Projects::assertCanPush($stg, $live);
        Projects::assertCanPush($live, $stg);
        $this->assertTrue(true);
    }

    public function test_assert_can_push_rejects_unrelated_pair(): void
    {
        $this->expectException(\RuntimeException::class);
        Projects::assertCanPush($this->user('a', 'dind', 1), $this->user('b', 'dind', 2));
    }

    public function test_assert_can_push_rejects_mixed_template_pair(): void
    {
        $live = $this->user('live', 'dind', 1);
        $stg = $this->user('stg', 'default', 2, 1);
        $this->expectException(\RuntimeException::class);
        Projects::assertCanPush($stg, $live);
    }

    public function test_assert_not_pending(): void
    {
        $this->expectException(\RuntimeException::class);
        Projects::assertNotPending($this->user('x', 'dind', 1, null, 'pending'));
    }

    public function test_assert_idle_rejects_running_push(): void
    {
        $u = $this->user('x', 'dind');
        $u->details = [
            'template' => 'dind',
            'async_status' => ['push' => 'running'],
        ];
        $this->expectException(\RuntimeException::class);
        try {
            Projects::assertIdle($u);
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            throw $e;
        }
    }
}
