<?php

namespace Tests\Integration\Project\Create;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\Create\CreateFollowUp;
use App\Lib\Project\Create\FreshWordpress;
use App\Lib\Project\Create\SourceSpec;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Upload\ArchiveSource;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What a typed create does after the account's own deploy, as the queued
 * job runs it: from the array the job carries, back to the work.
 */
class CreateFollowUpTest extends TestCase
{
    use InMemoryDatabase;

    private \ArrayObject $calls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->calls = new \ArrayObject;
        $calls = $this->calls;
        $this->app->instance(ProjectRebuild::class, new class($calls) extends ProjectRebuild
        {
            public function __construct(private readonly \ArrayObject $calls) {}

            public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath, array $git = [], ?ArchiveSource $archive = null): array
            {
                $this->calls[] = ['run', $action, $zipPath, $archive?->toArray()];
                if ($logger !== null) {
                    $this->calls['status'] = $logger->readLatest()['status'] ?? null;
                }

                return [];
            }
        });
    }

    public function test_an_upload_is_deployed_by_the_archive_deploy(): void
    {
        $user = $this->makeUser('fromzip', ['template' => 'dind']);
        $followUp = CreateFollowUp::fromArray(CreateFollowUp::for(SourceSpec::of(['type' => 'archive', 'upload_id' => 'upl_x']))->toArray());

        $followUp?->run($user, null, static fn (string $line) => null);

        $this->assertSame([['run', 'deploy_archive', null, ['kind' => 'upload', 'value' => 'upl_x']]], $this->calls->getArrayCopy());
    }

    /** The account's own deploy waited for these files: it runs again while the archive deploys under its log. */
    public function test_the_archive_deploys_under_a_log_running_again(): void
    {
        $user = $this->makeUser('fromwait', ['template' => 'dind']);
        $logger = DeployLogger::start('fromwait');
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $logger->waitForFiles();

        try {
            CreateFollowUp::for(SourceSpec::of(['type' => 'archive', 'upload_id' => 'upl_w']))->run($user, $logger, static fn (string $line) => null);

            $this->assertSame(DeployLogger::STATUS_RUNNING, $this->calls['status']);
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
            unset($logger);
            DeployLogger::deleteUserLogs('fromwait');
        }
    }

    public function test_a_url_is_deployed_by_the_archive_deploy(): void
    {
        $user = $this->makeUser('fromurl', ['template' => 'dind']);

        CreateFollowUp::for(SourceSpec::of(['type' => 'archive', 'url' => 'https://example.com/app.tar.gz']))->run($user, null, static fn (string $line) => null);

        $this->assertSame([['run', 'deploy_archive', null, ['kind' => 'url', 'value' => 'https://example.com/app.tar.gz']]], $this->calls->getArrayCopy());
    }

    public function test_wordpress_is_installed_with_the_password_it_was_sealed_with(): void
    {
        $user = $this->makeUser('wpone');
        $installed = new \ArrayObject;
        $this->app->instance(FreshWordpress::class, new class($installed) extends FreshWordpress
        {
            public function __construct(private readonly \ArrayObject $installed) {}

            public function install(User $user, array $site, callable $log): void
            {
                $this->installed[] = $site;
            }
        });
        $source = SourceSpec::of(['type' => 'wordpress', 'title' => 'Shop', 'locale' => 'de_DE', 'admin' => ['user' => 'boss', 'email' => 'b@example.com', 'password' => 'long-enough']]);
        $carried = CreateFollowUp::for($source)->toArray();

        CreateFollowUp::fromArray(json_decode((string) json_encode($carried), true))?->run($user, null, static fn (string $line) => null);

        $this->assertNotSame('long-enough', $carried['admin_password']);
        $this->assertSame([[
            'title' => 'Shop', 'admin_user' => 'boss', 'admin_email' => 'b@example.com', 'admin_password' => 'long-enough', 'locale' => 'de_DE', 'version' => null,
        ]], $installed->getArrayCopy());
    }

    public function test_git_and_empty_sources_do_nothing_more(): void
    {
        $user = $this->makeUser('plain', ['template' => 'dind']);

        CreateFollowUp::for(SourceSpec::of(['type' => 'git', 'url' => 'https://github.com/a/b']))->run($user, null, static fn (string $line) => null);
        CreateFollowUp::for(SourceSpec::none())->run($user, null, static fn (string $line) => null);

        $this->assertSame([], $this->calls->getArrayCopy());
        $this->assertSame(['source_type' => 'git'], CreateFollowUp::for(SourceSpec::of(['type' => 'git', 'url' => 'https://github.com/a/b']))->toArray());
    }
}
