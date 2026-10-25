<?php

namespace Tests\Integration\Backup;

use App\Lib\Project\ProjectCreator;
use App\Lib\Project\ProjectRestore;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Backup;
use App\System\Project\Domain as DomainProject;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The run that turns a provisioned account into a working copy of a backup:
 * the restore protocol records its own failure instead of throwing it, so the
 * copy must stop there rather than start and publish a half-restored project.
 */
class ProjectRestoreTest extends TestCase
{
    use InMemoryDatabase;

    public const string RESTORE_FAILURE = 'Restore failed: disk full';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    /** The restored files carry the source account's owner, so they are handed over before anything starts. */
    public function test_a_restored_copy_is_handed_to_its_account_then_started_published_and_completed(): void
    {
        [$source, $target, $record] = $this->copyOfAlice();
        $system = new RestoreRecordingSystem(restoreFails: false);

        (new ProjectRestore($system, app(ProjectCreator::class)))->restore($source, $record, $target);

        $this->assertSame([
            'provision:bob',
            'restoreInto:bob',
            'fixPermissions:bob',
            'repointCopiedRunFile:bob:alice',
            'syncGeneratedProxyRules:bob',
            'createDomain:bob.example.test',
        ], $system->steps);
        $target->refresh();
        $this->assertSame('completed', $target->asyncStatus()['restore']);
        $this->assertSame('active', $target->status);
    }

    public function test_a_backup_restore_that_failed_fails_the_copy_and_publishes_nothing(): void
    {
        [$source, $target, $record] = $this->copyOfAlice();
        $system = new RestoreRecordingSystem(restoreFails: true);

        try {
            (new ProjectRestore($system, app(ProjectCreator::class)))->restore($source, $record, $target);
            $this->fail('A failed backup restore must fail the copy.');
        } catch (RuntimeException $e) {
            $this->assertSame(self::RESTORE_FAILURE, $e->getMessage());
        }

        $this->assertSame(['provision:bob', 'restoreInto:bob'], $system->steps);
        $target->refresh();
        $this->assertSame('failed', $target->asyncStatus()['restore']);
        $this->assertSame(self::RESTORE_FAILURE, $target->getDetails()['error']);
        $this->assertSame('pending', $target->status);
    }

    /**
     * @return array{0: User, 1: User, 2: BackupRecord}
     */
    private function copyOfAlice(): array
    {
        $source = $this->makeUser('alice', ['template' => 'dind', 'mysql_prefix' => 'alice_']);
        $target = $this->makeUser('bob', ['template' => 'dind', 'mysql_prefix' => 'bob_']);
        $target->status = 'pending';
        $target->mergeAsyncStatus(['restore' => 'pending']);
        $target->save();
        $this->makeMainDomain($target, 'bob.example.test');

        $container = new BackupContainer();
        $container->name = 'store-'.bin2hex(random_bytes(4));
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = null;
        $container->save();

        $record = BackupRecord::prepare($source, $container, 'api');
        $record->setBackupStatus('completed');
        $record->save();

        return [$source, $target, $record];
    }
}

/** Hands out projects that record each step of the copy instead of touching a host. */
class RestoreRecordingSystem extends System
{
    /** @var list<string> */
    public array $steps = [];

    public function __construct(public readonly bool $restoreFails) {}

    public function project(User $model): Project
    {
        return new RestoreRecordingProject($this, $model);
    }
}

class RestoreRecordingProject extends Project
{
    public function __construct(private readonly RestoreRecordingSystem $recorder, User $model)
    {
        parent::__construct($recorder, $model);
    }

    public function provision(): void
    {
        $this->recorder->steps[] = 'provision:'.$this->username();
    }

    public function backup(BackupRecord $record): Backup
    {
        return new class ($this, $record, $this->recorder) extends Backup
        {
            public function __construct(Project $project, BackupRecord $record, private readonly RestoreRecordingSystem $recorder)
            {
                parent::__construct($project, $record);
            }

            public function restoreInto(Project $target): void
            {
                $this->recorder->steps[] = 'restoreInto:'.$target->username();
                if ($this->recorder->restoreFails) {
                    $target->model()->markRestoreFailed(ProjectRestoreTest::RESTORE_FAILURE);
                }
            }
        };
    }

    public function fixPermissions(): void
    {
        $this->recorder->steps[] = 'fixPermissions:'.$this->username();
    }

    public function repointCopiedRunFile(User $source, ?string $path = null): void
    {
        $this->recorder->steps[] = 'repointCopiedRunFile:'.$this->username().':'.$source->username;
    }

    public function hasUserApp(): bool
    {
        return false;
    }

    public function syncGeneratedProxyRules(): void
    {
        $this->recorder->steps[] = 'syncGeneratedProxyRules:'.$this->username();
    }

    public function domain(DomainModel $domainModel): DomainProject
    {
        $recorder = $this->recorder;

        return new class ($this, $domainModel, $recorder) extends DomainProject
        {
            public function __construct(Project $project, private readonly DomainModel $domain, private readonly RestoreRecordingSystem $recorder)
            {
                parent::__construct($project, $domain);
            }

            public function create(): void
            {
                $this->recorder->steps[] = 'createDomain:'.$this->domain->domain;
            }
        };
    }
}
