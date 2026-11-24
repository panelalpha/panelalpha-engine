<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use PHPUnit\Framework\TestCase;

/**
 * The inspector's answers for compose content of an unexpected shape: entries that are not maps, volumes
 * that are neither strings nor maps, a build arg given as one string, and text that is not YAML.
 */
class ComposeFileInspectorShapesTest extends TestCase
{
    public function test_a_build_context_that_is_not_a_string_is_not_the_project_root(): void
    {
        $this->assertFalse(ComposeFileInspector::composeBuildContextIsProjectRoot(['context' => ['nested']]));
        $this->assertTrue(ComposeFileInspector::composeBuildContextIsProjectRoot(['dockerfile' => 'Dockerfile']));
    }

    public function test_a_v1_file_needs_a_top_level_service_with_an_image_or_build(): void
    {
        $this->assertFalse(ComposeFileInspector::isLegacyV1ComposeYaml("version: '2'\nweb:\n  ports: ['80:80']\n"));
        $this->assertTrue(ComposeFileInspector::isLegacyV1ComposeYaml("web:\n  image: nginx\n"));
    }

    public function test_a_service_that_is_not_a_map_neither_starts_nor_stops_the_file(): void
    {
        $this->assertNull(ComposeFileInspector::startsNothingReasonYaml("services:\n  junk: hello\n  app:\n    image: nginx\n"));
        $this->assertSame(
            'every service is behind a profile',
            ComposeFileInspector::startsNothingReasonYaml("services:\n  junk: hello\n  app:\n    image: nginx\n    profiles: [dev]\n")
        );
    }

    public function test_a_file_without_any_service_starts_nothing(): void
    {
        $this->assertSame('defines no services', ComposeFileInspector::startsNothingReasonYaml("networks:\n  harbor-network:\n    external: false\n"));
        $this->assertSame('defines no services', ComposeFileInspector::startsNothingReasonYaml("services: {}\n"));
        $this->assertSame('defines no services', ComposeFileInspector::startsNothingReasonYaml("services:\nvolumes:\n  data: {}\n"));
    }

    public function test_an_include_without_services_of_its_own_still_starts_something(): void
    {
        $this->assertNull(ComposeFileInspector::startsNothingReasonYaml("include:\n  - stack/compose.yaml\n"));
    }

    public function test_only_the_services_compose_up_starts_count_as_several(): void
    {
        $withDb = "services:\n  app:\n    build: .\n  db:\n    image: postgres:16\n";

        $this->assertTrue(ComposeFileInspector::startsSeveralServicesYaml($withDb));
        $this->assertFalse(ComposeFileInspector::startsSeveralServicesYaml("services:\n  app:\n    build: .\n  junk: hello\n"));
        $this->assertFalse(ComposeFileInspector::startsSeveralServicesYaml(str_replace("postgres:16\n", "postgres:16\n    profiles: [db]\n", $withDb)));
        $this->assertTrue(ComposeFileInspector::startsSeveralServicesYaml(str_replace("postgres:16\n", "postgres:16\n    profiles: [db]\n", $withDb), ['db']));
        $this->assertTrue(ComposeFileInspector::startsSeveralServicesYaml("include:\n  - stack/compose.yaml\nservices:\n  app:\n    build: .\n"));
        $this->assertFalse(ComposeFileInspector::startsSeveralServicesYaml("services: [\n"));
    }

    public function test_a_service_that_is_not_a_map_does_not_count_as_a_datastore(): void
    {
        $this->assertTrue(ComposeFileInspector::isSidecarsOnlyComposeYaml("services:\n  junk: hello\n  db:\n    image: postgres:16\n"));
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml("services:\n  junk: hello\n"));
    }

    public function test_a_host_uid_given_as_a_whole_args_string_is_a_workstation_service(): void
    {
        $this->assertTrue(ComposeFileInspector::isWorkstationAppService(['build' => ['context' => '.', 'args' => 'WWWUSER=${WWWUSER}']]));
        $this->assertFalse(ComposeFileInspector::isWorkstationAppService(['build' => ['context' => '.', 'args' => 42]]));
    }

    public function test_volumes_that_are_neither_strings_nor_maps_or_name_no_bind_mount_nothing(): void
    {
        $this->assertFalse(ComposeFileInspector::mountsWholeProjectRoot(['volumes' => [42, 'appdata', ['type' => 'volume', 'source' => '.', 'target' => '/app']]]));
        $this->assertTrue(ComposeFileInspector::mountsWholeProjectRoot(['volumes' => [['type' => 'bind', 'source' => '.', 'target' => '/app']]]));
    }

    public function test_text_that_is_not_yaml_is_no_compose_file_of_any_kind(): void
    {
        $broken = "services: [unclosed\n";

        $this->assertNull(ComposeFileInspector::localDevComposeReasonYaml($broken));
        $this->assertFalse(ComposeFileInspector::isLegacyV1ComposeYaml($broken));
        $this->assertNull(ComposeFileInspector::startsNothingReasonYaml($broken));
    }

    public function test_a_missing_file_is_not_one_the_engine_generated(): void
    {
        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose(__DIR__.'/no-such-compose.yml'));
        $this->assertNull(ComposeFileInspector::ignoredReason(__DIR__.'/no-such-compose.yml'));
    }
}
