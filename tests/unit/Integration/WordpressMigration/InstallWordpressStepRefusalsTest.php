<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallWordpressStep;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;

/**
 * The WordPress install refuses to start without a target it can find and an account it can write as; nothing is
 * installed before every check has passed.
 */
class InstallWordpressStepRefusalsTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    private const LABEL = 'dest.example.test';

    private RecordingInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();
        $this->installer = new RecordingInstaller;
    }

    protected function tearDown(): void
    {
        $this->dropTargetTables();
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_migration_without_a_target_is_refused(): void
    {
        $this->assertRefused($this->migration(null), 'Cannot install WordPress: target_label is missing (create_project must complete first).');
        $this->assertRefused($this->migration(''), 'Cannot install WordPress: target_label is missing (create_project must complete first).');
    }

    public function test_an_unknown_target_domain_is_refused(): void
    {
        $this->assertRefused($this->migration('ghost.example.test'), "Domain 'ghost.example.test' not found for WordPress install.");
    }

    public function test_a_domain_whose_account_is_gone_is_refused(): void
    {
        $this->domainRow();

        $this->assertRefused($this->migration(self::LABEL), "No user for domain 'dest.example.test'.");
    }

    public function test_a_subdirectory_install_needs_the_accounts_uid_and_gid(): void
    {
        $this->domainRow();
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails(['template' => 'default']);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $user->shouldReceive('project')->andReturn($project);
        Domain::retrieved(static function (Domain $domain) use ($user): void {
            $domain->setRelation('user', $user);
        });

        $this->assertRefused($this->migration(self::LABEL, 'https://wp.com/blog'), 'Cannot install WordPress: account uid:gid is missing.');
    }

    private function assertRefused(WordpressMigration $migration, string $message): void
    {
        try {
            (new InstallWordpressStep($this->installer))->run($migration);
            $this->fail('the install was not refused');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame([], $this->installer->calls);
    }

    private function migration(?string $label, string $sourceUrl = 'https://wp.com'): WordpressMigration
    {
        $migration = new WordpressMigration;
        $migration->forceFill(['id' => 7, 'target_label' => $label, 'source_url' => $sourceUrl]);

        return $migration;
    }

    private function domainRow(): void
    {
        DB::table('domains')->insert([
            'user_id' => 999,
            'domain' => self::LABEL,
            'type' => 'main',
            'details' => json_encode(['document_root' => '/dest.example.test/public_html']),
        ]);
    }
}
