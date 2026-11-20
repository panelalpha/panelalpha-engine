<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\UnresolvedRuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * Naming a runtime the engine does not know, and a required runtime the project gives no sign of.
 */
class RuntimeRegistryLookupTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeRegistry::flush();
        parent::tearDown();
    }

    private function nothing(): ProjectContext
    {
        return ProjectContext::make('/nonexistent/pa-runtime-registry', []);
    }

    public function test_an_unknown_runtime_is_refused_with_the_ones_the_engine_knows(): void
    {
        try {
            RuntimeRegistry::get('cobol');
            $this->fail('an unknown runtime should be refused');
        } catch (ManifestException $e) {
            $this->assertStringStartsWith("Unknown runtime 'cobol'; the engine knows ", $e->getMessage());
            $this->assertStringContainsString('node', $e->getMessage());
            $this->assertStringContainsString('php', $e->getMessage());
        }
    }

    public function test_a_required_runtime_the_project_gives_no_sign_of_is_unresolved(): void
    {
        $this->expectException(UnresolvedRuntimeException::class);

        RuntimeRegistry::resolveAll(['rust' => ['role' => 'runtime']], $this->nothing());
    }

    public function test_leniently_a_missing_runtime_is_its_default_and_an_optional_one_absent(): void
    {
        $resolved = RuntimeRegistry::resolveAll([
            'rust' => ['role' => Requirement::ROLE_BUILD],
            'go' => ['role' => 'runtime', 'optional' => true],
        ], $this->nothing(), true);

        $this->assertCount(1, $resolved);
        $this->assertSame(['rust', 'engine default', Requirement::ROLE_BUILD], [$resolved[0]->id, $resolved[0]->source, $resolved[0]->role]);
    }

    public function test_a_flush_loads_the_same_runtimes_again(): void
    {
        $before = array_keys(RuntimeRegistry::all());
        RuntimeRegistry::flush();

        $this->assertSame($before, array_keys(RuntimeRegistry::all()));
    }
}
