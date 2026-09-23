<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitTokenInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitTokenInputTest extends TestCase
{
    /** A real credential, and the forms a self-hosted forge issues. */
    #[DataProvider('acceptedProvider')]
    public function test_accepts_what_could_be_a_token(string $token): void
    {
        $this->assertNull(GitTokenInput::problem('git_token', $token));
    }

    public static function acceptedProvider(): array
    {
        return [
            'github classic' => ['ghp_' . str_repeat('a', 36)],
            'github fine-grained' => ['github_pat_' . str_repeat('b', 71)],
            'gitlab' => ['glpat-' . str_repeat('c', 20)],
            // Bitbucket, Gitea, self-hosted: no prefix to guess at.
            'unprefixed' => ['abc123def456'],
            'gitea 40 hex' => [str_repeat('0123456789abcdef', 2) . str_repeat('a', 8)],
            'surrounding whitespace is trimmed, not rejected' => ["  ghp_" . str_repeat('a', 36) . "\n"],
            'vault reference' => ['vault:9f2c1d7b'],
            // Longer than the known length is a newer format, not a mistake.
            'a longer future github token' => ['ghp_' . str_repeat('a', 60)],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejects_what_cannot_be_a_token(string $token, string $code): void
    {
        $problem = GitTokenInput::problem('git_token', $token);

        $this->assertNotNull($problem);
        $this->assertSame($code, $problem['code']);
        $this->assertSame('git_token', $problem['field']);
    }

    public static function rejectedProvider(): array
    {
        return [
            'blank' => ['   ', 'git_token_blank'],
            'newline inside' => ["ghp_aaa\nbbb", 'git_token_malformed'],
            'space inside' => ['ghp_aaa bbb', 'git_token_malformed'],
            'bearer header' => ['Bearer ghp_' . str_repeat('a', 36), 'git_token_has_auth_prefix'],
            'token header' => ['token ghp_' . str_repeat('a', 36), 'git_token_has_auth_prefix'],
            'a url' => ['https://github.com/o/r.git', 'git_token_looks_like_url'],
            'double quoted' => ['"ghp_' . str_repeat('a', 36) . '"', 'git_token_quoted'],
            'single quoted' => ["'ghp_" . str_repeat('a', 36) . "'", 'git_token_quoted'],
            'truncated github' => ['ghp_aaaaaaaa', 'git_token_truncated'],
            'truncated gitlab' => ['glpat-aaaa', 'git_token_truncated'],
            'bare vault prefix' => ['vault:', 'git_token_empty_vault_reference'],
            'too long' => [str_repeat('a', GitTokenInput::MAX_LENGTH + 1), 'git_token_too_long'],
        ];
    }

    /**
     * The rule this file exists to enforce. GitRepoInput echoes a URL back
     * because handing over the corrected string is the point; doing that with
     * a credential would put it everywhere the response goes.
     */
    #[DataProvider('everyRejectionProvider')]
    public function test_no_message_ever_contains_the_token(string $token): void
    {
        $problem = GitTokenInput::problem('git_token', $token);

        $this->assertNotNull($problem);
        $this->assertArrayNotHasKey('suggestion', $problem, 'a token must never be echoed back');

        $secret = trim($token);
        if (strlen($secret) >= 8) {
            $this->assertStringNotContainsString(
                $secret,
                $problem['message'],
                'the message quoted the credential'
            );
        }
    }

    public static function everyRejectionProvider(): array
    {
        $cases = [];
        foreach (self::rejectedProvider() as $name => [$token, $_code]) {
            $cases[$name] = [$token];
        }

        return $cases;
    }

    // ---- redact() -------------------------------------------------------

    public function test_redact_keeps_enough_to_tell_two_failures_apart(): void
    {
        $this->assertSame('ghp_••••••••', GitTokenInput::redact('ghp_' . str_repeat('a', 36)));
        $this->assertSame('vault:…', GitTokenInput::redact('vault:9f2c1d7b'));
        $this->assertSame('(blank)', GitTokenInput::redact('   '));
    }

    public function test_redact_reveals_nothing_of_a_short_value(): void
    {
        $this->assertSame('••••••', GitTokenInput::redact('abc123'));
    }

    public function test_redact_never_returns_the_value(): void
    {
        $token = 'ghp_' . str_repeat('z', 36);

        $this->assertStringNotContainsString($token, GitTokenInput::redact($token));
        $this->assertLessThan(strlen($token), strlen(GitTokenInput::redact($token)));
    }
}
