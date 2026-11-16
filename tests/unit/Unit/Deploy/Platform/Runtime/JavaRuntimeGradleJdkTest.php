<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The JDK a Gradle build resolves to, and the toolchain the project gets, pinned per build shape.
 */
class JavaRuntimeGradleJdkTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-characterise-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function tree(array $files): string
    {
        foreach ($files as $relative => $contents) {
            $path = $this->dir.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }

        return $this->dir;
    }

    public static function builds(): array
    {
        return [
            'no directory' => [[], true, [21, ''], null],
            'nothing declared' => [['build.gradle' => 'plugins {}'], false, [21, ''], '21-gradle'],
            'old source compatibility' => [['build.gradle' => 'sourceCompatibility = JavaVersion.VERSION_17'], false, [21, ''], '21-gradle'],
            'new source compatibility' => [['build.gradle.kts' => 'java { sourceCompatibility = "25" }'], false, [25, 'sourceCompatibility 25'], '25-gradle'],
            'underscored source compatibility' => [['build.gradle' => 'sourceCompatibility = JavaVersion.VERSION_1_8'], false, [21, ''], '21-gradle'],
            'uncatalogued toolchain' => [['build.gradle' => 'kotlin { jvmToolchain(19) }'], false, [21, ''], '21-gradle'],
            'toolchain from properties' => [['build.gradle' => 'java { toolchain { languageVersion = JavaLanguageVersion.of(javaVersion) } }', 'gradle.properties' => "javaVersion=17\n"], false, [17, 'toolchain 17'], '17-gradle'],
            'unresolvable property' => [['build.gradle' => 'kotlin { jvmToolchain(missing) }'], false, [21, ''], '21-gradle'],
            'old wrapper' => [['build.gradle' => 'sourceCompatibility = 11', 'gradlew' => '#!/bin/sh', 'gradle/wrapper/gradle-wrapper.jar' => 'jar', 'gradle/wrapper/gradle-wrapper.properties' => "distributionUrl=https\\://services.gradle.org/distributions/gradle-7.6.4-bin.zip\n"], false, [17, 'Gradle wrapper 7.6.4'], '17-gradle'],
            'ancient wrapper' => [['build.gradle' => '', 'gradlew' => '#!/bin/sh', 'gradle/wrapper/gradle-wrapper.jar' => 'jar', 'gradle/wrapper/gradle-wrapper.properties' => "distributionUrl=https\\://services.gradle.org/distributions/gradle-6.9-all.zip\n"], false, [17, 'Gradle wrapper 6.9'], '17-gradle'],
            'current wrapper' => [['build.gradle' => '', 'gradlew' => '#!/bin/sh', 'gradle/wrapper/gradle-wrapper.jar' => 'jar', 'gradle/wrapper/gradle-wrapper.properties' => "distributionUrl=https\\://services.gradle.org/distributions/gradle-8.14.3-bin.zip\n"], false, [21, ''], '21-gradle'],
            'subproject toolchain' => [['build.gradle' => '', 'app/build.gradle' => 'java { toolchain { languageVersion = JavaLanguageVersion.of(25) } }'], false, [25, 'toolchain 25'], '25-gradle'],
            'maven beside gradle' => [['pom.xml' => '<project><properties><maven.compiler.release>17</maven.compiler.release></properties></project>', 'build.gradle' => 'kotlin { jvmToolchain(25) }'], false, [25, 'toolchain 25'], '21-maven'],
        ];
    }

    /**
     * @param  array<string, string>  $files
     * @param  array{0: int, 1: string}  $jdk
     */
    #[DataProvider('builds')]
    public function test_the_gradle_jdk_is_pinned(array $files, bool $noDirectory, array $jdk, ?string $toolchain): void
    {
        $dir = $noDirectory ? '' : $this->tree($files);

        $this->assertSame($jdk, JavaRuntime::gradleJdk($dir));
        if ($toolchain !== null) {
            $this->assertSame($toolchain, JavaRuntime::toolchainFor($dir));
        }
    }
}
