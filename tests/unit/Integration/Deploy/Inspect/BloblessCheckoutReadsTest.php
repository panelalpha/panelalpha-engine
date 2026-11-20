<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\BloblessCheckout;
use App\Lib\Deploy\Inspect\DetectionFiles;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\TreeUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * What a bare clone written out file by file does when the clone is missing, holds nothing detection reads,
 * lacks a blob it must read, or lists more decisive files than it may fetch.
 */
class BloblessCheckoutReadsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not on PATH');
        }
        $this->tmp = sys_get_temp_dir().'/blobless-'.bin2hex(random_bytes(6));
        mkdir($this->tmp.'/origin', 0700, true);
        $this->git('init -q -b main');
        $this->git('config uploadpack.allowFilter true');
    }

    protected function tearDown(): void
    {
        ResolvedSource::removeTree($this->tmp);
        parent::tearDown();
    }

    private function git(string $args, string $dir = 'origin'): string
    {
        return trim((string) shell_exec(
            'git -C '.escapeshellarg($this->tmp.'/'.$dir).' -c user.name=t -c user.email=t@example.com '.$args.' 2>&1'
        ));
    }

    /**
     * @param  array<string, string>  $files
     */
    private function commit(array $files): void
    {
        foreach ($files as $path => $contents) {
            $file = $this->tmp.'/origin/'.$path;
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            file_put_contents($file, $contents);
        }
        $this->git('add -A');
        $this->git('commit -qm change');
    }

    private function bareClone(bool $blobless): BloblessCheckout
    {
        shell_exec(sprintf(
            'git clone -q --bare %s file://%s %s 2>&1',
            $blobless ? '--filter=blob:none' : '',
            escapeshellarg($this->tmp.'/origin'),
            escapeshellarg($this->tmp.'/repo.git')
        ));

        return new BloblessCheckout($this->tmp.'/repo.git', 30);
    }

    public function test_a_clone_that_is_not_there_has_no_head_and_no_tree(): void
    {
        $checkout = new BloblessCheckout($this->tmp.'/missing.git', 30);

        $this->assertSame(['commit' => null, 'branch' => null], $checkout->head());

        $this->expectExceptionObject(new TreeUnavailable('git ls-tree failed.'));
        $checkout->writeTo($this->tmp.'/out', null, static function (array $ids): void {});
    }

    public function test_a_tree_holding_nothing_detection_reads_is_written_out_empty_without_a_fetch(): void
    {
        $this->commit(['notes/todo.txt' => 'remember the milk']);
        $checkout = $this->bareClone(false);
        $fetched = [];

        $checkout->writeTo($this->tmp.'/out', null, static function (array $ids) use (&$fetched): void {
            $fetched[] = $ids;
        });

        $this->assertSame([], $fetched);
        $this->assertSame('', file_get_contents($this->tmp.'/out/notes/todo.txt'));
        $this->assertDirectoryExists($this->tmp.'/out/.git');
    }

    public function test_a_decisive_blob_the_fetch_did_not_bring_is_unavailable(): void
    {
        $this->commit(['package.json' => '{"name":"app"}']);
        $checkout = $this->bareClone(true);
        $asked = [];

        try {
            $checkout->writeTo($this->tmp.'/out', null, static function (array $ids) use (&$asked): void {
                $asked = $ids;
            });
            $this->fail('A checkout missing package.json was written');
        } catch (TreeUnavailable $e) {
            $this->assertSame('Could not fetch package.json.', $e->getMessage());
        }

        $this->assertSame([$this->git('rev-parse HEAD:package.json')], $asked);
        $this->assertDirectoryDoesNotExist($this->tmp.'/out');
    }

    public function test_more_decisive_files_than_a_partial_clone_fetches_is_unavailable(): void
    {
        $files = [];
        foreach (range(1, DetectionFiles::MAX_FILES + 1) as $i) {
            $files["s{$i}/Dockerfile"] = "FROM nginx\n";
        }
        $this->commit($files);
        $checkout = $this->bareClone(false);

        $this->expectExceptionObject(new TreeUnavailable('More files to read than a partial clone fetches.'));

        $checkout->writeTo($this->tmp.'/out', null, static function (array $ids): void {});
    }
}
