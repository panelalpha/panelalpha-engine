<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use PHPUnit\Framework\TestCase;
use Tests\Support\JavaRuntimeFixtures;

/**
 * Which Java build system a project uses, and how to start what it produced.
 *
 * Maven and Gradle put their jar in different places, and both directories
 * contain jars that are not runnable: Gradle emits a `-plain.jar` alongside
 * the boot jar, and `java -jar` on it fails with "no main manifest
 * attribute". Picking the wrong file is a container that builds and then
 * exits immediately.
 */
class JavaRuntimeTest extends TestCase
{
    use JavaRuntimeFixtures;

    /**
     * @param array<string, true> $files
     */
    private function context(array $files): ProjectContext
    {
        return ProjectContext::make('/nonexistent', $files);
    }

    public function test_a_maven_project_is_recognised(): void
    {
        $requirement = $this->runtime->resolve($this->context(['pom.xml' => true]));

        $this->assertSame('java', $requirement->id);
        $this->assertSame('pom.xml', $requirement->source);
        $this->assertSame(JavaRuntime::MAVEN_IMAGE, $this->runtime->image($requirement));
    }

    public function test_a_gradle_project_is_recognised_in_either_language(): void
    {
        foreach (['build.gradle', 'build.gradle.kts'] as $file) {
            $requirement = $this->runtime->resolve($this->context([$file => true]));

            $this->assertSame(JavaRuntime::GRADLE_IMAGE, $this->runtime->image($requirement), $file);
        }
    }

    public function test_maven_wins_when_a_project_carries_both(): void
    {
        // Gradle build files linger in repos that migrated to Maven; the pom
        // is the one that is maintained.
        $requirement = $this->runtime->resolve($this->context(['pom.xml' => true, 'build.gradle' => true]));

        $this->assertSame(JavaRuntime::MAVEN_IMAGE, $this->runtime->image($requirement));
    }

    public function test_a_project_with_neither_is_not_a_java_project(): void
    {
        $this->assertNull($this->runtime->resolve($this->context(['package.json' => true])));
    }

    public function test_a_project_that_says_nothing_gets_the_engines_default(): void
    {
        $default = $this->runtime->defaultRequirement();

        $this->assertSame('java', $default->id);
        $this->assertSame('engine default', $default->source);
        $this->assertSame(JavaRuntime::MAVEN_IMAGE, $this->runtime->image($default));
    }

    public function test_the_start_command_looks_where_each_tool_writes(): void
    {
        $this->assertStringContainsString('target/*.jar', JavaRuntime::startCommand(false));
        $this->assertStringContainsString('build/libs/*.jar', JavaRuntime::startCommand(true));
    }

    public function test_the_start_command_skips_the_jars_that_cannot_be_run(): void
    {
        // `-plain.jar` is the one Gradle emits without the Boot loader;
        // running it fails with "no main manifest attribute".
        $command = JavaRuntime::startCommand(true);

        foreach (['-plain\.jar', '-sources\.jar', '-javadoc\.jar'] as $excluded) {
            $this->assertStringContainsString($excluded, $command);
        }
    }

    public function test_the_start_command_takes_the_largest_remaining_jar(): void
    {
        // The fat jar is the one with the dependencies in it.
        $this->assertStringContainsString('ls -S ', JavaRuntime::startCommand(false));
    }

    public function test_a_build_that_produced_no_jar_says_so_rather_than_starting_nothing(): void
    {
        // Otherwise `java -jar ""` fails with a message about nothing.
        $command = JavaRuntime::startCommand(false);

        $this->assertStringContainsString('PANELALPHA: no runnable jar', $command);
        $this->assertStringContainsString('exit 1', $command);
    }

    public function test_the_server_replaces_the_shell(): void
    {
        // Otherwise SIGTERM reaches the shell and the JVM never shuts down.
        $this->assertStringContainsString('exec java -jar', JavaRuntime::startCommand(false));
    }

    public function test_no_project_directory_still_names_an_image(): void
    {
        $this->assertSame(JavaRuntime::MAVEN_IMAGE, JavaRuntime::imageFor(''));
    }

    public function test_a_release_past_the_newest_jdk_gets_the_newest(): void
    {
        $this->assertSame('25-maven', JavaRuntime::toolchain('maven', 30));
        $this->assertSame('11-maven', JavaRuntime::toolchain('maven', 11));
    }
}
