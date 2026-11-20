<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Deploy\Source\RepositoryAddress;
use App\Lib\Deploy\Source\RepoUrl;
use App\Lib\Deploy\Source\UploadedArchive;
use PHPUnit\Framework\TestCase;

/** Repository addresses, tokens and member names at the edges of what the input checks accept. */
class SourceInputEdgesTest extends TestCase
{
    public function test_an_ssh_url_with_an_empty_authority_names_no_host(): void
    {
        $this->assertNull(GitRepoInput::sshHost('ssh:///owner/repo.git'));
    }

    public function test_a_deploy_key_cannot_reach_an_ssh_url_that_names_no_host(): void
    {
        $asked = false;
        $problem = GitRepoInput::sshProblemWithKey('git_repo', 'ssh:///owner/repo.git', static function () use (&$asked): bool {
            $asked = true;

            return true;
        });

        $this->assertSame('git_repo_malformed', $problem['code'] ?? null);
        $this->assertSame("'ssh:///owner/repo.git' names no host.", $problem['message'] ?? null);
        $this->assertFalse($asked);
    }

    public function test_a_forge_remote_without_an_owner_has_no_https_spelling(): void
    {
        $this->assertNull(GitRepoInput::httpsEquivalent('git@github.com:repo.git'));
        $this->assertSame('https://github.com/acme/repo.git', GitRepoInput::httpsEquivalent('git@github.com:acme/repo.git'));
    }

    public function test_a_one_character_token_is_not_read_as_quoted(): void
    {
        $this->assertNull(GitTokenInput::problem('git_token', '"'));
        $this->assertSame('git_token_quoted', GitTokenInput::problem('git_token', '"abc"')['code'] ?? null);
    }

    public function test_a_blank_repository_address_has_no_host(): void
    {
        $this->assertNull(RepositoryAddress::host('   '));
    }

    public function test_a_path_segment_holding_a_backslash_is_no_directory_name(): void
    {
        $this->assertNull(RepoUrl::segments('https://github.com/acme\\evil/repo'));
        $this->assertSame(['github.com', 'acme', 'repo'], RepoUrl::segments('https://github.com/Acme/Repo'));
    }

    public function test_a_zip_member_named_with_a_wildcard_is_never_read(): void
    {
        $archive = UploadedArchive::confined('/home/shop/site.zip', true);

        foreach (['site/*', 'a?b', 'x[1]', 'back\\slash'] as $name) {
            try {
                $archive->readMemberArgv('/home/shop/.staged.zip', $name);
                $this->fail('Expected a refusal for '.$name);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('symbolic link', $e->getMessage());
            }
        }
    }

    public function test_a_ref_differing_only_in_case_is_suggested_at_once(): void
    {
        $this->assertSame('Main', GitRemoteProbe::closestRef('refs/heads/main', ['develop', 'Main'], ['v1']));
    }
}
