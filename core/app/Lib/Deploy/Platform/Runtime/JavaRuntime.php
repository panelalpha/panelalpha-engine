<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Java, where the build tool picks the image: a Gradle project built in the
 * Maven image has no gradle on PATH.
 */
final class JavaRuntime implements Runtime
{
    public const MAVEN_IMAGE = 'maven:3-eclipse-temurin-21';
    public const GRADLE_IMAGE = 'gradle:8-jdk21';

    /**
     * A Java requirement's version is a JDK release and its build tool, and
     * each entry carries its own `from` through a per-version catalogue
     * override.
     *
     * @var list<string>
     */
    public const TOOLCHAINS = [self::MAVEN, self::GRADLE];

    public const MAVEN = '21-maven';

    public const GRADLE = '21-gradle';

    /** The JDK a project gets when it names none, or names one 21 builds. */
    public const DEFAULT_JDK = 21;

    /**
     * Gradle releases that first run on a JDK. A wrapper older than the entry
     * cannot start on that JDK at all (scm-manager pins 7.6.4, which dies on
     * 21 before reading a build file).
     *
     * @var array<int, string>
     */
    private const GRADLE_RUNS_ON = [25 => '9.1', 21 => '8.5', 17 => '7.3'];

    /**
     * Where a Gradle build declares its Java: the root script, one or two
     * levels of subprojects (Tolgee declares it only in `backend/*`), shared
     * scripts under gradle/, and convention plugins.
     */
    private const GRADLE_SCRIPTS = [
        '/build.gradle', '/build.gradle.kts', '/gradle/*.gradle', '/gradle/*.gradle.kts',
        '/*/build.gradle', '/*/build.gradle.kts', '/*/*/build.gradle', '/*/*/build.gradle.kts',
        '/buildSrc/src/main/*/*.gradle*', '/build-logic/*/src/main/*/*.gradle*',
    ];

    /**
     * JDK releases, for callers asking about the JDK itself. Not what
     * supportedVersions() answers: no project resolves to a bare "17".
     *
     * @var list<string>
     */
    public const RELEASES = ['17', '21', '25'];

    /**
     * The toolchains a project may resolve to.
     *
     * @return list<string>
     */
    public static function toolchains(): array
    {
        $configured = RuntimeImageCatalog::versions('java');

        return $configured === [] ? self::TOOLCHAINS : $configured;
    }

    public static function defaultToolchain(): string
    {
        return RuntimeImageCatalog::defaultVersion('java') ?? self::MAVEN;
    }

    /** Builder image for a toolchain: catalogue first, else the constants. */
    public static function imageTag(string $toolchain): string
    {
        $spec = RuntimeImageCatalog::spec('java', $toolchain);
        if ($spec !== null) {
            return $spec->from;
        }

        return str_ends_with($toolchain, '-gradle') ? self::GRADLE_IMAGE : self::MAVEN_IMAGE;
    }

    public function id(): string
    {
        return 'java';
    }

    /**
     * Maven when there is a pom, Gradle otherwise; a project carrying both is
     * Maven's. The same tie-break `java-gradle.yaml` states with `none:
     * pom.xml`: disagreeing put the jar in target/ while the start looked in
     * build/libs.
     */
    public function resolve(ProjectContext $context): ?Requirement
    {
        if ($context->hasFile('pom.xml')) {
            return self::requirement('maven', 'pom.xml', self::mavenJdk($context->projectDir));
        }
        if ($context->hasFile('build.gradle') || $context->hasFile('build.gradle.kts')) {
            return self::requirement('gradle', 'build.gradle', self::gradleJdk($context->projectDir));
        }

        return null;
    }

    /** @param array{0: int, 1: string} $jdk */
    private static function requirement(string $tool, string $file, array $jdk): Requirement
    {
        [$release, $said] = $jdk;

        // "java 25-maven (from pom.xml maven.compiler.release 25)" in the log.
        return $said === ''
            ? new Requirement('java', self::toolchain($tool, $release), '', $file)
            : new Requirement('java', self::toolchain($tool, $release), (string) $release, $file . ' ' . $said);
    }

    /**
     * The catalogued toolchain for a build tool and the JDK a project asked
     * for: the lowest JDK at or above it, since javac compiles older releases
     * (a Gradle toolchain is matched exactly before this is reached). Asking
     * past the newest gets the newest; the build then says what is missing.
     */
    public static function toolchain(string $tool, int $jdk): string
    {
        $candidates = [];
        foreach (self::toolchains() as $toolchain) {
            if (preg_match('/^(\d+)-' . preg_quote($tool, '/') . '$/', $toolchain, $m) === 1) {
                $candidates[(int) $m[1]] = $toolchain;
            }
        }
        if ($candidates === []) {
            return $tool === 'gradle' ? self::GRADLE : self::MAVEN;
        }
        ksort($candidates);
        foreach ($candidates as $release => $toolchain) {
            if ($release >= $jdk) {
                return $toolchain;
            }
        }

        return end($candidates);
    }

