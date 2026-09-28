<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use PHPUnit\Framework\TestCase;

/**
 * A repository carrying both build files must resolve to one build tool for
 * everything: the image, the build command and the start command.
 *
 * It did not. `java-gradle` outranked `java` on priority, so the commands came
 * from Gradle, while JavaRuntime checks pom.xml first, so the image came from
 * Maven. spring-petclinic ships both — it compiled with mvn into `target/` and
 * then restart-looped on `PANELALPHA: no runnable jar in build/libs`.
 */
class JavaBuildToolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-java-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_a_project_with_both_build_files_is_maven_throughout(): void
    {
        file_put_contents($this->dir . '/pom.xml', '<project></project>');
        file_put_contents($this->dir . '/build.gradle', 'plugins {}');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('Java (Maven)', $decision['label']);
        $this->assertStringContainsString('maven', $decision['image']);
        $this->assertStringContainsString('mvn ', $decision['build_command']);
        $this->assertStringContainsString('target/', $decision['start_command']);
        $this->assertStringNotContainsString('build/libs', $decision['start_command']);
    }

    public function test_a_gradle_only_project_is_gradle_throughout(): void
    {
        file_put_contents($this->dir . '/build.gradle', 'plugins {}');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('Java (Gradle)', $decision['label']);
        $this->assertStringContainsString('gradle', $decision['image']);
        $this->assertStringContainsString('gradle ', $decision['build_command']);
        $this->assertStringContainsString('build/libs', $decision['start_command']);
    }

    public function test_a_kotlin_gradle_only_project_is_gradle_too(): void
    {
        file_put_contents($this->dir . '/build.gradle.kts', 'plugins {}');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('Java (Gradle)', $decision['label']);
        $this->assertStringContainsString('build/libs', $decision['start_command']);
    }

    public function test_a_gradle_project_with_a_wrapper_builds_with_it(): void
    {
        mkdir($this->dir . '/gradle/wrapper', 0o777, true);
        file_put_contents($this->dir . '/build.gradle', 'plugins {}');
        file_put_contents($this->dir . '/gradlew', "#!/bin/sh\n");
        file_put_contents($this->dir . '/gradle/wrapper/gradle-wrapper.jar', 'jar');
        file_put_contents(
            $this->dir . '/gradle/wrapper/gradle-wrapper.properties',
            "distributionUrl=https\\://services.gradle.org/distributions/gradle-7.6.4-bin.zip\n"
        );

        try {
            $decision = DetectProjectStrategy::detect($this->dir);

            $this->assertStringContainsString('./gradlew ', $decision['build_command']);
            $this->assertSame('gradle:8-jdk17', $decision['image']);
        } finally {
            exec('rm -rf ' . escapeshellarg($this->dir . '/gradle'));
        }
    }

    /**
     * engine#283: a Spring Boot app that sets `server.address=localhost` binds
     * 127.0.0.1 and nothing outside the container reaches it. Both Java
     * platforms put the env var that outranks it into the container.
     */
    public function test_both_java_platforms_bind_spring_to_every_interface(): void
    {
        file_put_contents($this->dir . '/pom.xml', '<project></project>');
        $this->assertSame('0.0.0.0', DetectProjectStrategy::detect($this->dir)['env']['SERVER_ADDRESS'] ?? null);

        unlink($this->dir . '/pom.xml');
        file_put_contents($this->dir . '/build.gradle', 'plugins {}');
        $gradle = DetectProjectStrategy::detect($this->dir);
        $this->assertSame('java-gradle', $gradle['platform']);
        $this->assertSame('0.0.0.0', $gradle['env']['SERVER_ADDRESS'] ?? null);
    }

    /** OpenTripPlanner binds `spotless:apply` with Prettier, which needs npm, to its build. */
    public function test_a_maven_build_does_not_run_the_source_formatter(): void
    {
        file_put_contents($this->dir . '/pom.xml', '<project></project>');

        $build = DetectProjectStrategy::detect($this->dir)['build_command'];

        $this->assertStringContainsString('-Dspotless.apply.skip=true', $build);
        $this->assertStringContainsString('-Dspotless.check.skip=true', $build);
        $this->assertStringContainsString('-DskipTests', $build);
    }
}
