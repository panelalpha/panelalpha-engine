<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Detect\DetectionResult;
use PHPUnit\Framework\TestCase;

/**
 * The shape every detection answer has.
 *
 * The result crosses a process boundary as JSON and is read by key on the
 * other side, so a platform that says nothing about `output_directory` still
 * has to produce the key: `array_key_exists` and `?? null` are the same
 * question here, but only one of them survives a round trip through a reader
 * that expects the field to be there.
 */
class DetectionResultTest extends TestCase
{
    public function test_every_optional_key_is_present_even_when_unanswered(): void
    {
        $result = DetectionResult::of('static', 'Static site');

        foreach ([
            'runtime', 'output_directory', 'package_manager', 'install_command',
            'build_command', 'start_command', 'env', 'static_index',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, $key);
            $this->assertNull($result[$key], $key);
        }
    }

    public function test_the_identity_fields_are_always_there(): void
    {
        $result = DetectionResult::of('static', 'Static site');

        $this->assertSame('static', $result['strategy']);
        $this->assertSame('Static site', $result['label']);
        $this->assertNull($result['compose_path']);
        $this->assertNull($result['dockerfile']);
        $this->assertNull($result['port_hint']);
    }

    public function test_what_a_platform_decided_is_carried_through(): void
    {
        $result = DetectionResult::of('laravel', 'Laravel', null, null, 8080, [
            'runtime' => 'php',
            'install_command' => 'composer install --no-dev',
            'env' => ['APP_ENV' => 'production'],
        ]);

        $this->assertSame('php', $result['runtime']);
        $this->assertSame('composer install --no-dev', $result['install_command']);
        $this->assertSame(['APP_ENV' => 'production'], $result['env']);
    }

    public function test_the_identity_cannot_be_overwritten_by_the_extras(): void
    {
        // The extras are the platform's own decision array, which carries its
        // own copies of these keys. The caller's arguments are the truth -
        // a strategy silently replaced by a stale copy would route the deploy
        // to the wrong builder.
        $result = DetectionResult::of('laravel', 'Laravel', null, null, 8080, [
            'strategy' => 'php',
            'label' => 'PHP',
            'port_hint' => 9000,
        ]);

        $this->assertSame('laravel', $result['strategy']);
        $this->assertSame('Laravel', $result['label']);
        $this->assertSame(8080, $result['port_hint']);
    }

    public function test_a_platforms_decision_becomes_a_complete_result(): void
    {
        $result = DetectionResult::fromPlatform([
            'strategy' => 'compose',
            'label' => 'Docker Compose',
            'compose_path' => '/srv/app/docker-compose.yml',
            'port_hint' => 8080,
            'runtime' => 'compose',
        ]);

        $this->assertSame('compose', $result['strategy']);
        $this->assertSame('/srv/app/docker-compose.yml', $result['compose_path']);
        $this->assertSame(8080, $result['port_hint']);
        $this->assertArrayHasKey('static_index', $result);
    }

    public function test_a_platform_that_named_no_paths_still_produces_them(): void
    {
        $result = DetectionResult::fromPlatform(['strategy' => 'php', 'label' => 'PHP']);

        $this->assertNull($result['compose_path']);
        $this->assertNull($result['dockerfile']);
        $this->assertNull($result['port_hint']);
    }

    public function test_the_result_survives_a_round_trip_as_json(): void
    {
        // Which is how it actually reaches the deploy pipeline.
        $result = DetectionResult::of('static', 'Static site', null, null, null, ['static_index' => 'index.html']);
        $decoded = json_decode((string) json_encode($result), true);

        $this->assertSame($result, $decoded);
        $this->assertArrayHasKey('output_directory', $decoded);
    }
}
