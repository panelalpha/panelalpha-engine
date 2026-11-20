<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
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
     * @param list<string> $withoutMain jars written with no Main-Class
     */
    private function jarChosenIn(array $files, array $withoutMain = [], bool $needsFind = true): string
    {
        if ($needsFind) {
            $this->requireGnuFind();
        }
        $root = sys_get_temp_dir() . '/java-jar-' . bin2hex(random_bytes(6));
        mkdir($root . '/target', 0o777, true);
        foreach ($files as $path => $size) {
            $full = $root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            $this->writeJar($full, $size, !in_array($path, $withoutMain, true));
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

    /** A real jar, stored uncompressed so its size on disk follows $size. */
    private function writeJar(string $path, int $size, bool $main): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\n" . ($main ? "Main-Class: app.Main\r\n" : ''));
        $zip->addFromString('payload.bin', str_repeat('x', $size));
        $zip->setCompressionName('payload.bin', \ZipArchive::CM_STORE);
        $zip->close();
    }

    /**
     * CommaFeed: the React client is packaged as a 4 MB webjar with no
     * Main-Class, the Quarkus server as a 694-byte launcher. The webjar ran and
     * the container crash-looped on "no main manifest attribute".
     */
    public function test_a_quarkus_fast_jar_launcher_is_started(): void
    {
        $this->assertSame('commafeed-server/target/quarkus-app/quarkus-run.jar', $this->jarChosenIn([
            'commafeed-client/target/commafeed-client-7.3.2.jar' => 4000000,
            'commafeed-server/target/quarkus-app/quarkus-run.jar' => 694,
            'commafeed-server/target/quarkus-app/lib/main/io.quarkus.quarkus-core.jar' => 900000,
        ], ['commafeed-client/target/commafeed-client-7.3.2.jar'], false));
    }

    public function test_a_jar_without_a_main_class_is_passed_over(): void
    {
        $this->assertSame('target/app.jar', $this->jarChosenIn([
            'target/app-webjar.jar' => 400000,
            'target/app.jar' => 40000,
        ], ['target/app-webjar.jar'], false));
    }

    /** The reactor fallback, too: shallowest first, but only a runnable one. */
    public function test_a_module_jar_without_a_main_class_is_passed_over(): void
    {
        $this->assertSame('./server/target/server.jar', $this->jarChosenIn([
            'client/target/client.jar' => 400000,
            'server/target/server.jar' => 40000,
        ], ['client/target/client.jar']));
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
        $this->assertSame('maven:3.9-eclipse-temurin-25', $this->runtime->image($requirement));
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

    public function test_a_jdk_is_found_inside_a_range(): void
    {
        $this->assertSame('17-maven', JavaRuntime::toolchainIn('maven', 17, 18));
        $this->assertSame('8-maven', JavaRuntime::toolchainIn('maven', 8, 21));
        $this->assertSame('17-maven', JavaRuntime::toolchainIn('maven', 0, 21, newest: true));
        $this->assertSame('17-gradle', JavaRuntime::toolchainIn('gradle', 0, 21));
        $this->assertNull(JavaRuntime::toolchainIn('maven', 22, 25));
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
