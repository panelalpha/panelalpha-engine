<?php

namespace Tests\Integration\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * project:backup:list and project:backup:show read the backup rows of one project
 * and print them; neither reaches storage.
 */
class ProjectBackupListShowCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_list_of_an_unknown_project_fails(): void
    {
        $this->artisan('project:backup:list', ['project' => 'ghost'])
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);
    }

    public function test_list_of_a_project_without_backups_says_so(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:backup:list', ['project' => 'alice'])
            ->expectsOutputToContain("No backups for project 'alice'.")
            ->assertExitCode(0);
    }

    public function test_list_shows_each_backup_with_its_store_status_size_and_error(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $box = $this->container('box');
        $small = $this->backup($alice, $box, 'completed', [512], ageMinutes: 30);
        $medium = $this->backup($alice, $box, 'failed', [1024, 1024], 'disk full', ageMinutes: 20);
        $large = $this->backup($alice, $box, 'completed', [3 * 1024 * 1024], ageMinutes: 10);
        $this->backup($bob, $box, 'completed', [7]);

        $this->artisan('project:backup:list', ['project' => 'alice'])
            ->expectsTable(['ID', 'Container', 'Status', 'Created', 'Size', 'Error'], [
                [$large->id, "box (#{$box->id})", 'completed', $large->created_at->format('Y-m-d H:i:s'), '3 MB', '-'],
                [$medium->id, "box (#{$box->id})", 'failed', $medium->created_at->format('Y-m-d H:i:s'), '2 KB', 'disk full'],
                [$small->id, "box (#{$box->id})", 'completed', $small->created_at->format('Y-m-d H:i:s'), '512 B', '-'],
            ])
            ->assertExitCode(0);
    }

    public function test_list_marks_a_backup_without_a_status(): void
    {
        $alice = $this->makeUser('alice');
        $box = $this->container('box');
        $record = BackupRecord::create([
            'user_id' => $alice->id,
            'username' => 'alice',
            'container_id' => $box->id,
            'async_status' => [],
        ]);

        $this->artisan('project:backup:list', ['project' => 'alice'])
            ->expectsTable(['ID', 'Container', 'Status', 'Created', 'Size', 'Error'], [
                [$record->id, "box (#{$box->id})", '-', $record->created_at->format('Y-m-d H:i:s'), '0 B', '-'],
            ])
            ->assertExitCode(0);
    }

    public function test_show_refuses_a_backup_id_that_is_not_a_number(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:backup:show', ['project' => 'alice', 'backup' => '1x'])
            ->expectsOutputToContain('Backup id must be numeric.')
            ->assertExitCode(1);
    }

    public function test_show_of_an_unknown_project_fails(): void
    {
        $this->artisan('project:backup:show', ['project' => 'ghost', 'backup' => '1'])
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);
    }

    public function test_show_refuses_a_missing_backup_and_one_of_another_project(): void
    {
        $this->makeUser('alice');
        $bobs = $this->backup($this->makeUser('bob'), $this->container('box'), 'completed', [1]);

        $this->artisan('project:backup:show', ['project' => 'alice', 'backup' => '999'])
            ->expectsOutputToContain("Backup '999' not found for project 'alice'.")
            ->assertExitCode(1);
        $this->artisan('project:backup:show', ['project' => 'alice', 'backup' => (string) $bobs->id])
            ->expectsOutputToContain("Backup '{$bobs->id}' not found for project 'alice'.")
            ->assertExitCode(1);
    }

    public function test_show_prints_the_backup_and_its_items(): void
    {
        $alice = $this->makeUser('alice');
        $box = $this->container('box');
        $record = $this->backup($alice, $box, 'failed', [2048, 5 * 1024 * 1024], 'upload stopped');
        $items = $record->items()->orderBy('id')->get();

        $this->artisan('project:backup:show', ['project' => 'alice', 'backup' => (string) $record->id])
            ->expectsOutputToContain('Backup ID:   ' . $record->id)
            ->expectsOutputToContain('Project:     alice')
            ->expectsOutputToContain("Container:   box (#{$box->id})")
            ->expectsOutputToContain('Status:      failed')
            ->expectsOutputToContain('Source:      artisan')
            ->expectsOutputToContain('Created:     ' . $record->created_at->format('Y-m-d H:i:s'))
            ->expectsOutputToContain('Size:        5 MB')
            ->expectsOutputToContain('Error:       upload stopped')
            ->expectsTable(['ID', 'Remote path', 'Size', 'Type', 'Name'], [
                [$items[0]->id, $items[0]->remote_path, '2 KB', 'files', 'item-0'],
                [$items[1]->id, $items[1]->remote_path, '5 MB', 'files', 'item-1'],
            ])
            ->assertExitCode(0);
    }

    public function test_show_of_a_backup_without_items_says_so(): void
    {
        $alice = $this->makeUser('alice');
        $record = BackupRecord::create([
            'user_id' => $alice->id,
            'username' => 'alice',
            'container_id' => $this->container('box')->id,
            'async_status' => [],
        ]);

        $this->artisan('project:backup:show', ['project' => 'alice', 'backup' => (string) $record->id])
            ->expectsOutputToContain('Status:      -')
            ->expectsOutputToContain('Source:      -')
            ->expectsOutputToContain('Size:        0 B')
            ->doesntExpectOutputToContain('Error:')
            ->expectsOutputToContain('No backup items.')
            ->assertExitCode(0);
    }

    private function container(string $name): BackupContainer
    {
        return BackupContainer::create([
            'name' => $name,
            'driver' => 'local',
            'location' => "/opt/panelalpha/backups/{$name}",
            'credentials' => null,
        ]);
    }

    /** @param list<int> $sizes */
    private function backup(User $user, BackupContainer $container, string $status, array $sizes, ?string $error = null, int $ageMinutes = 0): BackupRecord
    {
        $record = BackupRecord::prepare($user, $container, 'artisan');
        $record->setBackupStatus($status);
        $record->error = $error;
        $record->created_at = now()->subMinutes($ageMinutes);
        $record->save();

        foreach ($sizes as $i => $size) {
            $item = new BackupItem();
            $item->backup_id = $record->id;
            $item->remote_path = "{$user->username}/{$record->id}/item-{$i}.tar.gz";
            $item->size_bytes = $size;
            $item->details = ['type' => 'files', 'name' => "item-{$i}"];
            $item->save();
        }

        return $record->fresh();
    }
}
