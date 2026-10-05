<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JavaNodeTooling;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use Tests\TestCase;

/**
 * Tolgee's Gradle build looks npm up while it configures (#141). Only a build
 * that names a JS tool gets Node; a plain Java build keeps its image.
 */
class JavaNodeToolingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/java-node-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function write(string $path, string $contents): void
    {
        $full = $this->dir . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }

    /** Tolgee ba9e738: the lookup is in gradle/utils.gradle, applied from build.gradle. */
    public function test_tolgee_utils_gradle_needs_node(): void
    {
        $this->write('build.gradle', "apply from: \"./gradle/webapp.gradle\"\n");
        $this->write('gradle/utils.gradle', <<<'GRADLE'
            ext {
                def resolveExecutable = { String executableName ->
                    def executable = (osName.contains("windows") && executableName == "npm") ? "npm.cmd" : executableName
                    def proc = [locator, executable].execute()
                }
                npmCommandName = resolveExecutable("npm")
            }
            GRADLE);
        $this->write('package.json', '{"name":"@tolgee/server","devDependencies":{"semantic-release":"^19.0.2"}}');

        $this->assertSame(NodeRuntime::imageFor($this->dir), JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_kotlin_dsl_exec_in_a_module_needs_node(): void
    {
        $this->write('settings.gradle.kts', "include(\"server\")\n");
        $this->write('server/frontend/build.gradle.kts', "tasks.register<Exec>(\"web\") { commandLine(\"yarn build\") }\n");

        $this->assertNotNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_node_gradle_plugin_needs_node(): void
    {
        $this->write('build.gradle', "plugins { id 'com.github.node-gradle.node' version '7.0.2' }\n");

        $this->assertNotNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    /** halo: the plugin id is only in the version catalog; ui/build.gradle applies an alias. */
    public function test_node_gradle_plugin_from_a_version_catalog_needs_node(): void
    {
        $this->write('settings.gradle', "include 'ui'\n");
        $this->write('ui/build.gradle', "plugins {\n  id 'base'\n  alias(libs.plugins.node)\n}\n");
        $this->write('gradle/libs.versions.toml', "[plugins]\nnode = 'com.github.node-gradle.node:7.1.0'\n"
            . "spring-boot = { id = 'org.springframework.boot', version.ref = 'spring-boot' }\n");

        $this->assertNotNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    /** A catalog naming no JS plugin leaves the build alone, whatever its version keys are called. */
    public function test_a_version_catalog_without_the_node_plugin_needs_no_node(): void
    {
        $this->write('build.gradle', "plugins { alias(libs.plugins.spring.boot) }\n");
        $this->write('gradle/libs.versions.toml', "[versions]\nnode = \"20\"\n[libraries]\n"
            . "foo = { module = \"org.example:foo\", version.ref = \"node\" }\n");

        $this->assertNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_maven_exec_of_npm_needs_node(): void
    {
        $this->write('pom.xml', '<project><build><plugins><plugin><configuration>'
            . '<executable>npm</executable></configuration></plugin></plugins></build></project>');

        $this->assertNotNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_node_version_follows_the_repository(): void
    {
        $this->write('build.gradle', "task web(type: Exec) { commandLine 'npm', 'ci' }\n");
        $this->write('.nvmrc', "22\n");

        $this->assertSame('node:22-bookworm-slim', JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_plain_java_builds_get_no_node(): void
    {
        $this->write('build.gradle', <<<'GRADLE'
            plugins { id 'org.springframework.boot' version '3.3.0' }
            // the frontend is built with npm elsewhere
            dependencies { implementation 'org.webjars.npm:bootstrap:5.3.3' }
            tasks.named('processResources') { exclude 'node_modules/**', 'yarn.lock' }
            GRADLE);
        $this->write('pom.xml', '<project><build><plugin><artifactId>frontend-maven-plugin</artifactId>'
            . '<configuration><nodeVersion>v20.11.0</nodeVersion></configuration></plugin></build></project>');
        // A package.json alone is not a build that runs npm.
        $this->write('package.json', '{"devDependencies":{"prettier":"^3"}}');

        $this->assertNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_missing_directory_is_null(): void
    {
        $this->assertNull(JavaNodeTooling::nodeImageFor($this->dir . '/nope'));
        $this->assertNull(JavaNodeTooling::nodeImageFor(''));
    }
}
