<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
    private JavaRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = new JavaRuntime();
    }

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

    /**
     * A multi-module Maven reactor writes each module into its own target/ and
     * leaves the project root without one. BinPastes builds
     * backend/build/binpastes.jar and has no root target/ at all, so the root
     * glob found nothing and the container restarted for ever.
     */
    public function test_the_start_command_falls_back_to_a_nested_jar(): void
    {
        $command = JavaRuntime::startCommand(false);

        $this->assertStringContainsString('find . -mindepth 2 -maxdepth 4 -name "*.jar"', $command);
        // The root directory is still tried first.
        $this->assertStringContainsString('target/*.jar', $command);
        $this->assertSame('./backend/target/binpastes.jar', $this->jarChosenIn([
            'backend/target/binpastes.jar' => 50000,
        ]));
    }

    /**
     * The build tools' own jars are not the application.
     *
     * Every Gradle project ships `gradle/wrapper/gradle-wrapper.jar` and every
     * Maven-wrapper project `.mvn/wrapper/maven-wrapper.jar`, both inside the
     * depth window the reactor fallback searches. With the build having
     * produced nothing, the wrapper was the only jar found, so the container
     * ran `java -jar gradle/wrapper/gradle-wrapper.jar` and restart-looped —
     * where the honest one-line message had said what was wrong.
     */
    public function test_a_build_tool_wrapper_is_never_started_as_the_application(): void
    {
        $this->assertSame('', $this->jarChosenIn([
            'gradle/wrapper/gradle-wrapper.jar' => 60000,
            '.mvn/wrapper/maven-wrapper.jar' => 60000,
        ]), 'a wrapper jar is not a runnable application');
    }

    /**
     * Depth first, size second — which is what the fallback always claimed.
     *
     * `maven-dependency-plugin:copy-dependencies` fills `target/dependency/`
     * with the whole classpath, and sorting the whole result set by size makes
     * a 900 KB `spring-core.jar` beat the 40 KB application jar beside it. The
     * container then dies on `no main manifest attribute`.
     */
    public function test_a_copied_dependency_never_outranks_the_application_jar(): void
    {
        $this->assertSame('./backend/target/app.jar', $this->jarChosenIn([
            'backend/target/app.jar' => 40000,
            'backend/target/dependency/spring-core.jar' => 900000,
        ]));
    }

    /** `xargs` split on whitespace; the path is read back whole now. */
    public function test_a_jar_whose_path_contains_a_space_is_still_found(): void
    {
        $this->assertSame('./my app/target/app.jar', $this->jarChosenIn([
            'my app/target/app.jar' => 50000,
        ]));
    }

    /**
     * The start command runs in the runtime image, which is Debian or Ubuntu with
     * GNU find; busybox find (an Alpine test runner) has no -printf.
     */
    private function requireGnuFind(): void
    {
        exec('find . -maxdepth 0 -printf "" 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('needs GNU find (-printf), which the runtime images ship');
        }
    }

    /**
     * Runs the real generated command against a real directory tree and
     * reports which jar it picked, or '' when it refused to start one.
     *
     * Executed rather than pattern-matched: every defect above was in what the
     * pipeline *does*, and a test that only greps the string cannot see it.
     *
     * @param array<string, int> $files relative path => size in bytes
     */
    private function jarChosenIn(array $files): string
    {
        $this->requireGnuFind();
        $root = sys_get_temp_dir() . '/java-jar-' . bin2hex(random_bytes(6));
        mkdir($root . '/target', 0o777, true);
        foreach ($files as $path => $size) {
            $full = $root . '/' . $path;
            mkdir(dirname($full), 0o777, true);
            file_put_contents($full, str_repeat('x', $size));
        }

        $command = str_replace(
            'exec java -jar "$jar"',
            'printf "%s" "$jar"',
            JavaRuntime::startCommand(false)
        );
        $process = Process::fromShellCommandline($command, $root);
        $process->run();
        $output = trim($process->getOutput());

        exec('rm -rf ' . escapeshellarg($root));

        // The refusal path prints its one-line message and exits 1, so a
        // non-zero status is "it would not start anything" rather than a jar.
        return $process->isSuccessful() ? $output : '';
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

    public function test_the_build_image_is_chosen_from_what_is_on_disk(): void
    {
        $dir = sys_get_temp_dir() . '/pa-java-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $this->assertSame(JavaRuntime::MAVEN_IMAGE, JavaRuntime::imageFor($dir));

            touch($dir . '/build.gradle');
            $this->assertSame(JavaRuntime::GRADLE_IMAGE, JavaRuntime::imageFor($dir));

            touch($dir . '/pom.xml');
            $this->assertSame(JavaRuntime::MAVEN_IMAGE, JavaRuntime::imageFor($dir));
        } finally {
            @unlink($dir . '/build.gradle');
            @unlink($dir . '/pom.xml');
            rmdir($dir);
        }
    }

    public function test_no_project_directory_still_names_an_image(): void
    {
        $this->assertSame(JavaRuntime::MAVEN_IMAGE, JavaRuntime::imageFor(''));
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function project(array $files): string
    {
        $dir = sys_get_temp_dir() . '/pa-java-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            @mkdir(dirname($dir . '/' . $path), 0o777, true);
            file_put_contents($dir . '/' . $path, $contents);
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    private function resolveIn(string $dir): Requirement
    {
        $requirement = $this->runtime->resolve(ProjectContext::at($dir));
        $this->assertNotNull($requirement);

        return $requirement;
    }

    /**
     * Tigase, GraphHopper, Druid and ThingsBoard compile for Java 25, and the
     * JDK 21 image failed each with `release version 25 not supported`.
     */
    public function test_a_pom_compiling_for_java_25_gets_a_jdk_25_image(): void
    {
        $dir = $this->project(['pom.xml' => '<project><build><plugins><plugin>'
            . '<artifactId>maven-compiler-plugin</artifactId><configuration><release>25</release>'
            . '</configuration></plugin></plugins></build></project>']);

        $requirement = $this->resolveIn($dir);

        $this->assertSame('25-maven', $requirement->version);
        $this->assertSame('maven:3-eclipse-temurin-25', $this->runtime->image($requirement));
        $this->assertStringContainsString('release 25', $requirement->explain());
    }

    public function test_a_property_reference_is_followed_to_its_value(): void
    {
        // Druid: <maven.compiler.release>${java.version}</maven.compiler.release>
        $dir = $this->project(['pom.xml' => '<project><properties><java.version>25</java.version>'
            . '<maven.compiler.release>${java.version}</maven.compiler.release></properties></project>']);

        $this->assertSame('25-maven', $this->resolveIn($dir)->version);
    }

    public function test_an_older_release_still_builds_on_the_default_jdk(): void
    {
        // javac 21 compiles --release 17 and 8; nothing is gained by a second image.
        foreach (['17', '1.8', '21'] as $release) {
            $dir = $this->project(['pom.xml' => "<project><properties><maven.compiler.release>{$release}"
                . '</maven.compiler.release></properties></project>']);

            $this->assertSame(JavaRuntime::MAVEN, $this->resolveIn($dir)->version, $release);
        }
        $this->assertSame(JavaRuntime::MAVEN, $this->resolveIn($this->project(['pom.xml' => '<project/>']))->version);
    }

    public function test_a_release_past_the_newest_jdk_gets_the_newest(): void
    {
        $this->assertSame('25-maven', JavaRuntime::toolchain('maven', 30));
        $this->assertSame('21-maven', JavaRuntime::toolchain('maven', 11));
    }

    /**
     * alf.io declares a Java 25 toolchain; on gradle:8-jdk21 Gradle stopped
     * with `Cannot find a Java installation ... languageVersion=25`.
     */
    public function test_a_gradle_toolchain_is_matched_exactly(): void
    {
        foreach (['25' => '25-gradle', '17' => '17-gradle', '21' => '21-gradle'] as $version => $toolchain) {
            $dir = $this->project(['build.gradle' => "java { toolchain { languageVersion = JavaLanguageVersion.of({$version}) } }"]);

            $this->assertSame($toolchain, $this->resolveIn($dir)->version, $version);
        }

        $kotlin = $this->project(['build.gradle.kts' => 'kotlin { jvmToolchain(17) }']);
        $this->assertSame('17-gradle', $this->resolveIn($kotlin)->version);
    }

    public function test_a_toolchain_named_in_gradle_properties_in_a_subproject_is_found(): void
    {
        // Tolgee: backend/app/build.gradle says JavaLanguageVersion.of(javaVersion.toInteger()).
        $dir = $this->project([
            'build.gradle' => 'plugins {}',
            'gradle.properties' => "kotlinVersion=2.2\njavaVersion=25\n",
            'backend/app/build.gradle' => 'java { toolchain { languageVersion = JavaLanguageVersion.of(javaVersion.toInteger()) } }',
        ]);

        $this->assertSame('25-gradle', $this->resolveIn($dir)->version);
    }

    /**
     * scm-manager's wrapper is Gradle 7.6.4, which cannot start on JDK 21 at
     * all; 8.5 is the first that can.
     */
    public function test_an_old_gradle_wrapper_gets_a_jdk_it_can_run_on(): void
    {
        $this->assertSame('17-gradle', $this->resolveIn($this->gradleProject('7.6.4'))->version);
        $this->assertSame('21-gradle', $this->resolveIn($this->gradleProject('8.14.3'))->version);
        $this->assertSame('21-gradle', $this->resolveIn($this->gradleProject('9.7.0'))->version);
    }

    /**
     * scm-manager (Gradle 7) and Solr (Gradle 9.7) both failed on the image's
     * Gradle 8.14; Gradle's own error says "use the gradlew script".
     */
    public function test_a_project_with_a_gradle_wrapper_builds_with_it(): void
    {
        $dir = $this->gradleProject('9.7.0');

        $this->assertStringContainsString('./gradlew --no-daemon -x test build', JavaRuntime::gradleBuildCommand($dir));
        $this->assertSame('9.7.0', JavaRuntime::gradleWrapperVersion($dir));
    }

    public function test_without_a_complete_wrapper_the_images_gradle_builds(): void
    {
        $this->assertSame('gradle --no-daemon -x test build', JavaRuntime::gradleBuildCommand($this->project(['build.gradle' => ''])));

        // gradlew without its jar cannot run.
        $dir = $this->project(['build.gradle' => '', 'gradlew' => '#!/bin/sh']);
        $this->assertSame('gradle --no-daemon -x test build', JavaRuntime::gradleBuildCommand($dir));
    }

    private function gradleProject(string $wrapper): string
    {
        return $this->project([
            'build.gradle' => 'plugins { id "java" }',
            'gradlew' => "#!/bin/sh\n",
            'gradle/wrapper/gradle-wrapper.jar' => 'jar',
            'gradle/wrapper/gradle-wrapper.properties' => "distributionUrl=https\\://services.gradle.org/distributions/gradle-{$wrapper}-bin.zip\n",
        ]);
    }
}
