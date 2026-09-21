<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRepoInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The matrix three endpoints used to disagree about. One table, asserted
 * once, is what keeps them aligned.
 */
class GitRepoInputTest extends TestCase
{
    #[DataProvider('acceptedProvider')]
    public function test_accepts_a_cloneable_repository(string $input): void
    {
        $this->assertNull(
            GitRepoInput::problem('git_repo', $input),
            "{$input} should be cloneable"
        );
    }

    public static function acceptedProvider(): array
    {
        return [
            'https' => ['https://github.com/vvolv/market-radar.git'],
            'https without .git' => ['https://github.com/vvolv/market-radar'],
            'https with trailing slash' => ['https://github.com/vvolv/market-radar/'],
            'schemeless' => ['github.com/vvolv/market-radar'],
            'gitlab subgroup' => ['https://gitlab.com/group/subgroup/repo.git'],
            'plain http' => ['http://git.internal/o/r.git'],
            // What demanding owner/repo everywhere would cost.
            'self-hosted, no owner' => ['https://git.internal/nope.git'],
            'self-hosted, deep path' => ['https://git.internal/scm/team/nope.git'],
            'surrounding whitespace' => ['  https://github.com/vvolv/market-radar.git  '],
            'empty is the field being absent' => [''],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejects_what_the_engine_cannot_clone(string $input, string $code): void
    {
        $problem = GitRepoInput::problem('git_repo', $input);

        $this->assertNotNull($problem, "{$input} should be rejected");
        $this->assertSame($code, $problem['code']);
        $this->assertSame('git_repo', $problem['field']);
        $this->assertNotSame('', $problem['expected']);
    }

    public static function rejectedProvider(): array
    {
        return [
            'scp-style SSH' => ['git@github.com:vvolv/market-radar.git', 'git_repo_ssh_unsupported'],
            'ssh:// scheme' => ['ssh://git@github.com/vvolv/market-radar.git', 'git_repo_ssh_unsupported'],
            'ftp' => ['ftp://github.com/o/r.git', 'git_repo_unsupported_scheme'],
            'file' => ['file:///srv/repo.git', 'git_repo_unsupported_scheme'],
            'bare host' => ['https://github.com', 'git_repo_incomplete'],
            'bare self-hosted host' => ['https://git.internal/', 'git_repo_incomplete'],
            'a forge account is not a repository' => ['https://github.com/vvolv', 'git_repo_incomplete'],
            'embedded credentials' => ['https://user:pw@github.com/o/r.git', 'git_repo_embedded_credentials'],
        ];
    }

    /** The 422 hands back the value the caller meant, rather than describing it. */
    #[DataProvider('suggestionProvider')]
    public function test_suggests_the_value_the_caller_meant(string $input, string $suggestion): void
    {
        $problem = GitRepoInput::problem('git_repo', $input);

        $this->assertNotNull($problem);
        $this->assertSame($suggestion, $problem['suggestion'] ?? null);
    }

    public static function suggestionProvider(): array
    {
        return [
            'scp-style' => [
                'git@github.com:vvolv/market-radar.git',
                'https://github.com/vvolv/market-radar.git',
            ],
            'scp-style without .git' => [
                'git@github.com:vvolv/market-radar',
                'https://github.com/vvolv/market-radar.git',
            ],
            'ssh://' => [
                'ssh://git@gitlab.com/acme/widget.git',
                'https://gitlab.com/acme/widget.git',
            ],
            'credentials are stripped, not kept' => [
                'https://user:pw@github.com/o/r.git',
                'https://github.com/o/r.git',
            ],
        ];
    }

    /** A token needs HTTPS; plain HTTP is fine without one. */
    public function test_http_is_refused_only_when_a_token_comes_with_it(): void
    {
        $url = 'http://git.internal/o/r.git';

        $this->assertNull(GitRepoInput::problem('git_repo', $url));

        $problem = GitRepoInput::problem('git_repo', $url, true);
        $this->assertNotNull($problem);
        $this->assertSame('git_repo_token_requires_https', $problem['code']);
        $this->assertSame('https://git.internal/o/r.git', $problem['suggestion']);
    }

    public function test_rejects_an_overlong_value(): void
    {
        $problem = GitRepoInput::problem(
            'git_repo',
            'https://github.com/o/' . str_repeat('r', GitRepoInput::MAX_LENGTH)
        );

        $this->assertNotNull($problem);
        $this->assertSame('git_repo_too_long', $problem['code']);
    }

    /** The field name travels, so one rule can serve three endpoints. */
    public function test_the_code_is_namespaced_by_the_field(): void
    {
        $problem = GitRepoInput::problem('source', 'git@github.com:o/r.git');

        $this->assertNotNull($problem);
        $this->assertSame('source', $problem['field']);
        $this->assertSame('source_ssh_unsupported', $problem['code']);
    }

    // ---- normalise() ----------------------------------------------------

    #[DataProvider('normaliseProvider')]
    public function test_normalise_fills_in_only_a_missing_scheme(string $input, string $expected): void
    {
        $this->assertSame($expected, GitRepoInput::normalise($input));
    }

    public static function normaliseProvider(): array
    {
        return [
            'schemeless gains https' => ['github.com/o/r', 'https://github.com/o/r'],
            'leading slashes are dropped' => ['//github.com/o/r', 'https://github.com/o/r'],
            'https is left alone' => ['https://github.com/o/r', 'https://github.com/o/r'],
            'http is left alone' => ['http://github.com/o/r', 'http://github.com/o/r'],
            // Suggest, never substitute -- an SSH-only host cannot serve HTTPS.
            'scp-style is left alone' => ['git@github.com:o/r.git', 'git@github.com:o/r.git'],
            'ssh:// is left alone' => ['ssh://git@github.com/o/r', 'ssh://git@github.com/o/r'],
            'empty stays empty' => ['', ''],
        ];
    }

    // ---- expandShorthand() ----------------------------------------------

    #[DataProvider('shorthandProvider')]
    public function test_expand_shorthand_only_touches_owner_repo(string $input, string $expected): void
    {
        $this->assertSame($expected, GitRepoInput::expandShorthand($input));
    }

    public static function shorthandProvider(): array
    {
        return [
            'owner/repo is GitHub' => ['n8n-io/n8n', 'https://github.com/n8n-io/n8n'],
            'a dotted repo name still expands' => ['calcom/cal.com', 'https://github.com/calcom/cal.com'],
            // A host carries a dot; normalise() is what fills in its scheme.
            'a host is left for normalise' => ['gitea.com/repo', 'gitea.com/repo'],
            'schemeless forge path is left alone' => ['github.com/o/r', 'github.com/o/r'],
            'a URL is left alone' => ['https://github.com/o/r', 'https://github.com/o/r'],
            'scp-style is left alone' => ['git@github.com:o/r.git', 'git@github.com:o/r.git'],
            'a bare word is not a repository' => ['n8n', 'n8n'],
            'empty stays empty' => ['', ''],
        ];
    }

    #[DataProvider('shorthandHostProvider')]
    public function test_the_shorthand_forge_is_configurable(?string $host, string $expected): void
    {
        $this->assertSame($expected, GitRepoInput::expandShorthand('o/r', $host));
    }

    public static function shorthandHostProvider(): array
    {
        return [
            'unset means GitHub' => [null, 'https://github.com/o/r'],
            'empty means GitHub' => ['', 'https://github.com/o/r'],
            'blank means GitHub' => ['   ', 'https://github.com/o/r'],
            'a host' => ['gitlab.com', 'https://gitlab.com/o/r'],
            // Set through `settings:set`, so it arrives however it was typed.
            'a host with a scheme' => ['https://gitlab.com', 'https://gitlab.com/o/r'],
            'a host with a trailing slash' => ['gitlab.com/', 'https://gitlab.com/o/r'],
            'a self-hosted forge' => ['git.internal', 'https://git.internal/o/r'],
            'a group prefix is kept' => ['gitlab.com/team', 'https://gitlab.com/team/o/r'],
        ];
    }

    public function test_a_full_url_never_consults_the_configured_forge(): void
    {
        $this->assertSame(
            'https://github.com/o/r',
            GitRepoInput::expandShorthand('https://github.com/o/r', 'gitlab.com')
        );
        $this->assertSame(
            'github.com/o/r',
            GitRepoInput::expandShorthand('github.com/o/r', 'gitlab.com')
        );
    }

    #[DataProvider('sshProvider')]
    public function test_recognises_both_spellings_of_ssh(string $input, bool $isSsh): void
    {
        $this->assertSame($isSsh, GitRepoInput::isSsh($input));
    }

    public static function sshProvider(): array
    {
        return [
            'scp-style' => ['git@github.com:o/r.git', true],
            'scp-style, other user' => ['deploy@git.internal:o/r.git', true],
            'ssh scheme' => ['ssh://git@github.com/o/r', true],
            'https' => ['https://github.com/o/r', false],
            'schemeless' => ['github.com/o/r', false],
            // An email-looking value is not a remote, but it is not SSH either.
            'https with credentials' => ['https://user:pw@github.com/o/r', false],
        ];
    }
}
