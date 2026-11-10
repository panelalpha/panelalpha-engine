<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder written when sources were ingested but no strategy could bootstrap them,
 * and the page the user shipped that it never overwrites.
 */
class ComposeWriterFallbackIndexTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const PAGE = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PanelAlpha — Project not configured</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 80px auto; padding: 0 20px; color: #333; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: .9em; }
        a { color: #0070f3; }
    </style>
</head>
<body>
    <h2>Project not ready yet</h2>
    <p>
        Your project (__SOURCE__)
        does not contain a <code>docker-compose.yml</code> and PanelAlpha Engine could not
        determine how to bootstrap it automatically.
    </p>
    <p>
        To get started, log in to your account and prepare <code>docker-compose.yml</code>
        in the <code>~/project</code> directory, then deploy the project again.
    </p>
</body>
</html>
HTML;

    private string $homes;

    private string $projectDir;

    /** @var list<array{0: string, 1: string, 2: ?string, 3: ?string}> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->homes = sys_get_temp_dir().'/pa-fallback-index-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->homes.'/acme/project';
        mkdir($this->projectDir, 0777, true);
        AccountHomeRead::useHomesDir($this->homes);
    }

    protected function tearDown(): void
    {
        AccountHomeRead::useHomesDir(null);
        exec('rm -rf '.escapeshellarg($this->homes));
        parent::tearDown();
    }

    public function test_a_web_address_is_linked_and_escaped(): void
    {
        $this->writer()->writeFallbackIndexHtml($this->projectDir, 'HTTPS://example.test/acme/app.git?a=1&b="2"', 'acme:acme');

        $source = '<a href="HTTPS://example.test/acme/app.git?a=1&amp;b=&quot;2&quot;" target="_blank" rel="noopener">'
            .'HTTPS://example.test/acme/app.git?a=1&amp;b=&quot;2&quot;</a>';
        $this->assertSame([[$this->projectDir.'/index.html', $this->page($source), 'acme:acme', '644']], $this->writes);
    }

    public function test_any_other_source_is_named_as_code(): void
    {
        $this->writer()->writeFallbackIndexHtml($this->projectDir, "upload <app.zip> & 'more'", null);

        $source = '<code>upload &lt;app.zip&gt; &amp; &#039;more&#039;</code>';
        $this->assertSame([[$this->projectDir.'/index.html', $this->page($source), null, '644']], $this->writes);
    }

    public function test_a_git_address_that_is_not_http_is_code_too(): void
    {
        $this->writer()->writeFallbackIndexHtml($this->projectDir, 'git@example.test:acme/app.git', null);

        $this->assertSame($this->page('<code>git@example.test:acme/app.git</code>'), $this->writes[0][1] ?? null);
    }

    public function test_a_page_the_user_shipped_is_never_overwritten(): void
    {
        file_put_contents($this->projectDir.'/index.html', '<html><title>My site</title></html>');

        $placeholder = $this->writer()->writeFallbackIndexHtml($this->projectDir, 'https://example.test/acme/app.git', null);

        $this->assertSame([], $this->writes);
        $this->assertFalse($placeholder, 'the front page is the user\'s, not the placeholder');
    }

    public function test_an_engine_placeholder_is_replaced(): void
    {
        file_put_contents($this->projectDir.'/index.html', '<html><title>'.PlaceholderPage::WELCOME_TITLE.'</title></html>');

        $placeholder = $this->writer()->writeFallbackIndexHtml($this->projectDir, 'archive', 'acme:acme');

        $this->assertSame([[$this->projectDir.'/index.html', $this->page('<code>archive</code>'), 'acme:acme', '644']], $this->writes);
        $this->assertTrue($placeholder);
    }

    private function page(string $source): string
    {
        return str_replace('__SOURCE__', $source, self::PAGE);
    }

    private function writer(): ComposeWriter
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('writeFileReplacingPath')->andReturnUsing(function (string $path, string $contents, ?string $chown = null, ?string $chmod = null): void {
            $this->writes[] = [$path, $contents, $chown, $chmod];
        });
        $system = Mockery::mock(System::class);
        $system->shouldReceive('filesystem')->andReturn($filesystem);
        $project = Mockery::mock(Dind::class);
        $project->shouldReceive('system')->andReturn($system);

        return new ComposeWriter($project);
    }
}
