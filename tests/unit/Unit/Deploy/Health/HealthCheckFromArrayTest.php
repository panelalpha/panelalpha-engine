<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckException;
use App\Lib\Deploy\Health\HealthCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins fromArray(): the first refusal and its message for each malformed check, and what a valid one holds. */
class HealthCheckFromArrayTest extends TestCase
{
    private const VALID = ['id' => 'ok-check', 'message' => ' Broken ', 'expect' => ['status' => 200]];

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'unknown key' => [self::VALID + ['extra' => 1], 'php/x.yaml', "php/x.yaml: unknown key(s) extra"],
            'id not a slug' => [['id' => 'Bad Id'] + self::VALID, 'php/x.yaml', "php/x.yaml: 'id' must be a lowercase slug"],
            'id missing' => [['message' => 'm', 'expect' => ['status' => 200]], 'php/x.yaml', "php/x.yaml: 'id' must be a lowercase slug"],
            'message blank, source named' => [['message' => '  '] + self::VALID, 'php/x.yaml', "php/x.yaml: 'message' must be a non-empty string"],
            'message blank, no source' => [['message' => ''] + self::VALID, '', "php/ok-check: 'message' must be a non-empty string"],
            'severity unknown' => [self::VALID + ['severity' => 'fatal'], 'php/x.yaml', "php/ok-check: 'severity' must be one of error, warning, info"],
            'expect not an object' => [['expect' => 'yes'] + self::VALID, 'php/x.yaml', "php/ok-check: 'expect' must be an object"],
            'expect key unknown' => [['expect' => ['header' => 'x']] + self::VALID, 'php/x.yaml', "php/ok-check: unknown expect key(s) header; a question needing more than status, status_not, body, body_not, path, json belongs in an explain class"],
            'when not an object' => [self::VALID + ['when' => 'always'], 'php/x.yaml', "php/ok-check: 'when' must be an object"],
            'expect empty' => [['expect' => []] + self::VALID, 'php/x.yaml', "php/ok-check: 'expect' declares no condition, so the check can never fail"],
            'expect path alone' => [['expect' => ['path' => '/health']] + self::VALID, 'php/x.yaml', "php/ok-check: 'expect' declares no condition, so the check can never fail"],
            'path a url' => [['expect' => ['path' => 'http://x', 'status' => 200]] + self::VALID, 'php/x.yaml', "php/ok-check: 'path' must be a local path beginning with '/', not a URL"],
            'path protocol relative' => [['expect' => ['path' => '//x/y', 'status' => 200]] + self::VALID, 'php/x.yaml', "php/ok-check: 'path' must not begin with '//'"],
            'path with a space' => [['expect' => ['path' => '/a b', 'status' => 200]] + self::VALID, 'php/x.yaml', "php/ok-check: 'path' must not contain whitespace or control characters"],
            'json empty' => [['expect' => ['json' => []]] + self::VALID, 'php/x.yaml', "php/ok-check: 'json' must be a non-empty object"],
            'json a list' => [['expect' => ['json' => ['a']]] + self::VALID, 'php/x.yaml', "php/ok-check: 'json' must be an object of members, not a list"],
            'json nested' => [['expect' => ['json' => ['db' => ['a' => 1]]]] + self::VALID, 'php/x.yaml', "php/ok-check: 'json.db' must not be nested — a member compared as an object belongs in an explain class"],
            'json empty list' => [['expect' => ['json' => ['db' => []]]] + self::VALID, 'php/x.yaml', "php/ok-check: 'json.db' must name at least one value"],
            'json list of objects' => [['expect' => ['json' => ['db' => [['a']]]]] + self::VALID, 'php/x.yaml', "php/ok-check: 'json.db' must be a string, number or boolean — a member compared as an object or array belongs in an explain class"],
            'landing empty' => [self::VALID + ['landing' => []], 'php/x.yaml', "php/ok-check: 'landing' must be a non-empty list of paths"],
            'landing relative' => [self::VALID + ['landing' => ['install']], 'php/x.yaml', "php/ok-check: 'landing' paths must begin with '/' and hold no whitespace"],
            'fix blank' => [self::VALID + ['fix' => ' '], 'php/x.yaml', "php/ok-check: 'fix' must be a non-empty string when present"],
            'explain not a string' => [self::VALID + ['explain' => 3], 'php/x.yaml', "php/ok-check: 'explain' must be a non-empty string when present"],
        ];
    }

    /** @param  array<string, mixed>  $raw */
    #[DataProvider('refusals')]
    public function test_a_malformed_check_is_refused(array $raw, string $source, string $message): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessage($message);

        HealthCheck::fromArray($raw, 'php', $source);
    }

    public function test_a_valid_check_keeps_what_it_declared(): void
    {
        $check = HealthCheck::fromArray([
            'severity' => 'warning',
            'when' => ['file' => 'composer.json'],
            'landing' => ['/install'],
            'fix' => ' Do this ',
            'serving' => 'placeholder',
            'expect' => ['status' => 200, 'path' => '/health?deep=1', 'json' => ['status' => ['ok', 'healthy'], 'up' => true]],
        ] + self::VALID, 'php', 'php/ok-check.yaml');

        $this->assertSame(
            ['php', 'ok-check', 'warning', 'Broken', 'Do this', 'placeholder', null, 'php/ok-check.yaml', ['/install'], ['file' => 'composer.json']],
            [$check->group, $check->id, $check->severity, $check->message, $check->fix, $check->serving, $check->explain, $check->source, $check->landing, $check->when]
        );
        $this->assertSame('/health?deep=1', $check->path());
        $this->assertSame(['status' => ['ok', 'healthy'], 'up' => true], $check->json());
        $this->assertSame('php/ok-check', $check->reference());
    }
}
