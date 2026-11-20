<?php

namespace Tests\Integration\Project\Create;

use App\Exceptions\ProblemException;
use App\Lib\Project\Create\CreateBody;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every malformed piece of a typed create or attach body answers with the field as sent and its own code.
 */
class CreateBodyRefusalsTest extends TestCase
{
    private const HTTPS = 'https://github.com/owner/repo';

    private const SSH = 'git@github.com:owner/repo.git';

    /**
     * @param  array<string, mixed>  $body
     * @param  list<array{string, string}>  $expected
     */
    #[DataProvider('createBodies')]
    public function test_a_malformed_create_body_names_each_problem(array $body, array $expected): void
    {
        $this->assertSame($expected, $this->fieldsAndCodes(CreateBody::forCreate($body)));
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{string, string}>}> */
    public static function createBodies(): iterable
    {
        yield 'groups that are not objects' => [
            ['name' => 'shop', 'domain' => ['shop.example.com'], 'limits' => '1G'],
            [['domain', 'domain_must_be_object'], ['limits', 'limits_must_be_object']],
        ];
        yield 'a source that is not an object' => [
            ['name' => 'shop', 'source' => 'git'],
            [['source', 'source_must_be_object']],
        ];
        yield 'a source without a type' => [
            ['name' => 'shop', 'source' => ['url' => self::HTTPS]],
            [['source.type', 'source_type_required']],
        ];
        yield 'a git source without a url' => [
            ['name' => 'shop', 'source' => ['type' => 'git']],
            [['source.url', 'source_url_required']],
        ];
        yield 'a git source with an empty url' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => ' ']],
            [['source.url', 'source_url_invalid']],
        ];
        yield 'a branch that is not a string' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::HTTPS, 'branch' => 5]],
            [['source.branch', 'source_branch_invalid']],
        ];
        yield 'auth that is not an object' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::SSH, 'auth' => 'token']],
            [['source.auth', 'source_auth_must_be_object']],
        ];
        yield 'auth without a type' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::HTTPS, 'auth' => ['token' => 'vault:abc']]],
            [['source.auth.type', 'source_auth_type_required']],
        ];
        yield 'auth of an unknown type' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::HTTPS, 'auth' => ['type' => 'password']]],
            [['source.auth.type', 'source_auth_type_invalid']],
        ];
        yield 'a token sent with auth none' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::HTTPS, 'auth' => ['type' => 'none', 'token' => 'abc']]],
            [['source.auth.token', 'field_not_allowed']],
        ];
        yield 'a token sent with a deploy key' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::SSH, 'auth' => ['type' => 'deploy_key', 'key' => 'vault:abc', 'token' => 'abc']]],
            [['source.auth.token', 'field_not_allowed']],
        ];
        yield 'a key sent with a token' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::HTTPS, 'auth' => ['type' => 'token', 'token' => 'abc', 'key' => 'vault:abc']]],
            [['source.auth.key', 'field_not_allowed']],
        ];
        yield 'token auth without the token' => [
            ['name' => 'shop', 'source' => ['type' => 'git', 'url' => self::HTTPS, 'auth' => ['type' => 'token']]],
            [['source.auth.token', 'source_auth_token_required']],
        ];
        yield 'an archive url that is empty' => [
            ['name' => 'shop', 'source' => ['type' => 'archive', 'url' => '']],
            [['source.url', 'source_url_invalid']],
        ];
        yield 'an archive url that is not http' => [
            ['name' => 'shop', 'source' => ['type' => 'archive', 'url' => 'ftp://example.com/site.zip']],
            [['source.url', 'source_url_invalid']],
        ];
        yield 'copy_databases that is not a boolean' => [
            ['name' => 'shop', 'source' => ['type' => 'project', 'from' => 'blog', 'copy_databases' => 'yes']],
            [['source.copy_databases', 'source_copy_databases_invalid']],
        ];
        yield 'a wordpress source without an admin' => [
            ['name' => 'shop', 'source' => ['type' => 'wordpress', 'title' => 'Blog']],
            [['source.admin', 'source_admin_required']],
        ];
        yield 'a wordpress admin that is not an object' => [
            ['name' => 'shop', 'source' => ['type' => 'wordpress', 'title' => 'Blog', 'admin' => 'boss']],
            [['source.admin', 'source_admin_must_be_object']],
        ];
    }

    public function test_an_attach_needs_a_source(): void
    {
        $this->assertSame([['source', 'source_required']], $this->fieldsAndCodes(CreateBody::forAttach([])));
    }

    public function test_a_group_refusal_gives_an_example_only_where_one_helps(): void
    {
        $messages = array_column($this->problems(CreateBody::forCreate(['domain' => 'shop.example.com', 'limits' => 'big'])), 'message', 'field');

        $this->assertSame('domain must be an object, for example {"name": "shop.example.com"}.', $messages['domain']);
        $this->assertSame('limits must be an object.', $messages['limits']);
    }

    public function test_an_empty_string_and_a_missing_value_are_told_apart(): void
    {
        $missing = $this->problems(CreateBody::forCreate(['source' => ['type' => 'git']]));
        $empty = $this->problems(CreateBody::forCreate(['source' => ['type' => 'git', 'url' => '']]));

        $this->assertSame('source.url is required.', $missing[0]['message']);
        $this->assertSame('source.url must be a non-empty string.', $empty[0]['message']);
    }

    /** @return list<array{string, string}> */
    private function fieldsAndCodes(CreateBody $body): array
    {
        return array_map(static fn (array $problem): array => [$problem['field'], $problem['code']], $this->problems($body));
    }

    /** @return list<array<string, mixed>> */
    private function problems(CreateBody $body): array
    {
        try {
            $body->assertWellFormed();
        } catch (ProblemException $e) {
            return $e->problems;
        }

        return [];
    }
}
