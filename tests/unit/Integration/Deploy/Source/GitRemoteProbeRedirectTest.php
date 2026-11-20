<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The probe follows no redirect, so a moved repository or a GitLab address without `.git`
 * is refused naming where it went, not as one that could not be read.
 */
class GitRemoteProbeRedirectTest extends TestCase
{
    private const REFUSED = "fatal: unable to access 'https://gitlab.example/group/project/': The requested URL returned error: 301";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/probe-redirect-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_redirect_is_refused_with_the_address_the_remote_names(): void
    {
        $problem = $this->probe(self::REFUSED, 'https://gitlab.example/group/project.git')
            ->problem('git_repo', 'https://gitlab.example/group/project', null);

        $this->assertSame('git_repo_redirect_not_followed', $problem['code']);
        $this->assertSame('git_repo', $problem['field']);
        $this->assertStringContainsString('use https://gitlab.example/group/project.git instead', $problem['message']);
    }

    public function test_a_redirect_whose_target_cannot_be_learned_is_still_called_a_redirect(): void
    {
        $problem = $this->probe(self::REFUSED, null)->problem('git_repo', 'https://gitlab.example/group/project', null);

        $this->assertSame('git_repo_redirect_not_followed', $problem['code']);
        $this->assertStringContainsString('does not follow Git redirects', $problem['message']);
    }

    public function test_another_http_error_is_still_unreadable(): void
    {
        $problem = $this->probe("fatal: unable to access 'https://gitlab.example/g/p/': The requested URL returned error: 500", null)
            ->problem('git_repo', 'https://gitlab.example/g/p', null);

        $this->assertSame('git_repo_unreadable', $problem['code']);
    }

    public function test_the_lookup_asks_the_ref_listing_once_and_reads_a_relative_location(): void
    {
        $problem = $this->probe(self::REFUSED, '/group/project.git/info/refs?service=git-upload-pack')
            ->problem('git_repo', 'https://gitlab.example/group/project', null);

        $this->assertStringContainsString('use https://gitlab.example/group/project.git instead', $problem['message']);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://gitlab.example/group/project/info/refs?service=git-upload-pack');
    }

    private function probe(string $refusal, ?string $location): GitRemoteProbe
    {
        $git = $this->dir.'/git';
        file_put_contents($git, "#!/bin/sh\necho ".escapeshellarg($refusal)." >&2; exit 128\n");
        chmod($git, 0755);

        Http::preventStrayRequests();
        Http::fake(['*' => $location === null ? Http::response('', 200) : Http::response('', 301, ['Location' => $location])]);

        return new class($git) extends GitRemoteProbe
        {
            public function __construct(private string $git)
            {
                parent::__construct(10, 1);
            }

            protected function run(array $command): array
            {
                $command[array_search('git', $command, true)] = $this->git;

                return parent::run($command);
            }
        };
    }
}