    /**
     * The Java release a pom compiles for: `maven.compiler.release`, the
     * compiler plugin's `<release>`, `maven.compiler.source/target` or Spring
     * Boot's `java.version`, the highest of them. A parent pom from a
     * repository is not read -- it is not on disk yet.
     *
     * @return array{0: int, 1: string} JDK, and what said so ('' for nothing)
     */
    public static function mavenJdk(string $projectDir): array
    {
        $pom = self::read($projectDir, 'pom.xml');
        if ($pom === null) {
            return [self::DEFAULT_JDK, ''];
        }

        $properties = [];
        if (preg_match('#<properties>(.*?)</properties>#s', $pom, $block) === 1) {
            preg_match_all('#<([\w.-]+)>\s*([^<]*?)\s*</\1>#', $block[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $properties[$pair[1]] = $pair[2];
            }
        }

        $best = null;
        $said = '';
        preg_match_all(
            '#<(maven\.compiler\.(?:release|target|source)|release|java\.version)>\s*([^<]+?)\s*</\1>#',
            $pom,
            $found,
            PREG_SET_ORDER
        );
        foreach ($found as [, $name, $value]) {
            if (preg_match('/^\$\{([\w.-]+)\}$/', $value, $ref) === 1) {
                $value = $properties[$ref[1]] ?? '';
            }
            $release = self::release($value);
            if ($release !== null && ($best === null || $release > $best)) {
                $best = $release;
                $said = $name . ' ' . $release;
            }
        }

        return $best === null ? [self::DEFAULT_JDK, ''] : [$best, $said];
    }

    /**
     * The JDK a Gradle build needs. A toolchain (`JavaLanguageVersion.of(N)`,
     * `jvmToolchain(N)`, N possibly a gradle.properties name) is matched
     * exactly, the way Gradle matches it; then `sourceCompatibility`; then the
     * newest JDK the wrapper's Gradle can run on, when older than the default.
     *
     * @return array{0: int, 1: string} JDK, and what said so ('' for nothing)
     */
    public static function gradleJdk(string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        $scripts = '';
        if ($projectDir !== '') {
            foreach (self::GRADLE_SCRIPTS as $pattern) {
                foreach (array_slice(glob($projectDir . $pattern) ?: [], 0, 100) as $file) {
                    $scripts .= (@file_get_contents($file) ?: '') . "\n";
                }
            }
        }

        if (preg_match_all('/(?:JavaLanguageVersion\.of|jvmToolchain)\s*\(\s*([\w.]+)/', $scripts, $m) > 0) {
            $properties = self::gradleProperties($projectDir);
            $declared = [];
            foreach ($m[1] as $value) {
                $declared[] = ctype_digit($value)
                    ? (int) $value
                    : self::release($properties[explode('.', $value)[0]] ?? '');
            }
            $declared = array_filter($declared);
            $jdk = $declared === [] ? 0 : max($declared);
            if (in_array($jdk . '-gradle', self::toolchains(), true)) {
                return [$jdk, 'toolchain ' . $jdk];
            }
        }

        if (preg_match_all('/sourceCompatibility\s*=\s*(?:JavaVersion\.VERSION_)?[\'"]?([\d._]+)/', $scripts, $m) > 0) {
            $releases = array_filter(array_map([self::class, 'release'], str_replace('_', '.', $m[1])));
            if ($releases !== []) {
                $jdk = max($releases);
                if ($jdk > self::DEFAULT_JDK) {
                    return [$jdk, 'sourceCompatibility ' . $jdk];
                }
            }
        }

        $wrapper = self::gradleWrapperVersion($projectDir);
        if ($wrapper !== null) {
            foreach (self::GRADLE_RUNS_ON as $jdk => $since) {
                if ($jdk <= self::DEFAULT_JDK && version_compare($wrapper, $since, '>=')) {
                    return $jdk === self::DEFAULT_JDK
                        ? [self::DEFAULT_JDK, '']
                        : [$jdk, 'Gradle wrapper ' . $wrapper];
                }
            }

            return [min(array_keys(self::GRADLE_RUNS_ON)), 'Gradle wrapper ' . $wrapper];
        }

        return [self::DEFAULT_JDK, ''];
    }

    /**
     * The Gradle version the checked-in wrapper downloads, or null without a
     * usable wrapper. The script alone is not one: it needs the jar beside it.
     */
    public static function gradleWrapperVersion(string $projectDir): ?string
    {
        if (!self::hasGradleWrapper($projectDir)) {
            return null;
        }
        $properties = self::read($projectDir, 'gradle/wrapper/gradle-wrapper.properties') ?? '';
        if (preg_match('/distributionUrl\s*=.*gradle-(\d+(?:\.\d+)*)(?:-[\w.]+)?-(?:bin|all)\.zip/', $properties, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public static function hasGradleWrapper(string $projectDir): bool
    {
        $projectDir = rtrim($projectDir, '/');

        return $projectDir !== ''
            && is_file($projectDir . '/gradlew')
            && is_file($projectDir . '/gradle/wrapper/gradle-wrapper.jar')
            && is_file($projectDir . '/gradle/wrapper/gradle-wrapper.properties');
    }

    /**
     * The project's own wrapper when it ships one: it pins the Gradle its
     * build scripts are written for, and the image's Gradle 8 fails both a
     * 7.x build (`archiveName()` is gone) and a 9.x one (Solr refuses to
     * configure). The image's `gradle` only when there is no wrapper.
     */
    public static function gradleBuildCommand(string $projectDir): string
    {
        $args = '--no-daemon -x test build';
        if (!self::hasGradleWrapper($projectDir)) {
            return 'gradle ' . $args;
        }

        // The mode bit does not survive every way a project arrives.
        return 'chmod +x ./gradlew && ./gradlew ' . $args;
    }

    /** @return array<string, string> */
    private static function gradleProperties(string $projectDir): array
    {
        $properties = [];
        foreach (preg_split('/\R/', self::read($projectDir, 'gradle.properties') ?? '') ?: [] as $line) {
            if (preg_match('/^\s*([\w.-]+)\s*[=:]\s*(.*?)\s*$/', $line, $m) === 1) {
                $properties[$m[1]] = $m[2];
            }
        }

        return $properties;
    }

    /** "25", "1.8" or "17.0.2" as a Java release; null for anything else. */
    private static function release(string $value): ?int
    {
        if (preg_match('/^(?:1\.)?(\d+)(?:\.\d+)*$/', trim($value), $m) !== 1) {
            return null;
        }
        $release = (int) $m[1];

        return $release >= 5 && $release <= 99 ? $release : null;
    }

    private static function read(string $projectDir, string $relative): ?string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '' || !is_file($projectDir . '/' . $relative)) {
            return null;
        }
        $contents = @file_get_contents($projectDir . '/' . $relative);

        return is_string($contents) ? $contents : null;
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * The largest non-`-plain`/`-sources`/`-javadoc` jar wins, resolved at start
     * time; a multi-module reactor's is found in the shallowest directory below
     * (four levels at most), skipping wrapper jars and copy-dependencies. Depth
     * sorts before size.
     */
    public static function startCommand(bool $gradle): string
    {
        $dir = $gradle ? 'build/libs' : 'target';

        return 'jar="$(ls -S ' . $dir . '/*.jar 2>/dev/null'
            . ' | grep -v -- "-plain\.jar$"'
            . ' | grep -v -- "-sources\.jar$"'
            . ' | grep -v -- "-javadoc\.jar$"'
            . ' | head -1)";'
            . ' [ -n "$jar" ] || jar="$(find . -mindepth 2 -maxdepth 4 -name "*.jar"'
            . ' -not -path "./.git/*"'
            . ' -not -path "./gradle/*"'
            . ' -not -path "./.mvn/*"'
            . ' -not -path "*/dependency/*"'
            . ' -not -name "*-wrapper.jar"'
            . ' -printf "%d %s %p\n" 2>/dev/null'
            . ' | grep -v -- "-plain\.jar$"'
            . ' | grep -v -- "-sources\.jar$"'
            . ' | grep -v -- "-javadoc\.jar$"'
            . ' | sort -k1,1n -k2,2nr | head -1 | cut -d" " -f3-)";'
            . ' [ -n "$jar" ] || { echo "PANELALPHA: no runnable jar in ' . $dir . '"; exit 1; };'
            . ' exec java -jar "$jar"';
    }

    /**
     * Builder image for a project directory, for callers only warming an image.
     */
    public static function imageFor(string $projectDir): string
    {
        return self::imageTag(self::toolchainFor($projectDir));
    }

    /**
     * Maven wins a repository that ships both, the order resolve() applies:
     * spring-petclinic ships pom.xml and build.gradle, and disagreeing left it
     * building into target/ while the start command looked in build/libs.
     */
    public static function toolchainFor(string $projectDir): string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir !== '' && !is_file($projectDir . '/pom.xml')
            && (is_file($projectDir . '/build.gradle') || is_file($projectDir . '/build.gradle.kts'))
        ) {
            return self::toolchain('gradle', self::gradleJdk($projectDir)[0]);
        }

        return self::toolchain('maven', self::mavenJdk($projectDir)[0]);
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('java', self::defaultToolchain(), '', 'engine default');
    }

    /**
     * The toolchains, not RELEASES: these feed provisioning and the prewarm
     * matrix, and ['17', '21'] names neither image anything runs.
     *
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return self::toolchains();
    }
}
