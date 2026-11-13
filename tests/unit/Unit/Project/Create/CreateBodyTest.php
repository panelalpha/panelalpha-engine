<?php

namespace Tests\Unit\Project\Create;

use App\Exceptions\ProblemException;
use App\Lib\Project\Create\CreateBody;
use App\Lib\Project\Create\CreateShape;
use Tests\TestCase;

/**
 * The typed create body checked on its own: every refusal names the field as sent and a code, with no
 * database and no request.
 */
class CreateBodyTest extends TestCase
{
    private const ADMIN = ['user' => 'boss', 'email' => 'boss@example.com', 'password' => 'longenough'];

    public function test_client_sending_an_unknown_key_is_refused_with_unknown_field(): void
    {
        $problems = $this->problems(['name' => 'shop', 'runtime' => 'container', 'colour' => 'red', 'limits' => ['ram' => '1G']]);

        $this->assertSame(
            [['colour', 'unknown_field'], ['limits.ram', 'unknown_field']],
            array_map(fn (array $p): array => [$p['field'], $p['code']], $problems),
        );
    }

    public function test_client_sending_a_flat_field_gets_legacy_field_and_where_it_goes(): void
    {
        $problems = $this->problems(['name' => 'shop', 'runtime' => 'container', 'git_repo' => 'x', 'memory_limit' => '1G']);

        $this->assertSame('legacy_field', $problems[0]['code']);
        $this->assertSame('source.url', $problems[0]['replacement']);
        $this->assertSame('limits.memory', $problems[1]['replacement']);
    }

    public function test_client_sending_a_flat_field_to_a_source_attach_gets_unknown_field(): void
    {
        $body = CreateBody::forAttach(['source' => ['type' => 'git', 'url' => 'https://example.com/a/b'], 'memory_limit' => '1G']);

        $this->assertSame('unknown_field', $this->problemsOf($body)[0]['code']);
    }

    public function test_body_of_only_a_name_and_an_email_is_the_typed_body(): void
    {
        $this->assertTrue(CreateShape::isTyped(['name' => 'shop', 'email' => 'a@example.com']));
        $this->assertTrue(CreateShape::isTyped([]));
        $this->assertFalse(CreateShape::isTyped(['username' => 'shop', 'email' => 'a@example.com']));
        $this->assertFalse(CreateShape::isTyped(['name' => 'shop', 'git_repo' => 'x']));
    }

    public function test_client_naming_a_runtime_that_contradicts_the_source_is_refused(): void
    {
        $problems = $this->problems(['name' => 'shop', 'runtime' => 'container', 'source' => $this->wordpress()]);

        $this->assertSame('runtime', $problems[0]['field']);
        $this->assertSame('source_conflicts_with_runtime', $problems[0]['code']);
        $this->assertSame([], $this->problems(['name' => 'shop', 'runtime' => 'php_hosting', 'source' => $this->wordpress()]));
        $this->assertSame([], $this->problems(['name' => 'shop', 'source' => $this->wordpress()]));
    }

    public function test_client_naming_an_unknown_runtime_is_refused(): void
    {
        $this->assertSame('runtime_invalid', $this->problems(['name' => 'shop', 'runtime' => 'vm'])[0]['code']);
    }

    public function test_archive_source_needs_exactly_one_of_upload_id_and_url(): void
    {
        foreach ([[], ['upload_id' => 'upl_x', 'url' => 'https://example.com/a.zip']] as $keys) {
            $problems = $this->problems(['name' => 'shop', 'source' => ['type' => 'archive'] + $keys]);
            $this->assertSame('source_archive_needs_one', $problems[0]['code']);
        }

        $this->assertSame([], $this->problems(['name' => 'shop', 'source' => ['type' => 'archive', 'upload_id' => 'upl_x']]));
        $this->assertSame([], $this->problems(['name' => 'shop', 'source' => ['type' => 'archive', 'url' => 'https://example.com/a.zip']]));
    }

    public function test_wordpress_admin_password_needs_eight_characters(): void
    {
        $this->assertSame('source_admin_password_too_short', $this->wordpressProblems(['password' => '1234567'])[0]['code']);
        $this->assertSame([], $this->wordpressProblems(['password' => '12345678']));
    }

    public function test_wordpress_admin_user_must_match_the_pattern(): void
    {
        foreach (['bad/user', 'tab	user', str_repeat('a', 61)] as $user) {
            $this->assertSame('source_admin_user_invalid', $this->wordpressProblems(['user' => $user])[0]['code'], $user);
        }
        $this->assertSame([], $this->wordpressProblems(['user' => 'Jo Doe_1.x@y-z']));
        $this->assertSame([], $this->wordpressProblems(['user' => str_repeat('a', 60)]));
    }

    public function test_wordpress_title_is_at_most_255_characters(): void
    {
        $long = $this->problems(['name' => 'shop', 'source' => ['title' => str_repeat('t', 256)] + $this->wordpress()]);
        $edge = $this->problems(['name' => 'shop', 'source' => ['title' => str_repeat('t', 255)] + $this->wordpress()]);

        $this->assertSame('source_title_too_long', $long[0]['code']);
        $this->assertSame([], $edge);
    }

    public function test_wordpress_admin_email_must_be_an_address(): void
    {
        $this->assertSame('source_admin_email_invalid', $this->wordpressProblems(['email' => 'nope'])[0]['code']);
    }

    /** @return array<string, mixed> */
    private function wordpress(): array
    {
        return ['type' => 'wordpress', 'title' => 'Shop', 'admin' => self::ADMIN];
    }

    /**
     * @param  array<string, mixed>  $admin
     * @return list<array<string, mixed>>
     */
    private function wordpressProblems(array $admin): array
    {
        $source = $this->wordpress();
        $source['admin'] = $admin + self::ADMIN;

        return $this->problems(['name' => 'shop', 'source' => $source]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function problems(array $body): array
    {
        return $this->problemsOf(CreateBody::forCreate($body));
    }

    /** @return list<array<string, mixed>> */
    private function problemsOf(CreateBody $body): array
    {
        try {
            $body->assertWellFormed();
        } catch (ProblemException $e) {
            return $e->problems;
        }

        return [];
    }
}
