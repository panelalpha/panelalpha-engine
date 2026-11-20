<?php

namespace Tests\Integration\Project\Create;

use App\Lib\Project\Create\ProjectSourceView;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * GET /projects/{name} `source`: what a rebuild deploys, never a secret.
 */
class ProjectSourceViewTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_a_git_project_with_a_token(): void
    {
        $user = $this->makeUser('gitone', ['template' => 'dind', 'git_repo' => 'https://github.com/acme/shop', 'git_branch' => 'main', 'git_token' => 'secret-token']);

        $this->assertSame(
            ['type' => 'git', 'url' => 'https://github.com/acme/shop', 'branch' => 'main', 'auth' => 'token', 'rebuild' => 'clone'],
            ProjectSourceView::of($user),
        );
    }

    public function test_an_ssh_project_with_a_deploy_key(): void
    {
        $user = $this->makeUser('sshone', ['template' => 'dind', 'git_repo' => 'git@github.com:acme/shop.git', 'git_deploy_key' => [
            'private_key' => 'k', 'public_key' => 'ssh-ed25519 AAAA x', 'fingerprint' => 'f', 'known_hosts' => [],
        ]]);

        $this->assertSame('deploy_key', ProjectSourceView::of($user)['auth']);
    }

    public function test_a_connected_checkout_rebuilds_as_it_is(): void
    {
        $user = $this->makeUser('connected', ['template' => 'dind']);
        $user->putSiteGit('project', ['repo_url' => 'https://github.com/acme/shop', 'branch' => 'main', 'token' => null]);
        $user->save();

        $this->assertSame(
            ['type' => 'git', 'url' => 'https://github.com/acme/shop', 'branch' => 'main', 'auth' => 'none', 'rebuild' => 'checkout'],
            ProjectSourceView::of($user->refresh()),
        );
    }

    public function test_what_a_create_recorded(): void
    {
        $archive = $this->makeUser('fromzip', ['template' => 'dind', 'source' => ['type' => 'archive', 'upload_id' => 'upl_x']]);
        $copy = $this->makeUser('thecopy', ['template' => 'dind', 'source' => ['type' => 'project', 'from' => 'shop']]);
        $empty = $this->makeUser('empty', ['template' => 'dind']);

        $this->assertSame(['type' => 'archive', 'upload_id' => 'upl_x'], ProjectSourceView::of($archive));
        $this->assertSame(['type' => 'project', 'from' => 'shop'], ProjectSourceView::of($copy));
        $this->assertSame(['type' => 'none'], ProjectSourceView::of($empty));
    }

    public function test_an_archive_url_recorded_with_its_query_is_shown_without_it(): void
    {
        $user = $this->makeUser('presigned', ['template' => 'dind', 'source' => [
            'type' => 'archive',
            'url' => 'https://bucket.s3.amazonaws.com/site.tar.gz?X-Amz-Credential=AKIA&X-Amz-Signature=abc',
        ]]);

        $this->assertSame(['type' => 'archive', 'url' => 'https://bucket.s3.amazonaws.com/site.tar.gz'], ProjectSourceView::of($user));
    }
}
