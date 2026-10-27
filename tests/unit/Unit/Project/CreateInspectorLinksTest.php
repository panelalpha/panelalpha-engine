<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Project\CreateInspector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A create from a public GitHub URL inspects the repository's file list, which
 * keeps a link only when it points inside the repository on paper. A chain of
 * such links still led out, and the repository's `.panelalpha/files` was
 * written through it into the engine's own storage.
 */
class CreateInspectorLinksTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/create-inspector-links-'.bin2hex(random_bytes(6));
        mkdir($this->tmp.'/outside', 0755, true);
    }

    protected function tearDown(): void
    {
        ResolvedSource::removeTree($this->tmp);
        parent::tearDown();
    }

    public function test_a_chain_of_links_out_of_the_workspace_skips_the_inspection_and_writes_nothing_outside(): void
    {
        self::fakeRepository(
            [
                'index.html' => '<!doctype html><title>x</title>',
                '.panelalpha/panelalpha.yaml' => "description: x\n",
                '.panelalpha/files/x/evil.php' => '<?php echo "written outside";',
            ],
            ['here' => '.', 'up' => 'here/..', 'x' => 'up/../outside']
        );

        $inspection = $this->inspector()->inspect('https://github.com/owner/repo');

        $this->assertNull($inspection->toResponse());
        $this->assertStringContainsString('x is a symbolic link in the repository', (string) $inspection->skipped);
        $this->assertSame(['.', '..'], scandir($this->tmp.'/outside'));
        $this->assertSame([], glob($this->tmp.'/inspect-*') ?: []);
    }

    public function test_a_link_off_the_files_path_does_not_stop_the_inspection(): void
    {
        self::fakeRepository(
            [
                'index.html' => '<!doctype html><title>x</title>',
                '.panelalpha/panelalpha.yaml' => "description: x\n",
                '.panelalpha/files/public/robots.txt' => "User-agent: *\n",
            ],
            ['docs' => 'public']
        );

        $inspection = $this->inspector()->inspect('https://github.com/owner/repo');

        $this->assertNull($inspection->skipped);
        $this->assertSame('static', $inspection->strategy);
    }

    private function inspector(): CreateInspector
    {
        $tree = new GitHubTree(static fn (string $url, ?string $branch): array => [self::SHA, 'main'], 20);

        return new CreateInspector(new SourceResolver($this->tmp, 20, $tree));
    }

    /**
     * owner/repo on GitHub holding $files and the symbolic links $links (path => target).
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $links
     */
    private static function fakeRepository(array $files, array $links): void
    {
        $tree = [];
        foreach ($files as $path => $body) {
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'size' => strlen($body)];
        }
        foreach ($links as $path => $target) {
            $tree[] = ['path' => $path, 'mode' => '120000', 'type' => 'blob', 'size' => strlen($target)];
        }
        $bodies = $files + $links;
        Http::fake(static function (Request $request) use ($bodies, $tree) {
            if (str_contains($request->url(), 'api.github.com/repos/owner/repo/git/trees/')) {
                return Http::response(['tree' => $tree]);
            }
            $prefix = 'https://raw.githubusercontent.com/owner/repo/'.self::SHA.'/';
            $path = str_starts_with($request->url(), $prefix) ? rawurldecode(substr($request->url(), strlen($prefix))) : null;

            return $path !== null && isset($bodies[$path]) ? Http::response($bodies[$path]) : Http::response('', 404);
        });
    }
}
