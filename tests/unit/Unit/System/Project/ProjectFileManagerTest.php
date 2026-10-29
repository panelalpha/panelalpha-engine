<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\ProblemException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\FileManager;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\InfoZip;
use Tests\Support\TestNetwork;
use Tests\TestCase;

class ProjectFileManagerTest extends TestCase
{
    private string $homeDir;

    /** @var list<list<string>> every command the project ran */
    private array $commands = [];

    /** @var array<string, string> links planted, link => target, just before an archive is unpacked */
    private array $plantedBeforeUnpack = [];

    /** A directory whose `mv` plants a link at its destination first, put ahead of PATH for the move script. */
    private ?string $mvShim = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeDir = sys_get_temp_dir() . '/pa-project-files-' . bin2hex(random_bytes(4));
        mkdir($this->homeDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->homeDir);
        $this->removeTree($this->homeDir . '.outside');
        parent::tearDown();
    }

    public function test_project_files_returns_collaborator_rooted_at_project_home(): void
    {
        $system = $this->systemWithDirectFilesystem();
        $project = new Project($system, $this->userModel('alice'));

        $files = $project->fileManager();

        $this->assertInstanceOf(FileManager::class, $files);
        $this->assertSame($this->homeDir, $files->homeDirPath());
    }

    public function test_resolve_builds_absolute_path_under_home(): void
    {
        $files = $this->fileManager();

        $this->assertSame($this->homeDir . '/project', $files->resolvePath('project'));
        $this->assertSame($this->homeDir . '/public_html/', $files->resolvePath('public_html/'));
    }

    public function test_resolve_rejects_path_traversal_and_control_characters(): void
    {
        $files = $this->fileManager();

        $rejected = 0;
        foreach (['../etc/passwd', "foo\0bar", "foo\x01bar", "foo\nbar", "foo\x0bbar", "foo\x7fbar"] as $path) {
            try {
                $files->resolvePath($path);
                $this->fail('Expected ValidationException');
            } catch (ValidationException) {
                $rejected++;
            }
        }
        $this->assertSame(6, $rejected);
    }

    public function test_aggregate_resolve_path_matches_files_collaborator(): void
    {
        $system = $this->systemWithDirectFilesystem();
        $project = new Project($system, $this->userModel('alice'));

        $this->assertSame($project->fileManager()->resolvePath('project'), $project->resolvePath('project'));
    }

    public function test_aggregate_resolve_path_replaces_legacy_var_www(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('A drive-letter home is not a slash-absolute path, which is what resolvePath confines.');
        }
        $model = $this->getMockBuilder(ModelsUser::class)
            ->onlyMethods(['getHomeDir'])
            ->getMock();
        $model->method('getHomeDir')->willReturn($this->homeDir);
        $model->username = 'alice';

        $system = $this->systemWithDirectFilesystem();
        $project = new Project($system, $model);

        $this->assertSame(
            $this->homeDir . '/project',
            $project->resolvePath('/var/www/project'),
        );
    }

    public function test_put_contents_mkdir_and_storage_round_trip(): void
    {
        $files = $this->fileManager();

        $files->mkdir('nested/dir', true);
        $files->putContents('nested/dir/hello.txt', 'panelalpha');

        $this->assertFileExists($this->homeDir . '/nested/dir/hello.txt');
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/nested/dir/hello.txt'));

        $files->storage()->put('via-storage.txt', 'storage');
        $this->assertSame('storage', $files->storage()->get('via-storage.txt'));
        $this->assertTrue($files->storage()->exists('nested/dir/hello.txt'));
        $this->assertContains('nested', $files->storage()->directories('/'));
        $this->assertContains('via-storage.txt', $files->storage()->files('/'));
    }

    public function test_exists_stat_move_copy_and_remove_use_the_project_home(): void
    {
        $this->requireGnuFind();

        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/hello.txt', 'panelalpha');

        $this->assertTrue($files->exists('hello.txt'));
        $this->assertFalse($files->exists('missing.txt'));
        $this->assertSame(
            filesize($this->homeDir . '/hello.txt'),
            $files->stat('hello.txt')['size']
        );

        $files->cp('hello.txt', 'copy.txt');
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/hello.txt'));
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/copy.txt'));

        $files->mv('copy.txt', 'moved.txt');
        $this->assertFileDoesNotExist($this->homeDir . '/copy.txt');
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/moved.txt'));

        mkdir($this->homeDir . '/tree');
        file_put_contents($this->homeDir . '/tree/inner.txt', 'in');
        $files->remove('tree', true);
        $this->assertDirectoryDoesNotExist($this->homeDir . '/tree');
        $files->remove('moved.txt');
        $this->assertFileDoesNotExist($this->homeDir . '/moved.txt');
    }

    public function test_zip_keeps_the_child_name_and_honours_level_date_and_empty(): void
    {
        $this->requireCommand('zip');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/site');
        file_put_contents($this->homeDir . '/site/old.txt', 'old');
        file_put_contents($this->homeDir . '/site/new.txt', str_repeat('a', 4096));
        touch($this->homeDir . '/site/old.txt', time() - 10 * 86400);
        touch($this->homeDir . '/site/new.txt', time());

        $files->zip('recent.zip', 'site', true, null, date('Y-m-d', time() - 86400));
        $recent = array_map('basename', $this->zipNames($this->homeDir . '/recent.zip'));
        $this->assertContains('new.txt', $recent);
        $this->assertNotContains('old.txt', $recent);

        file_put_contents($this->homeDir . '/site/zeros.bin', str_repeat("\0", 20000));
        $files->zip('plain.zip', 'site/zeros.bin', false, 0);
        $files->zip('tight.zip', 'site/zeros.bin', false, 9);
        $this->assertGreaterThan(
            filesize($this->homeDir . '/tight.zip'),
            filesize($this->homeDir . '/plain.zip')
        );

        mkdir($this->homeDir . '/empty');
        try {
            $files->zip('empty.zip', 'empty', true);
            $this->fail('An empty tree is a zip failure unless ignore_empty is set');
        } catch (\Exception) {
        }
        $files->zip('empty-ok.zip', 'empty', true, null, null, true);
    }

    public function test_unzip_restores_zip_and_tar_gz(): void
    {
        $this->requireCommand('zip');
        $this->requireCommand('unzip');
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/note.txt', 'restored');
        $files->zip('bundle.zip', 'src', true);

        mkdir($this->homeDir . '/out');
        $files->unzip('bundle.zip', 'out');
        $this->assertSame('restored', file_get_contents($this->homeDir . '/out/src/note.txt'));

        $tar = $this->homeDir . '/bundle.tar';
        $archive = new \PharData($tar);
        $archive->addFile($this->homeDir . '/src/note.txt', 'note.txt');
        $archive->compress(\Phar::GZ);
        unset($archive);

        mkdir($this->homeDir . '/tar-out');
        $files->unzip('bundle.tar.gz', 'tar-out');
        $this->assertSame('restored', file_get_contents($this->homeDir . '/tar-out/note.txt'));
    }

    /** The file manager refused nothing a deploy would refuse. */
    public function test_unzip_refuses_an_archive_that_unpacks_past_the_cap(): void
    {
        $this->requireCommand('tar');
        $files = new FileManager(
            new Project($this->systemWithDirectFilesystem(), $this->userModel('alice')),
            $this->homeDir . '/.unzip-stage',
            100 * 1024
        );
        $zeros = $this->homeDir . '/zeros.bin';
        file_put_contents($zeros, str_repeat("\0", 1024 * 1024));
        $archive = new \PharData($this->homeDir . '/bomb.tar');
        $archive->addFile($zeros, 'zeros.bin');
        $archive->compress(\Phar::GZ);
        unset($archive);
        mkdir($this->homeDir . '/bomb-out');

        try {
            $files->unzip('bomb.tar.gz', 'bomb-out');
            $this->fail('an archive past the cap must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('more than this server accepts', $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir($this->homeDir . '/bomb-out'), ['.', '..'])));
        $this->assertSame([], glob($this->homeDir . '/.unzip-stage/*') ?: [], 'the staged copy is removed');
    }

    /** Root read what the home check, run as the account, could not see: an archive in ~/docker. */
    public function test_unzip_reads_the_archive_as_the_account(): void
    {
        $this->requireCommand('zip');
        $this->requireCommand('unzip');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/note.txt', 'restored');
        $files->zip('bundle.zip', 'src', true);
        mkdir($this->homeDir . '/out');
        $this->commands = [];

        $files->unzip('bundle.zip', 'out');

        $this->assertSame('restored', file_get_contents($this->homeDir . '/out/src/note.txt'));
        $reads = array_filter($this->commands, fn (array $argv): bool => in_array($this->homeDir . '/bundle.zip', $argv, true));
        $this->assertNotSame([], $reads);
        foreach ($reads as $argv) {
            $this->assertSame(['sudo', 'setpriv'], array_slice($argv, 0, 2), 'read as root: ' . implode(' ', $argv));
        }
    }

    /** A link already in the destination would take a member outside the home: unzip and tar write through it. */
    public function test_unzip_refuses_a_member_below_a_link_out_of_the_destination(): void
    {
        $this->requireCommand('zip');
        $this->requireCommand('unzip');
        $this->requireCommand('tar');
        $files = $this->fileManager();
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        mkdir($this->homeDir . '/src/evil', 0777, true);
        file_put_contents($this->homeDir . '/src/evil/x.txt', 'escaped');
        file_put_contents($this->homeDir . '/note', 'escaped');
        $files->zip('bundle.zip', 'src/evil', true);
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' evil');
        exec('gzip -k ' . escapeshellarg($this->homeDir . '/note'));
        mkdir($this->homeDir . '/out');
        symlink($outside, $this->homeDir . '/out/evil');
        symlink($outside . '/note', $this->homeDir . '/out/note');

        foreach (['bundle.zip', 'bundle.tar.gz', 'note.gz'] as $archive) {
            $this->assertDestinationTaken(fn () => $files->unzip($archive, 'out'), $archive);
        }
        $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])));
    }

    /** tar lists `./ä/x` as `./\303\244/x` in the C locale, so no check on listed names sees the link. */
    public function test_unzip_refuses_a_non_ascii_member_below_a_link_out_of_the_destination(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        mkdir($this->homeDir . "/src/\u{e4}", 0777, true);
        file_put_contents($this->homeDir . "/src/\u{e4}/x", 'escaped');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' .');
        mkdir($this->homeDir . '/out');
        symlink($outside, $this->homeDir . "/out/\u{e4}");

        $this->assertDestinationTaken(fn () => $files->unzip('bundle.tar.gz', 'out'), 'bundle.tar.gz');
        $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])));
    }

    /** The account's container can plant a link in the destination while the archive is being unpacked. */
    public function test_unzip_does_not_write_through_a_link_planted_during_the_unpack(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        mkdir($this->homeDir . '/src/evil', 0777, true);
        file_put_contents($this->homeDir . '/src/evil/x', 'escaped');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' evil');
        mkdir($this->homeDir . '/out');
        $this->plantedBeforeUnpack = [$this->homeDir . '/out/evil' => $outside];

        $this->assertDestinationTaken(fn () => $files->unzip('bundle.tar.gz', 'out'), 'bundle.tar.gz');
        $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])));
    }

    public function test_unzip_refuses_a_destination_that_holds_an_entry_and_extracts_nothing(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/index.php', 'new');
        file_put_contents($this->homeDir . '/src/extra.txt', 'new');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/site.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' index.php extra.txt');
        mkdir($this->homeDir . '/public');
        file_put_contents($this->homeDir . '/public/index.php', 'mine');

        $e = $this->assertDestinationTaken(fn () => $files->unzip('site.tar.gz', 'public'), 'site.tar.gz');

        $this->assertSame(409, $e->status);
        $this->assertStringContainsString('/public already holds 1 of the entries the archive unpacks to (index.php)', $e->getMessage());
        $this->assertSame('mine', file_get_contents($this->homeDir . '/public/index.php'));
        $this->assertFileDoesNotExist($this->homeDir . '/public/extra.txt');
        $this->assertSame([], glob($this->homeDir . '/.unzip-stage/*') ?: [], 'the unpacked copy is removed');
    }

    /** The account's container can create an entry after the destination was checked, before its rename. */
    public function test_unzip_answers_destination_exists_when_an_entry_appears_before_its_rename(): void
    {
        $this->assertRaceAnswersDestinationExists(removedAfterTheRename: false);
    }

    /** The account can remove what it planted again before anything could take a second look at it. */
    public function test_unzip_answers_destination_exists_when_the_raced_entry_is_gone_again(): void
    {
        $this->assertRaceAnswersDestinationExists(removedAfterTheRename: true);
    }

    /** A regular file the account creates in that window is its own file: the rename must not replace it. */
    public function test_unzip_does_not_replace_a_file_that_appears_before_its_rename(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/note.txt', 'archived');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' note.txt');
        mkdir($this->homeDir . '/race');
        $this->plantAroundEachRename('printf mine > "$last"', removedAfter: false);

        $e = $this->assertDestinationTaken(fn () => $files->unzip('bundle.tar.gz', 'race'), 'bundle.tar.gz');

        $this->assertSame(409, $e->status);
        $this->assertStringContainsString('note.txt could not be moved into /race: an entry of that name appeared there', $e->getMessage());
        $this->assertStringNotContainsString('mv:', $e->getMessage());
        $this->assertSame('mine', file_get_contents($this->homeDir . '/race/note.txt'));
    }

    public function test_unzip_does_not_replace_an_empty_folder_that_appears_before_its_rename(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src/assets', 0777, true);
        file_put_contents($this->homeDir . '/src/assets/app.css', 'archived');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' assets');
        mkdir($this->homeDir . '/race');
        $this->plantAroundEachRename('mkdir "$last"', removedAfter: false);

        $e = $this->assertDestinationTaken(fn () => $files->unzip('bundle.tar.gz', 'race'), 'bundle.tar.gz');

        $this->assertSame(409, $e->status);
        $this->assertStringContainsString('assets could not be moved into /race', $e->getMessage());
        $this->assertSame([], array_values(array_diff(scandir($this->homeDir . '/race/assets'), ['.', '..'])));
    }

    public function test_unzip_refuses_a_destination_the_account_cannot_write_to(): void
    {
        $this->requireCommand('tar');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a 0555 directory.');
        }
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/note.txt', 'new');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' note.txt');
        mkdir($this->homeDir . '/locked', 0555);

        try {
            $files->unzip('bundle.tar.gz', 'locked');
            $this->fail('an archive was extracted into a folder the account cannot write to');
        } catch (ValidationException $e) {
            $this->assertSame(['/locked is not writable.'], $e->errors()['path']);
        } finally {
            chmod($this->homeDir . '/locked', 0755);
        }
    }

    private function assertRaceAnswersDestinationExists(bool $removedAfterTheRename): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        mkdir($this->homeDir . "/src/l\u{e4}b", 0777, true);
        file_put_contents($this->homeDir . "/src/l\u{e4}b/x", 'escaped');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/race.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . " l\u{e4}b");
        mkdir($this->homeDir . '/race');
        $this->plantLinkAroundEachRename($outside, $removedAfterTheRename);

        $e = $this->assertDestinationTaken(fn () => $files->unzip('race.tar.gz', 'race'), 'race.tar.gz');

        $this->assertSame(409, $e->status);
        $this->assertStringContainsString("l\u{e4}b could not be moved into /race: an entry of that name appeared there", $e->getMessage());
        $this->assertStringNotContainsString('unzip-stage', $e->getMessage());
        $this->assertStringNotContainsString('mv:', $e->getMessage());
        $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])));
        $this->assertSame($removedAfterTheRename, !is_link($this->homeDir . "/race/l\u{e4}b"));
    }

    public function test_unzip_creates_a_missing_destination(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/note.txt', 'restored');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' note.txt');

        $files->unzip('bundle.tar.gz', 'new/dir');

        $this->assertSame('restored', file_get_contents($this->homeDir . '/new/dir/note.txt'));
    }

    /** The deploy's archive rules, here too: a link member pointing out of the archive. */
    public function test_unzip_refuses_an_archive_a_deploy_would_refuse(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        symlink('/etc', $this->homeDir . '/src/etc');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/links.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . ' etc');
        mkdir($this->homeDir . '/out');

        try {
            $files->unzip('links.tar.gz', 'out');
            $this->fail('an archive with a link out of it was extracted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('zip_path', $e->errors());
        }
        $this->assertFalse(is_link($this->homeDir . '/out/etc'));
    }

    /** tar lists `x€` as `x\342\202\254` in the C locale: four components to a check that splits on `\`. */
    public function test_unzip_refuses_a_link_climbing_out_past_a_non_ascii_directory(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . "/src/x\u{20ac}", 0777, true);
        symlink("x\u{20ac}/../../../..", $this->homeDir . '/src/l');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/links.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . " x\u{20ac} l");
        mkdir($this->homeDir . '/out');

        try {
            $files->unzip('links.tar.gz', 'out');
            $this->fail('an archive with a link out of it was extracted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('symbolic link', $e->errors()['zip_path'][0]);
        }
        $this->assertFalse(is_link($this->homeDir . '/out/l'));
    }

    public function test_unzip_extracts_a_tarball_whose_member_starts_with_a_non_ascii_character(): void
    {
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . "/src/\u{e4}", 0777, true);
        file_put_contents($this->homeDir . "/src/\u{e4}/x", 'restored');
        exec('tar -czf ' . escapeshellarg($this->homeDir . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->homeDir . '/src') . " \u{e4}");

        $files->unzip('bundle.tar.gz', 'out');

        $this->assertSame('restored', file_get_contents($this->homeDir . "/out/\u{e4}/x"));
    }

    /**
     * `zip -r` on Linux or macOS flags a non-ASCII name as UTF-8. PHP-FPM runs in the C locale,
     * where unzip would list and write it as `za#U017c...`; every unzip runs in UTF-8.
     */
    public function test_unzip_keeps_the_non_ascii_names_of_an_info_zip_archive(): void
    {
        $this->requireCommand('unzip');
        $files = $this->fileManager();
        $name = "za\u{17c}\u{f3}\u{142}\u{107}/\u{105}.txt";
        InfoZip::utf8Flagged($this->homeDir . '/site.zip', $name);
        $this->commands = [];

        $files->unzip('site.zip', 'out');

        $this->assertSame('x', file_get_contents($this->homeDir . '/out/' . $name));
        $unzips = array_filter($this->commands, fn (array $argv): bool => in_array('unzip', $argv, true));
        $this->assertNotSame([], $unzips);
        foreach ($unzips as $argv) {
            $at = (int) array_search('unzip', $argv, true);
            $this->assertSame(['env', 'LC_ALL=C.UTF-8'], array_slice($argv, $at - 2, 2), implode(' ', $argv));
        }
    }

    public function test_move_directory_contents_moves_children_only(): void
    {
        $files = $this->fileManager();
        mkdir($this->homeDir . '/source');
        mkdir($this->homeDir . '/dest');
        file_put_contents($this->homeDir . '/source/a.txt', 'new');
        file_put_contents($this->homeDir . '/source/b.txt', 'b');
        file_put_contents($this->homeDir . '/dest/a.txt', 'old');

        $files->moveDirectoryContents('source', 'dest', false);
        $this->assertSame('old', file_get_contents($this->homeDir . '/dest/a.txt'));
        $this->assertSame('new', file_get_contents($this->homeDir . '/source/a.txt'));
        $this->assertSame('b', file_get_contents($this->homeDir . '/dest/b.txt'));
        $this->assertDirectoryExists($this->homeDir . '/source');

        $files->moveDirectoryContents('source', 'dest');
        $this->assertSame('new', file_get_contents($this->homeDir . '/dest/a.txt'));
        $this->assertFileDoesNotExist($this->homeDir . '/source/a.txt');
        $this->assertDirectoryExists($this->homeDir . '/source');
    }

    public function test_move_directory_contents_refuses_a_missing_destination(): void
    {
        $files = $this->fileManager();
        mkdir($this->homeDir . '/source');
        file_put_contents($this->homeDir . '/source/a.txt', 'a');

        $this->expectException(\Exception::class);
        $files->moveDirectoryContents('source', 'missing');
    }

    public function test_fetch_stores_an_http_url_and_rejects_other_schemes(): void
    {
        $files = $this->fileManager(allowLocalFixtureServer: true);
        $web = $this->homeDir . '/web';
        mkdir($web);
        mkdir($this->homeDir . '/inbox');
        file_put_contents($web . '/plugin.zip', 'archive-bytes');
        [$server, $port] = $this->serve($web);

        try {
            $files->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/plugin.zip', 'inbox');
            $this->assertSame('archive-bytes', file_get_contents($this->homeDir . '/inbox/plugin.zip'));

            $files->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/plugin.zip', 'inbox', 'renamed.bin');
            $this->assertSame('archive-bytes', file_get_contents($this->homeDir . '/inbox/renamed.bin'));
        } finally {
            $server->stop();
        }

        try {
            $files->fetch('file:///etc/passwd', 'inbox', 'stolen.txt');
            $this->fail('A non-http URL must be rejected');
        } catch (\Exception) {
        }
        $this->assertFileDoesNotExist($this->homeDir . '/inbox/stolen.txt');

        try {
            $files->fetch('http://' . TestNetwork::LOOPBACK . '/', 'inbox');
            $this->fail('A URL with no file name must be rejected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('filename', $e->getMessage());
        }
    }

    /** With or without a Content-Length, past the limit is refused, and an existing file is left as it was. */
    public function test_fetch_refuses_a_file_past_the_limit_and_leaves_nothing_behind(): void
    {
        $files = $this->fileManager(allowLocalFixtureServer: true, fetchMaxBytes: 1024);
        $web = $this->homeDir . '/web';
        mkdir($web);
        mkdir($this->homeDir . '/inbox');
        file_put_contents($web . '/big.bin', str_repeat('x', 64 * 1024));
        file_put_contents($web . '/stream.php', '<?php for ($i = 0; $i < 64; $i++) { echo str_repeat("x", 1024); flush(); }');
        file_put_contents($this->homeDir . '/inbox/big.bin', 'kept');
        [$server, $port] = $this->serve($web);

        try {
            foreach (['big.bin', 'stream.php'] as $name) {
                try {
                    $files->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/' . $name, 'inbox');
                    $this->fail("{$name} past the limit was stored");
                } catch (\Exception $e) {
                    $this->assertStringContainsString('MB limit', $e->getMessage(), $name);
                }
            }
        } finally {
            $server->stop();
        }

        $this->assertSame(['big.bin'], array_values(array_diff(scandir($this->homeDir . '/inbox'), ['.', '..'])));
        $this->assertSame('kept', file_get_contents($this->homeDir . '/inbox/big.bin'));
    }

    /** A failed download is a short reason on `url`, never curl's own error text. */
    public function test_a_failed_fetch_is_a_problem_on_url_with_a_short_reason(): void
    {
        $files = $this->fileManager(allowLocalFixtureServer: true);
        $web = $this->homeDir . '/web';
        mkdir($web);
        mkdir($this->homeDir . '/inbox');
        [$server, $port] = $this->serve($web);
        try {
            $missing = $this->fetchProblem($files, 'http://' . TestNetwork::LOOPBACK . ':' . $port . '/missing.zip');
        } finally {
            $server->stop();
        }
        $closed = $this->fetchProblem($files, 'http://' . TestNetwork::LOOPBACK . ':' . $port . '/plugin.zip');

        $this->assertSame(['url', 'download_failed', 'http', 404], [$missing['field'], $missing['code'], $missing['reason'], $missing['http_status']]);
        $this->assertSame('http://' . TestNetwork::LOOPBACK . ':' . $port . '/missing.zip answered HTTP 404.', $missing['message']);
        $this->assertSame(['url', 'download_failed', 'connect'], [$closed['field'], $closed['code'], $closed['reason']]);
        $this->assertSame('Could not connect to ' . TestNetwork::LOOPBACK . '.', $closed['message']);
        $this->assertSame([], array_values(array_diff(scandir($this->homeDir . '/inbox'), ['.', '..'])));
    }

    /** @return array<string, mixed> */
    private function fetchProblem(FileManager $files, string $url): array
    {
        try {
            $files->fetch($url, 'inbox');
        } catch (ProblemException $e) {
            $this->assertStringNotContainsString('curl', $e->problems[0]['message']);

            return $e->problems[0];
        }
        $this->fail("{$url} was fetched");
    }

    public function test_a_path_that_escapes_the_home_is_refused_under_path(): void
    {
        $files = $this->fileManager();

        try {
            $files->resolvePath('../escape');
            $this->fail('a path that escapes the home must be refused');
        } catch (ValidationException $e) {
            $this->assertSame(['Invalid path'], $e->errors()['path']);
        }
    }

    public function test_chmod_sets_an_octal_mode_and_rejects_a_bad_one(): void
    {
        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/script.sh', "#!/bin/sh\n");
        chmod($this->homeDir . '/script.sh', 0644);

        $files->chmod('script.sh', '755');
        $mode = fileperms($this->homeDir . '/script.sh') & 0777;
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0755, $mode);
        }

        try {
            $files->chmod('script.sh', '999');
            $this->fail('A non-octal mode must be rejected');
        } catch (\Exception) {
        }
        $this->assertSame($mode, fileperms($this->homeDir . '/script.sh') & 0777);

        $this->expectException(ValidationException::class);
        $files->chmod('../outside.sh', '755');
    }

    public function test_disk_usage_counts_megabytes_of_the_project_home(): void
    {
        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/blob.bin', str_repeat('x', 2 * 1024 * 1024));

        $this->assertGreaterThanOrEqual(1, $files->diskUsage('/'));
    }

    public function test_disk_usage_skips_the_dind_data_root_the_project_cannot_read(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a 0000 directory, so the unreadable ~/docker cannot be reproduced.');
        }
        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/blob.bin', str_repeat('x', 2 * 1024 * 1024));
        mkdir($this->homeDir . '/docker');
        chmod($this->homeDir . '/docker', 0000);

        try {
            $this->assertGreaterThanOrEqual(1, $files->diskUsage('/'));
        } finally {
            chmod($this->homeDir . '/docker', 0755);
        }
    }

    public function test_disk_usage_still_counts_a_docker_directory_below_the_home_root(): void
    {
        $files = $this->fileManager();
        mkdir($this->homeDir . '/project/docker', 0777, true);
        file_put_contents($this->homeDir . '/project/docker/blob.bin', str_repeat('x', 3 * 1024 * 1024));

        $this->assertGreaterThanOrEqual(3, $files->diskUsage('/'));
    }

    private function plantLinkAroundEachRename(string $target, bool $removedAfter): void
    {
        $this->plantAroundEachRename('ln -s ' . escapeshellarg($target) . ' "$last"', $removedAfter);
    }

    private function plantAroundEachRename(string $plant, bool $removedAfter): void
    {
        $mv = (string) (new \Symfony\Component\Process\ExecutableFinder())->find('mv');
        $this->mvShim = $this->homeDir . '/.mv-shim';
        mkdir($this->mvShim);
        file_put_contents($this->mvShim . '/mv', implode("\n", [
            '#!/bin/sh',
            'for last; do :; done',
            $plant,
            escapeshellarg($mv) . ' "$@"; status=$?',
            $removedAfter ? 'rm -f "$last"' : ':',
            'exit $status',
            '',
        ]));
        chmod($this->mvShim . '/mv', 0755);
    }

    private function assertDestinationTaken(callable $unzip, string $archive): ProblemException
    {
        try {
            $unzip();
        } catch (ProblemException $e) {
            $this->assertSame('destination_exists', $e->problems[0]['code'], $archive);

            return $e;
        }
        $this->fail("{$archive} was extracted over an entry already in the destination");
    }

    private function userModel(string $username): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails(['template' => 'dind', 'deploy_strategy' => 'php', 'UID' => 1001, 'GID' => 1001]);

        return $model;
    }

    private function fileManager(bool $allowLocalFixtureServer = false, int $fetchMaxBytes = FileManager::MAX_FETCH_BYTES): FileManager
    {
        if (!$allowLocalFixtureServer) {
            return new FileManager(
                new Project($this->systemWithDirectFilesystem(), $this->userModel('alice')),
                $this->homeDir . '/.unzip-stage'
            );
        }

        // The fixture server binds loopback, which the outbound guard refuses
        // in production (engine#785). Bypass it only for tests that exercise
        // the fetch *mechanics* (destinations, names, redirects).
        return new class (
            new Project($this->systemWithDirectFilesystem(), $this->userModel('alice')),
            $this->homeDir . '/.unzip-stage',
            fetchMaxBytes: $fetchMaxBytes,
        ) extends FileManager {
            protected function assertOutboundAllowed(string $url): void
            {
            }

            protected function outboundPin(string $url): ?string
            {
                return null;
            }
        };
    }

    private function systemWithDirectFilesystem(): System
    {
        $commands = &$this->commands;
        $planted = &$this->plantedBeforeUnpack;
        $mvShim = &$this->mvShim;

        return new class ($this->homeDir, $commands, $planted, $mvShim) extends System {
            /**
             * @param list<list<string>> $commands
             * @param array<string, string> $planted
             */
            public function __construct(
                private string $homeRoot,
                private array &$commands,
                private array &$planted,
                private ?string &$mvShim,
            ) {
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homeRoot;
            }

            public function projectDirPath(string $username): string
            {
                return $this->homeRoot . '/engine';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $dir = dirname($path);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        file_put_contents($path, $contents);
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        if (!is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (!is_array($cmd) || in_array('docker', $cmd, true)) {
                    throw new \RuntimeException('Project file operations must not enter the project service container');
                }

                $this->commands[] = $cmd;
                $argv = $cmd;
                if (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'setpriv') {
                    $argv = array_slice($argv, 7);
                } elseif (($argv[0] ?? '') === 'sudo') {
                    $argv = array_slice($argv, 1);
                }
                if (($argv[0] ?? '') === 'chown') {
                    // Root's handover of the stage: the test need not run as root.
                    $argv = ['true'];
                }
                $cwd = null;
                if (($argv[0] ?? '') === 'env' && is_string($argv[1] ?? null) && str_starts_with($argv[1], '--chdir=')) {
                    $cwd = substr($argv[1], strlen('--chdir='));
                    $argv = array_slice($argv, 2);
                }

                $tool = ($argv[0] ?? '') === 'env' && ($argv[1] ?? '') === 'LC_ALL=C.UTF-8' ? array_slice($argv, 2) : $argv;
                if (in_array($tool[0] ?? '', ['tar', 'unzip'], true) && !in_array($tool[1] ?? '', ['--quoting-style=escape', '-Z1', '-Z', '-p'], true)) {
                    $this->plantLinks();
                }

                $env = ['LC_ALL' => 'C'];
                if ($this->mvShim !== null && ($argv[0] ?? '') === 'sh') {
                    $env['PATH'] = $this->mvShim . ':' . getenv('PATH');
                }
                $process = new Process($argv, $cwd, $env);
                $process->run();

                return $process;
            }

            private function plantLinks(): void
            {
                foreach ($this->planted as $link => $target) {
                    symlink($target, $link);
                }
                $this->planted = [];
            }
        };
    }

    private function requireCommand(string $name): void
    {
        if ((new \Symfony\Component\Process\ExecutableFinder())->find($name) === null) {
            $this->markTestSkipped($name . ' is not installed');
        }
    }

    /**
     * @return list<string>
     */
    private function zipNames(string $path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        $zip->close();

        return $names;
    }

    /**
     * @return array{0: Process, 1: int}
     */
    private function serve(string $docroot): array
    {
        $socket = stream_socket_server('tcp://' . TestNetwork::LOOPBACK . ':0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);

        $process = new Process([PHP_BINARY, '-S', TestNetwork::LOOPBACK . ':' . $port, '-t', $docroot]);
        $process->setTimeout(null);
        $process->start();

        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $client = @fsockopen(TestNetwork::LOOPBACK, $port, $errno, $errstr, 0.1);
            if (is_resource($client)) {
                fclose($client);

                return [$process, $port];
            }
            usleep(50000);
        }

        $process->stop();
        $this->fail('Local server did not start: ' . $process->getErrorOutput());
    }

    private function removeTree(string $path): void
    {
        // rm, not a PHP walk: that follows a link to a directory, and these tests plant links to /etc.
        exec('rm -rf ' . escapeshellarg($path));
    }
}
