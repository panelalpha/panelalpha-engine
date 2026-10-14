<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `[]` and `{}` both parse to a PHP `[]`; writing the document back must not
 * turn one into the other. `healthcheck.test: {}` is an invalid project.
 */
class ComposeYamlDumpTest extends TestCase
{
    private const SOURCE = <<<'YAML'
        services:
          worker:
            image: acme/app
            command: []
            healthcheck:
              test: []
            environment: {}
        volumes:
          data: {}
        networks: {}
        YAML;

    public function test_empty_sequences_and_empty_maps_survive_a_round_trip(): void
    {
        $out = ComposeYaml::dump(ComposeYaml::parse(self::SOURCE), self::SOURCE, 6, 2);
        $read = Yaml::parse($out, Yaml::PARSE_OBJECT_FOR_MAP);

        $this->assertSame([], $read->services->worker->healthcheck->test);
        $this->assertSame([], $read->services->worker->command);
        $this->assertEquals(new \stdClass(), $read->services->worker->environment);
        $this->assertEquals(new \stdClass(), $read->volumes->data);
        $this->assertEquals(new \stdClass(), $read->networks);
    }

    public function test_a_file_the_engine_wrote_survives_being_rewritten(): void
    {
        $first = ComposeYaml::dump(ComposeYaml::parse(self::SOURCE), self::SOURCE, 6, 2);
        $second = ComposeYaml::dump(ComposeYaml::parse($first), $first, 6, 2);

        $this->assertSame($first, $second);
    }

    public function test_an_empty_array_the_source_did_not_have_is_still_a_map(): void
    {
        $compose = ComposeYaml::parse(self::SOURCE);
        $compose['services']['worker']['labels'] = [];

        $read = Yaml::parse(ComposeYaml::dump($compose, self::SOURCE, 6, 2), Yaml::PARSE_OBJECT_FOR_MAP);

        $this->assertEquals(new \stdClass(), $read->services->worker->labels);
    }

    public function test_a_source_needing_the_anchor_rescue_is_still_read(): void
    {
        $source = <<<'YAML'
            x-base:
              # shared
              &base
              healthcheck:
                test: []
            services:
              worker:
                <<: *base
                image: acme/app
            YAML;

        $read = Yaml::parse(
            ComposeYaml::dump(ComposeYaml::parse($source), $source, 6, 2),
            Yaml::PARSE_OBJECT_FOR_MAP
        );

        $this->assertSame([], $read->services->worker->healthcheck->test);
    }
}
