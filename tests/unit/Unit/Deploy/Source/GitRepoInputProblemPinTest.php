<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRepoInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every answer GitRepoInput::problem() gives, whole: the refusal order, each code,
 * message and suggestion, and which keys a refusal carries.
 */
class GitRepoInputProblemPinTest extends TestCase
{
    /**
     * @param ?array<string, mixed> $expected
     */
    #[DataProvider('answers')]
    public function test_the_answer_is_pinned(string $raw, bool $forToken, ?array $expected): void
    {
        $this->assertSame($expected, GitRepoInput::problem('git_repo', $raw, $forToken));
    }

    public static function answers(): array
    {
        return [
            'blank' => ['   ', false, null],
            'ssh scp' => ['git@github.com:owner/repo.git', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_ssh_unsupported',
                'message' => 'An SSH remote clones only with a project\'s deploy key, and a project has none until it exists. Create the project without a repository, create its deploy key, add the public key to the repository as a deploy key, then connect the SSH remote. Or use https://github.com/owner/repo.git, with a token if it is private.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'suggestion' => 'https://github.com/owner/repo.git',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'shorthand' => ['owner/repo', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_shorthand',
                'message' => '\'owner/repo\' is owner/repo shorthand, not a repository URL, and the host is not guessed. If it is on GitHub, send https://github.com/owner/repo.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'suggestion' => 'https://github.com/owner/repo',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'scheme only' => ['http://', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_malformed',
                'message' => '\'http://\' could not be read as a repository URL.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'triple slash' => ['https:///owner/repo', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_malformed',
                'message' => '\'https:///owner/repo\' could not be read as a repository URL.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'port only' => ['https://:8080/owner/repo', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_malformed',
                'message' => '\'https://:8080/owner/repo\' could not be read as a repository URL.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'zero host' => ['https://0/o/r', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_no_host',
                'message' => '\'https://0/o/r\' names no host.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'ftp scheme' => ['ftp://github.com/o/r.git', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_unsupported_scheme',
                'message' => 'The engine clones over HTTPS; \'ftp\' is not a scheme it can use.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'credentials' => ['https://user:secret@github.com/o/r.git', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_embedded_credentials',
                'message' => 'A repository URL must not embed credentials. Send the token separately instead: the engine injects it at clone time and never writes it to .git/config.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'suggestion' => 'https://github.com/o/r.git',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'user only' => ['https://user@gitlab.com/o/r.git', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_embedded_credentials',
                'message' => 'A repository URL must not embed credentials. Send the token separately instead: the engine injects it at clone time and never writes it to .git/config.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'suggestion' => 'https://gitlab.com/o/r.git',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'host only' => ['https://github.com', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_incomplete',
                'message' => '\'https://github.com\' names a host but no repository.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'forge account' => ['https://github.com/owner', false, [
                'field' => 'git_repo',
                'code' => 'git_repo_incomplete',
                'message' => '\'https://github.com/owner\' names an account on github.com, not a repository. Add the repository name.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'self hosted single' => ['https://git.example.test/repo.git', false, null],
            'http with token' => ['http://git.example.test/o/r.git', true, [
                'field' => 'git_repo',
                'code' => 'git_repo_token_requires_https',
                'message' => 'A Git token requires an HTTPS repository URL.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'suggestion' => 'https://git.example.test/o/r.git',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'https with token' => ['https://github.com/o/r.git', true, null],
            'typo and token' => ['http://github.com/owner', true, [
                'field' => 'git_repo',
                'code' => 'git_repo_incomplete',
                'message' => '\'http://github.com/owner\' names an account on github.com, not a repository. Add the repository name.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
            'ftp with token' => ['ftp://github.com/o/r.git', true, [
                'field' => 'git_repo',
                'code' => 'git_repo_unsupported_scheme',
                'message' => 'The engine clones over HTTPS; \'ftp\' is not a scheme it can use.',
                'expected' => 'https://<host>/<owner>/<repo>[.git]',
                'examples' => [
                    'https://github.com/owner/repo.git',
                    'github.com/owner/repo',
                ],
            ]],
        ];
    }
}
