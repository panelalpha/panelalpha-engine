<?php

namespace Tests\Unit\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;
use App\Lib\DeployHook\Registration\ApiRegistrar;
use App\Lib\DeployHook\Registration\BitbucketCloudRegistrar;
use App\Lib\DeployHook\Registration\GithubRegistrar;
use App\Lib\DeployHook\Registration\GitlabRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which repository URLs each registrar can address, and what it addresses them as, without
 * a self-managed probe; and how each reads the id of the hook it just created.
 */
class RegistrarAddressTest extends TestCase
{
    /** @return array<string, array{class-string<ApiRegistrar>, string, ?array{string, string}}> */
    public static function addresses(): array
    {
        $github = GithubRegistrar::class;
        $gitlab = GitlabRegistrar::class;
        $bitbucket = BitbucketCloudRegistrar::class;

        return [
            'github' => [$github, 'https://github.com/owner/repo', ['https://api.github.com', 'owner/repo']],
            'github .git' => [$github, 'https://github.com/owner/repo.git', ['https://api.github.com', 'owner/repo']],
            'github trailing slash' => [$github, 'https://github.com/owner/repo/', ['https://api.github.com', 'owner/repo']],
            'github padded and cased' => [$github, "  HTTPS://GitHub.com/Owner/Repo.git \n", ['https://api.github.com', 'Owner/Repo']],
            'github double .git' => [$github, 'https://github.com/o/r.git.git', ['https://api.github.com', 'o/r.git']],
            'github enterprise' => [$github, 'https://ghe.example.test/o/r', ['https://ghe.example.test/api/v3', 'o/r']],
            'github enterprise port' => [$github, 'https://GHE.example.test:8443/o/r.git', ['https://ghe.example.test:8443/api/v3', 'o/r']],
            'github http' => [$github, 'http://github.com/o/r', null],
            'github scp' => [$github, 'git@github.com:o/r.git', null],
            'github user' => [$github, 'https://user@github.com/o/r', null],
            'github user and password' => [$github, 'https://user:pw@github.com/o/r', null],
            'github query' => [$github, 'https://github.com/o/r?tab=readme', null],
            'github fragment' => [$github, 'https://github.com/o/r#top', null],
            'github one segment' => [$github, 'https://github.com/o', null],
            'github three segments' => [$github, 'https://github.com/o/r/tree', null],
            'github no host' => [$github, 'https:///o/r', null],
            'github encoded' => [$github, 'https://github.com/o/r%20x', null],
            'github empty' => [$github, '', null],
            'gitlab' => [$gitlab, 'https://gitlab.com/group/project', ['https://gitlab.com/api/v4', 'group/project']],
            'gitlab subgroup .git' => [$gitlab, 'https://gitlab.com/group/sub/project.git', ['https://gitlab.com/api/v4', 'group/sub/project']],
            'gitlab trailing slash' => [$gitlab, 'https://gitlab.com/group/project/', ['https://gitlab.com/api/v4', 'group/project']],
            'gitlab .git then slash' => [$gitlab, 'https://gitlab.com/group/project.git/', ['https://gitlab.com/api/v4', 'group/project.git']],
            'gitlab padded and cased' => [$gitlab, ' HTTPS://GitLab.COM/A/B ', ['https://gitlab.com/api/v4', 'A/B']],
            'gitlab self-managed' => [$gitlab, 'https://git.example.test/group/project', ['https://git.example.test/api/v4', 'group/project']],
            'gitlab self-managed port' => [$gitlab, 'https://git.example.test:8443/group/project.git', ['https://git.example.test:8443/api/v4', 'group/project']],
            'gitlab one segment' => [$gitlab, 'https://gitlab.com/project', null],
            'gitlab double slash' => [$gitlab, 'https://gitlab.com/a//b', null],
            'gitlab space' => [$gitlab, 'https://gitlab.com/a b/c', null],
            'gitlab http' => [$gitlab, 'http://gitlab.com/a/b', null],
            'gitlab user' => [$gitlab, 'https://oauth2:token@gitlab.com/a/b', null],
            'gitlab query' => [$gitlab, 'https://gitlab.com/a/b?x=1', null],
            'gitlab fragment' => [$gitlab, 'https://gitlab.com/a/b#x', null],
            'gitlab no host' => [$gitlab, 'https:///a/b', null],
            'gitlab empty' => [$gitlab, '', null],
            'bitbucket' => [$bitbucket, 'https://bitbucket.org/ws/slug', ['https://api.bitbucket.org/2.0', 'ws/slug']],
            'bitbucket .git' => [$bitbucket, 'https://bitbucket.org/ws/slug.git', ['https://api.bitbucket.org/2.0', 'ws/slug']],
            'bitbucket padded and cased' => [$bitbucket, ' https://BitBucket.org/Ws/Slug/ ', ['https://api.bitbucket.org/2.0', 'Ws/Slug']],
            'bitbucket other host' => [$bitbucket, 'https://bitbucket.example.test/ws/slug', null],
            'bitbucket user' => [$bitbucket, 'https://user@bitbucket.org/ws/slug.git', null],
            'bitbucket query' => [$bitbucket, 'https://bitbucket.org/ws/slug?at=main', null],
            'bitbucket fragment' => [$bitbucket, 'https://bitbucket.org/ws/slug#readme', null],
            'bitbucket http' => [$bitbucket, 'http://bitbucket.org/ws/slug', null],
            'bitbucket one segment' => [$bitbucket, 'https://bitbucket.org/ws', null],
            'bitbucket three segments' => [$bitbucket, 'https://bitbucket.org/ws/slug/src', null],
            'bitbucket empty' => [$bitbucket, '', null],
        ];
    }

