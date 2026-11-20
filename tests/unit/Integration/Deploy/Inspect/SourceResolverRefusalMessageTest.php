<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\SourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A clone refused over credentials says whether a token was sent, and never names a request field.
 */
class SourceResolverRefusalMessageTest extends TestCase
{
    private const AUTH_OUTPUT = "remote: Invalid username or password.\nfatal: Authentication failed for 'https://example.test/o/r.git/'";

    private const MISSING_OUTPUT = "remote: Repository not found.\nfatal: repository 'https://example.test/o/r.git/' not found";

    #[DataProvider('refusals')]
    public function test_a_refused_clone_is_explained_without_a_request_field(
        string $output,
        bool $tokenSent,
        string $expected
    ): void {
        $problem = $this->refusal($output, $tokenSent)->toProblem();
        $message = (string) $problem['message'];

        $this->assertStringContainsString('Could not clone the repository: ', $message);
        $this->assertStringContainsString($expected, $message);
        $this->assertStringNotContainsString('git_token', $message);
        $this->assertStringNotContainsString('Pass ', $message);
    }

    #[DataProvider('refusalShapes')]
    public function test_the_problem_keeps_its_field_and_code(string $output, bool $tokenSent): void
    {
        $problem = $this->refusal($output, $tokenSent)->toProblem();

        $this->assertSame('source', $problem['field']);
        $this->assertSame('source_unreadable', $problem['code']);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function refusals(): array
    {
        return [
            'no token, host asked for a login' => [
                self::AUTH_OUTPUT,
                false,
                'the repository is private or does not exist. Send an access token that can read it',
            ],
            'token sent, host refused it' => [
                self::AUTH_OUTPUT,
                true,
                'the token was refused, or it cannot read this repository',
            ],
            'token sent, host has no such repository' => [
                self::MISSING_OUTPUT,
                true,
                'there is no repository at this address, or the token cannot see it',
            ],
            'no token, host has no such repository' => [
                self::MISSING_OUTPUT,
                false,
                "fatal: repository 'https://example.test/o/r.git/' not found",
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function refusalShapes(): array
    {
        return array_map(static fn (array $row): array => [$row[0], $row[1]], self::refusals());
    }

    private function refusal(string $output, bool $tokenSent): InspectException
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'run');
        $command = ['sh', '-c', 'printf %s '.escapeshellarg($output).' >&2; exit 128'];

        try {
            $method->invoke(new SourceResolver(sys_get_temp_dir(), 10), $command, 'Could not clone the repository', null, $tokenSent);
        } catch (InspectException $e) {
            return $e;
        }

        $this->fail('the clone failure was not raised');

        throw new \RuntimeException('unreachable');
    }
}
