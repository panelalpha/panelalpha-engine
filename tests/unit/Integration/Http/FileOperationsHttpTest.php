<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Http\OutboundUrl;
use App\Lib\Project\FileThumbnail;
use App\Models\User;
use App\System;
use App\System\Services\Webserver;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The file manager endpoints end to end down to the broker: each one confines the paths it was sent to the
 * account's home, asks the broker as the account, and turns a failed request into a 400 with its output.
 */
class FileOperationsHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const HOME = '/home/alice';

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->answer('compose.serviceContainers', json_encode(['Labels' => 'com.panelalpha.webserver=nginx-proxy']));
        $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_exists_answers_with_the_resolved_path(): void
    {
        $this->getJson('/api/projects/alice/files/exists?path=docs/a.txt')
            ->assertOk()
            ->assertExactJson(['exists' => true, 'path' => self::HOME . '/docs/a.txt']);

        $this->assertSame([['1001', '1001', self::HOME . '/docs/a.txt']], $this->broker->valuesOf('account.exists'));
    }

    public function test_exists_reports_an_entry_the_account_cannot_see_as_missing(): void
    {
        $this->broker->answer('account.exists', '', 1);

        $this->getJson('/api/projects/alice/files/exists?path=a.txt')->assertOk()->assertJsonPath('exists', false);
    }

    public function test_exists_whose_confinement_fails_is_a_400(): void
    {
        $this->broker->answer('account.confine', '', 2, 'broker unavailable');

        $this->getJson('/api/projects/alice/files/exists?path=a.txt')->assertStatus(400);
    }

    public function test_a_file_is_removed_and_a_missing_one_is_not_found(): void
    {
        $this->entry('/a.txt', 'file');
        $this->entry('/gone.txt', 'missing');

        $this->deleteJson('/api/projects/alice/files/remove', ['path' => 'a.txt'])->assertOk()->assertExactJson(['success' => true]);
        $this->deleteJson('/api/projects/alice/files/remove', ['path' => 'gone.txt'])->assertNotFound()->assertExactJson(['message' => 'Invalid path']);

        $this->assertStringContainsString(self::HOME . '/a.txt', implode(' ', array_merge(...$this->broker->requests())));
    }

    public function test_removing_an_htaccess_checks_which_webserver_reads_it(): void
    {
        $this->entry('/.htaccess', 'file');

        $this->deleteJson('/api/projects/alice/files/remove', ['path' => '.htaccess'])->assertOk();

        $this->assertContains('compose.serviceContainers', $this->broker->actions());
    }

    public function test_a_remove_the_type_check_cannot_answer_is_a_400(): void
    {
        $this->entry('/a.txt', 'socket');

        $this->deleteJson('/api/projects/alice/files/remove', ['path' => 'a.txt'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Cannot tell what ' . self::HOME . '/a.txt is: socket');
    }

    public function test_mkdir_asks_for_parents_only_when_told_to(): void
    {
        $this->postJson('/api/projects/alice/files/mkdir', ['path' => 'd'])->assertOk()->assertExactJson(['success' => true]);
        $this->postJson('/api/projects/alice/files/mkdir', ['path' => 'e/f', 'parents' => true])->assertOk();

        $this->assertSame(
            [['1001', '1001', self::HOME . '/d', '0'], ['1001', '1001', self::HOME . '/e/f', '1']],
            $this->broker->valuesOf('account.makeFolder'),
        );
    }

    public function test_a_failed_mkdir_is_a_400_with_its_output(): void
    {
        $this->broker->answer('account.makeFolder', '', 1, 'mkdir: File exists');

        $this->postJson('/api/projects/alice/files/mkdir', ['path' => 'd'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'mkdir: File exists (exit code 1)');
    }

    public function test_zip_packs_the_directory_into_the_archive(): void
    {
        $this->entry(self::HOME, 'dir');
        $this->entry('/d', 'dir');
        $this->entry('/a.zip', 'missing');

        $this->postJson('/api/projects/alice/files/zip', ['zip_path' => 'a.zip', 'path' => 'd', 'compression_level' => 9])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertStringContainsString(self::HOME . '/a.zip', implode(' ', array_merge(...$this->broker->requests())));
    }

    public function test_a_zip_the_broker_cannot_check_is_a_400(): void
    {
        $this->entry(self::HOME, 'fifo');

        $this->postJson('/api/projects/alice/files/zip', ['zip_path' => 'a.zip', 'path' => 'd'])->assertStatus(400);
    }

    public function test_unzip_of_a_missing_archive_is_refused_on_its_field(): void
    {
        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'a.zip', 'path' => 'd'])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.field', 'zip_path');
    }

    public function test_move_contents_moves_into_an_existing_directory(): void
    {
        $this->entry('/d', 'dir');
        $this->entry('/e', 'dir');

        $this->postJson('/api/projects/alice/files/move-contents', ['source_path' => 'd', 'dest_path' => 'e', 'override' => false])
            ->assertOk()
            ->assertExactJson(['success' => true]);
    }

    public function test_move_contents_the_type_check_cannot_answer_is_a_400(): void
    {
        $this->entry('/e', 'door');

        $this->postJson('/api/projects/alice/files/move-contents', ['source_path' => 'd', 'dest_path' => 'e'])->assertStatus(400);
    }

    public function test_chmod_sets_the_mode_and_a_refusal_is_a_400(): void
    {
        $this->putJson('/api/projects/alice/files/chmod', ['path' => 'a.txt', 'mode' => '0640'])->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame([['1001', '1001', self::HOME . '/a.txt', '0640']], $this->broker->valuesOf('account.setMode'));

        $this->broker->answer('account.setMode', '', 1, 'Operation not permitted');
        $this->putJson('/api/projects/alice/files/chmod', ['path' => 'a.txt', 'mode' => '0640'])->assertStatus(400);
    }

    public function test_mv_moves_the_entry_and_a_refusal_is_a_400(): void
    {
        $this->putJson('/api/projects/alice/files/mv', ['source_path' => '.htaccess', 'dest_path' => 'old/.htaccess'])->assertOk();
        $this->assertSame(
            [['1001', '1001', self::HOME . '/.htaccess', self::HOME . '/old/.htaccess']],
            $this->broker->valuesOf('account.moveEntry'),
        );

        $this->broker->answer('account.moveEntry', '', 1, 'No such file');
        $this->putJson('/api/projects/alice/files/mv', ['source_path' => 'a', 'dest_path' => 'b'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'No such file (exit code 1)');
    }

    public function test_cp_copies_the_entry_and_a_refusal_is_a_400(): void
    {
        $this->putJson('/api/projects/alice/files/cp', ['source_path' => 'a', 'dest_path' => '.htaccess'])->assertOk();
        $this->assertSame([['1001', '1001', self::HOME . '/a', self::HOME . '/.htaccess']], $this->broker->valuesOf('account.copyEntry'));

        $this->broker->answer('account.copyEntry', '', 1, 'No space left');
        $this->putJson('/api/projects/alice/files/cp', ['source_path' => 'a', 'dest_path' => 'b'])->assertStatus(400);
    }

    public function test_stat_reads_the_entry_fields(): void
    {
        $this->entry('/a.txt', 'file');
        $this->broker->answer('account.entryFields', implode("\0", ['a.txt', 'f', '5', '1700000000', '644', '1001', '1001', '', '']) . "\0");

        $this->getJson('/api/projects/alice/files/stat?path=a.txt')
            ->assertOk()
            ->assertJsonPath('data.name', 'a.txt')
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.size', 5)
            ->assertJsonPath('data.mode', '0644');
    }

    public function test_stat_of_a_missing_entry_is_not_found_and_an_unknown_one_a_400(): void
    {
        $this->entry('/gone', 'missing');
        $this->entry('/odd', 'socket');

        $this->getJson('/api/projects/alice/files/stat?path=gone')->assertNotFound()->assertExactJson(['message' => 'Invalid path']);
        $this->getJson('/api/projects/alice/files/stat?path=odd')->assertStatus(400);
    }

    public function test_list_answers_with_the_page_and_its_meta(): void
    {
        $this->entry('/docs', 'dir');
        $this->broker->answer('account.listPage', "1\0" . implode("\0", ['a.txt', 'f', '5', '1700000000', '644', '1001', '1001', '', '']) . "\0");

        $this->getJson('/api/projects/alice/files/list?path=docs&limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'a.txt')
            ->assertJsonPath('meta.path', '/docs')
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_list_of_a_missing_directory_is_not_found_and_an_unknown_one_a_400(): void
    {
        $this->entry('/gone', 'missing');
        $this->entry('/odd', 'socket');

        $this->getJson('/api/projects/alice/files/list?path=gone')->assertNotFound();
        $this->getJson('/api/projects/alice/files/list?path=odd')->assertStatus(400);
    }

    public function test_an_upload_is_written_into_the_directory(): void
    {
        $this->entry('/docs', 'dir');

        $response = $this->post('/api/projects/alice/files/upload', [
            'path' => 'docs',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertExactJson(['success' => true]);
        $this->assertStringContainsString(self::HOME . '/docs', implode(' ', array_merge(...$this->broker->requests())));
    }

    public function test_an_upload_the_account_cannot_write_is_a_400(): void
    {
        $this->broker->answer('account.writeCopy', '', 1, 'Disk quota exceeded');

        $this->post('/api/projects/alice/files/upload', [
            'path' => 'docs',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        ], ['Accept' => 'application/json'])->assertStatus(400);
    }

    public function test_a_download_of_a_file_that_is_not_there_is_not_found(): void
    {
        $this->broker->answer('account.isFile', '', 1);

        $this->getJson('/api/projects/alice/files/download?path=a.txt')->assertNotFound()->assertExactJson(['message' => 'Invalid path']);
    }

    public function test_a_thumbnail_of_a_file_that_is_not_there_is_not_found(): void
    {
        $this->broker->answer('account.isFile', '', 1);

        $this->getJson('/api/projects/alice/files/thumbnail?path=a.png')->assertNotFound()->assertExactJson(['message' => 'Invalid path']);
    }

    public function test_a_thumbnail_the_stream_cannot_read_is_not_found(): void
    {
        $this->thumbnailSource();

        $this->getThumbnail()->assertNotFound()->assertExactJson(['message' => 'Invalid path']);
        $this->assertContains('account.fileStream', $this->broker->actions());
    }

    public function test_a_thumbnail_the_client_already_holds_is_not_rendered_again(): void
    {
        $this->thumbnailSource();
        $etag = (new FileThumbnail(new System()))->etag(User::findByUsernameOrFail('alice'), self::HOME . '/a.png', 176, 176, 'cover');

        $this->getThumbnail(['If-None-Match' => '"' . $etag . '"'])->assertStatus(304);
        $this->assertNotContains('account.fileStream', $this->broker->actions());
    }

    public function test_put_contents_writes_the_file_and_a_refusal_is_a_400(): void
    {
        $this->putJson('/api/projects/alice/files/put-contents', ['path' => '.htaccess', 'contents' => 'Deny from all'])
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->assertSame(self::HOME . '/.htaccess', $this->broker->valuesOf('account.writeCopy')[0][3]);

        $this->broker->answer('account.writeCopy', '', 1, 'Read-only file system');
        $this->putJson('/api/projects/alice/files/put-contents', ['path' => 'a.txt', 'contents' => 'x'])->assertStatus(400);
    }

    public function test_a_fetch_lands_under_the_name_from_the_url(): void
    {
        OutboundUrl::setResolverForTests(static fn (): array => [TestNetwork::routable(4)]);
        $this->entry('/docs', 'dir');

        $this->postJson('/api/projects/alice/files/fetch', ['url' => 'https://downloads.example.com/a.zip', 'path' => 'docs'])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertSame(self::HOME . '/docs/a.zip', end($this->broker->valuesOf('account.renameOver')[0]));
    }

    public function test_a_fetch_into_a_missing_directory_is_a_400(): void
    {
        OutboundUrl::setResolverForTests(static fn (): array => [TestNetwork::routable(4)]);
        $this->entry('/docs', 'missing');

        $this->postJson('/api/projects/alice/files/fetch', ['url' => 'https://downloads.example.com/a.zip', 'path' => 'docs'])
            ->assertStatus(400)
            ->assertExactJson(['message' => 'Destination directory does not exist']);
    }

    /** @param array<string, string> $headers */
    private function getThumbnail(array $headers = []): TestResponse
    {
        return $this->get('/api/projects/alice/files/thumbnail?path=a.png', $headers);
    }

    private function thumbnailSource(): void
    {
        $this->entry('/a.png', 'file');
        $this->broker->answer('account.entryFields', implode("\0", ['a.png', 'f', '5', '1700000000', '644', '1001', '1001', '', '']) . "\0");
    }

    private function entry(string $path, string $type): void
    {
        $this->broker->answer('account.entryType', $type . "\n", match: str_starts_with($path, self::HOME) ? $path : self::HOME . $path, exactly: true);
    }
}
