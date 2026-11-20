<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\GeneratedCompose;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Entries of a list-form `depends_on` that name no service, and a file with no app service to persist into. */
class GeneratedComposeDependsOnTest extends TestCase
{
    public function test_a_depends_on_entry_that_is_no_name_is_dropped(): void
    {
        $compose = Yaml::parse(GeneratedCompose::render(['image' => 'acme/app', 'depends_on' => ['db', '', 7]]));

        $this->assertSame(['db' => ['condition' => 'service_started']], $compose['services']['app']['depends_on']);
    }

    public function test_persisted_paths_leave_a_file_without_an_app_service_as_written(): void
    {
        $yaml = "services:\n  web:\n    image: acme/web\n";

        $this->assertSame($yaml, GeneratedCompose::withPersistedPaths($yaml, ['storage']));
    }
}