    /**
     * @param  class-string<ApiRegistrar>  $class
     * @param  ?array{string, string}  $expected
     */
    #[DataProvider('addresses')]
    public function test_a_repository_url_is_addressed_as_before(string $class, string $url, ?array $expected): void
    {
        $target = (new $class())->address($url);

        $this->assertSame($expected, $target === null ? null : [$target->apiBase, $target->repository]);
    }

    /** @return array<string, array{class-string<ApiRegistrar>, array<string, mixed>, ?string}> */
    public static function createdBodies(): array
    {
        return [
            'github int id' => [GithubRegistrar::class, ['id' => 5], '5'],
            'github string id' => [GithubRegistrar::class, ['id' => 'abc', 'uuid' => 'x'], 'abc'],
            'github empty string id' => [GithubRegistrar::class, ['id' => ''], ''],
            'github float id' => [GithubRegistrar::class, ['id' => 1.5], null],
            'github null id' => [GithubRegistrar::class, ['id' => null], null],
            'github no id' => [GithubRegistrar::class, ['uuid' => '{u}'], null],
            'gitlab int id' => [GitlabRegistrar::class, ['id' => 77], '77'],
            'gitlab string id' => [GitlabRegistrar::class, ['id' => '0'], '0'],
            'gitlab bool id' => [GitlabRegistrar::class, ['id' => true], null],
            'gitlab no id' => [GitlabRegistrar::class, [], null],
            'bitbucket uuid' => [BitbucketCloudRegistrar::class, ['uuid' => '{1234}', 'id' => 5], '{1234}'],
            'bitbucket empty uuid' => [BitbucketCloudRegistrar::class, ['uuid' => ''], null],
            'bitbucket int uuid' => [BitbucketCloudRegistrar::class, ['uuid' => 5], null],
            'bitbucket only an id' => [BitbucketCloudRegistrar::class, ['id' => 5], null],
        ];
    }

    /**
     * @param  class-string<ApiRegistrar>  $class
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('createdBodies')]
    public function test_the_created_hook_id_is_read_as_before(string $class, array $body, ?string $expected): void
    {
        $registrar = new $class();
        $hookId = new \ReflectionMethod($registrar, 'hookId');

        try {
            $this->assertSame($expected, $hookId->invoke($registrar, $body));
        } catch (ProviderRegistrationRefused $e) {
            $this->assertNull($expected, $e->getMessage());
            $field = $class === BitbucketCloudRegistrar::class ? 'uuid' : 'id';
            $this->assertSame(
                "The webhook registration at {$registrar->name()} could not be completed: the created webhook had no {$field}.",
                $e->getMessage()
            );
            $this->assertSame(502, $e->httpStatus);
        }
    }
}
