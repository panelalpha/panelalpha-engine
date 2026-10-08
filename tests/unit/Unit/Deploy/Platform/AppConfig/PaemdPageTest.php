<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\PaemdPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaemdPageTest extends TestCase
{
    /**
     * Shaped like legacy panelalpha.md pages (formerly under paemd-pages/): a
     * bulleted, backticked filename followed by a fenced block.
     */
    private function page(): string
    {
        return <<<'MD'
        # Example app

        Prose that mentions `panelalpha-after-clone.sh` inline without a block.

        ## Snippets

        - `panelalpha-before-clone-validation.sh`
        ```bash
        set -e
        df -Pk . | awk 'NR==2 {exit ($4 < 2000000)}'
        ```

        - `panelalpha-after-clone.sh`
        ```bash
        set -e
        echo "APP_SECRET=$(openssl rand -hex 16)" > .env
        ```

        - `docker-compose.yml`
        ```yaml
        services:
          app:
            image: example:latest
        ```

        - `wp-content/mu-plugins/panelalpha-app.php`
        ```php
        <?php
        echo 'hello';
        ```

        - `./docker-compose.override.yml`
        ```yaml
        services:
          app:
            restart: unless-stopped
        ```

        - `panelalpha-app.sh`
        ```bash
        exec docker compose exec -T app php app.php "$@"
        ```
        MD;
    }

    public function test_extracts_each_special_script(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('df -Pk', PaemdPage::preCheckCommands($page));
        $this->assertStringStartsWith('set -e', PaemdPage::setupCommands($page));
        $this->assertStringContainsString('openssl rand', PaemdPage::setupCommands($page));
        $this->assertStringContainsString('docker compose exec', PaemdPage::appScript($page));
        $this->assertStringContainsString('image: example:latest', PaemdPage::compose($page));
    }

    public function test_prose_mention_without_a_fence_is_not_treated_as_a_block(): void
    {
        // The page mentions panelalpha-after-clone.sh in a sentence before the
        // real block; only the fenced one may match.
        $this->assertStringContainsString('openssl rand', PaemdPage::setupCommands($this->page()));
    }

    public function test_absent_blocks_return_null(): void
    {
        $page = "# Nothing here\n\nJust prose.\n";

        $this->assertNull(PaemdPage::setupCommands($page));
        $this->assertNull(PaemdPage::preCheckCommands($page));
        $this->assertNull(PaemdPage::appScript($page));
        $this->assertNull(PaemdPage::compose($page));
    }

    public function test_unterminated_fence_is_not_extracted(): void
    {
        $page = "- `panelalpha-app.sh`\n```bash\necho hi\n";

        $this->assertNull(PaemdPage::appScript($page));
    }

    public function test_file_snippets_exclude_special_names(): void
    {
        $paths = array_column(PaemdPage::fileSnippets($this->page()), 'path');

        $this->assertContains('wp-content/mu-plugins/panelalpha-app.php', $paths);
        $this->assertContains('docker-compose.override.yml', $paths, 'leading ./ is stripped');
        $this->assertNotContains('panelalpha-app.sh', $paths);
        $this->assertNotContains('panelalpha-after-clone.sh', $paths);
        $this->assertNotContains('panelalpha-before-clone-validation.sh', $paths);
        $this->assertNotContains('docker-compose.yml', $paths, 'compose is handled by compose()');
    }

    public function test_file_snippet_contents_are_trimmed(): void
    {
        $snippets = PaemdPage::fileSnippets($this->page());
        $byPath = array_column($snippets, 'contents', 'path');

        $this->assertSame("<?php\necho 'hello';", $byPath['wp-content/mu-plugins/panelalpha-app.php']);
    }

    public function test_file_snippets_reject_absolute_and_traversing_paths(): void
    {
        $page = <<<'MD'
        - `/etc/passwd`
        ```text
        root:x:0:0
        ```

        - `../../escape.txt`
        ```text
        nope
        ```

        - `safe/ok.txt`
        ```text
        yes
        ```
        MD;

        $paths = array_column(PaemdPage::fileSnippets($page), 'path');

        $this->assertSame(['safe/ok.txt'], $paths);
    }

    public function test_file_snippets_on_a_page_without_blocks(): void
    {
        $this->assertSame([], PaemdPage::fileSnippets("# Title\n\nProse only.\n"));
    }

    #[DataProvider('repoUrlProvider')]
    public function test_parse_repo_url(string $url, ?array $expected): void
    {
        $this->assertSame($expected, PaemdPage::parseRepoUrl($url));
    }

    public static function repoUrlProvider(): array
    {
        return [
            'https' => [
                'https://github.com/n8n-io/n8n',
                ['host' => 'github.com', 'owner' => 'n8n-io', 'repo' => 'n8n'],
            ],
            'https with .git and trailing slash' => [
                'https://github.com/n8n-io/n8n.git/',
                ['host' => 'github.com', 'owner' => 'n8n-io', 'repo' => 'n8n'],
            ],
            'scp style' => [
                'git@github.com:n8n-io/n8n.git',
                ['host' => 'github.com', 'owner' => 'n8n-io', 'repo' => 'n8n'],
            ],
            'self-hosted with port and subgroup' => [
                // A GitLab subgroup is part of the namespace, not the repository name.
                'https://git.example.com:8443/group/sub/repo',
                ['host' => 'git.example.com', 'owner' => 'group/sub', 'repo' => 'repo'],
            ],
            'owner only' => ['https://github.com/n8n-io', null],
            'not a url' => ['nonsense', null],
        ];
    }

    public function test_local_page_path_is_lowercased(): void
    {
        $this->assertSame(
            '/opt/panelalpha/shared-hosting/paemd-pages/github.com/n8n-io/n8n/panelalpha.md',
            PaemdPage::localPagePath('https://GitHub.com/N8N-io/N8N.git'),
        );
    }

    public function test_local_page_path_is_null_for_unparseable_url(): void
    {
        $this->assertNull(PaemdPage::localPagePath('nonsense'));
    }
}
