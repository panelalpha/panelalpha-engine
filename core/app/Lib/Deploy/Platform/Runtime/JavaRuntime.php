<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Java, where the build tool picks the image: a Gradle project built in the
 * Maven image has no gradle on PATH.
 */
final class JavaRuntime implements Runtime
{
    public const MAVEN_IMAGE = 'maven:3.9-eclipse-temurin-21';

    public const GRADLE_IMAGE = 'gradle:8-jdk21';

    /**
     * A Java version is a JDK release and its build tool; each carries its own `from` through a per-version
     * catalogue override.
     *
     * @var list<string>
     */
    public const TOOLCHAINS = [self::MAVEN, self::GRADLE];

    public const MAVEN = '21-maven';

    public const GRADLE = '21-gradle';

    /** The JDK a project gets when it names none, or names one 21 builds. */
    public const DEFAULT_JDK = 21;

    /**
     * Gradle releases that first run on a JDK; an older wrapper cannot start on it at all (scm-manager's 7.6.4
     * dies on 21 before reading a build file).
     *
     * @var array<int, string>
     */
    private const GRADLE_RUNS_ON = [25 => '9.1', 21 => '8.5', 17 => '7.3'];

    /**
     * Where a Gradle build declares its Java: the root script, one or two levels of subprojects (Tolgee's
     * `backend/*`), shared scripts under gradle/, and convention plugins.
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

    /**
     * Builder image for a toolchain: catalogue first, else the constants. The catalogue's top-level `from` is
     * Maven's, so a Gradle toolchain it does not list gets the Gradle constant.
     */
    public static function imageTag(string $toolchain): string
    {
        $spec = self::isUncataloguedGradle($toolchain) ? null : RuntimeImageCatalog::spec('java', $toolchain);
        if ($spec !== null) {
            return $spec->from;
        }

        return str_ends_with($toolchain, '-gradle') ? self::GRADLE_IMAGE : self::MAVEN_IMAGE;
    }

    private static function isUncataloguedGradle(string $toolchain): bool
    {
        return str_ends_with($toolchain, '-gradle')
            && ! in_array($toolchain, RuntimeImageCatalog::versions('java'), true);
    }

    public function id(): string
    {
        return 'java';
    }

    /**
     * Maven when there is a pom, Gradle otherwise; the tie-break `java-gradle.yaml` states with `none: pom.xml`.
     * Disagreeing put the jar in target/ while the start looked in build/libs.
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

    /**
     * Logged as "java 25-maven (from pom.xml maven.compiler.release 25)".
     *
     * @param  array{0: int, 1: string}  $jdk
     */
    private static function requirement(string $tool, string $file, array $jdk): Requirement
    {
        [$release, $said] = $jdk;

        return $said === ''
            ? new Requirement('java', self::detectedToolchain($tool, $release), '', $file)
            : new Requirement('java', self::detectedToolchain($tool, $release), (string) $release, $file.' '.$said);
    }

    /**
     * Maven builds an older release on the default JDK, which javac compiles for; an older JDK is only
     * taken when a build refuses this one ({@see JdkRefusal}).
     */
    private static function detectedToolchain(string $tool, int $release): string
    {
        return self::toolchain($tool, $tool === 'maven' ? max($release, self::DEFAULT_JDK) : $release);
    }

    /**
     * The catalogued toolchain with the lowest JDK at or above the one asked for, since javac compiles older
     * releases; past the newest gets the newest, and the build then says what is missing.
     */
    public static function toolchain(string $tool, int $jdk): string
    {
        $candidates = self::releasesOf($tool);
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
     * The catalogued toolchain inside [$min, $max): the lowest, or the highest when $newest; null for none.
     */
    public static function toolchainIn(string $tool, int $min, int $max, bool $newest = false): ?string
    {
        $inside = array_filter(
            self::releasesOf($tool),
            static fn (int $release): bool => $release >= $min && $release < $max,
            ARRAY_FILTER_USE_KEY
        );
        if ($inside === []) {
            return null;
        }
        ksort($inside);

        return $newest ? end($inside) : reset($inside);
    }

    /** @return array<int, string> JDK release => toolchain */
    private static function releasesOf(string $tool): array
    {
        $releases = [];
        foreach (self::toolchains() as $toolchain) {
            if (preg_match('/^(\d+)-'.preg_quote($tool, '/').'$/', $toolchain, $m) === 1) {
                $releases[(int) $m[1]] = $toolchain;
            }
        }

        return $releases;
    }

    /**
     * The highest release a pom names in `maven.compiler.release/source/target`, `<release>` or `java.version`.
     * A parent pom from a repository is not on disk yet, so it is not read.
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
                $said = $name.' '.$release;
            }
        }

        return $best === null ? [self::DEFAULT_JDK, ''] : [$best, $said];
    }

    /**
     * The JDK a Gradle build needs: a toolchain matched exactly, as Gradle does; then `sourceCompatibility`;
     * then the newest JDK the wrapper's Gradle runs on, when older than the default.
     *
     * @return array{0: int, 1: string} JDK, and what said so ('' for nothing)
     */
    public static function gradleJdk(string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        $scripts = self::gradleScripts($projectDir);

        $toolchain = self::toolchainJdk($projectDir, $scripts);
        if ($toolchain !== null) {
            return $toolchain;
        }

        $compatibility = self::sourceCompatibilityJdk($scripts);
        if ($compatibility !== null) {
            return $compatibility;
        }

        return self::wrapperJdk($projectDir);
    }

    private static function gradleScripts(string $projectDir): string
    {
        $scripts = '';
        if ($projectDir !== '') {
            foreach (self::GRADLE_SCRIPTS as $pattern) {
                foreach (array_slice(glob($projectDir.$pattern) ?: [], 0, 100) as $file) {
                    $scripts .= (AccountHomeRead::contents($file) ?: '')."\n";
                }
            }
        }

        return $scripts;
    }

    /**
     * `JavaLanguageVersion.of(N)` or `jvmToolchain(N)`, N possibly a gradle.properties name, when catalogued.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function toolchainJdk(string $projectDir, string $scripts): ?array
    {
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
            if (in_array($jdk.'-gradle', self::toolchains(), true)) {
                return [$jdk, 'toolchain '.$jdk];
            }
        }

        return null;
    }

    /** @return array{0: int, 1: string}|null */
    private static function sourceCompatibilityJdk(string $scripts): ?array
    {
        if (preg_match_all('/sourceCompatibility\s*=\s*(?:JavaVersion\.VERSION_)?[\'"]?([\d._]+)/', $scripts, $m) > 0) {
            $releases = array_filter(array_map([self::class, 'release'], str_replace('_', '.', $m[1])));
            if ($releases !== []) {
                $jdk = max($releases);
                if ($jdk > self::DEFAULT_JDK) {
                    return [$jdk, 'sourceCompatibility '.$jdk];
                }
            }
        }

        return null;
    }

    /** @return array{0: int, 1: string} */
    private static function wrapperJdk(string $projectDir): array
    {
        $wrapper = self::gradleWrapperVersion($projectDir);
        if ($wrapper !== null) {
            foreach (self::GRADLE_RUNS_ON as $jdk => $since) {
                if ($jdk <= self::DEFAULT_JDK && version_compare($wrapper, $since, '>=')) {
                    return $jdk === self::DEFAULT_JDK
                        ? [self::DEFAULT_JDK, '']
                        : [$jdk, 'Gradle wrapper '.$wrapper];
                }
            }

            return [min(array_keys(self::GRADLE_RUNS_ON)), 'Gradle wrapper '.$wrapper];
        }

        return [self::DEFAULT_JDK, ''];
    }

    /**
     * The Gradle version the checked-in wrapper downloads, or null without a
     * usable wrapper. The script alone is not one: it needs the jar beside it.
     */
    public static function gradleWrapperVersion(string $projectDir): ?string
    {
        if (! self::hasGradleWrapper($projectDir)) {
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
            && is_file($projectDir.'/gradlew')
            && is_file($projectDir.'/gradle/wrapper/gradle-wrapper.jar')
            && is_file($projectDir.'/gradle/wrapper/gradle-wrapper.properties');
    }

    /**
     * The project's wrapper when it ships one, made executable since the mode bit does not always survive:
     * it pins the Gradle its scripts need, where the image's Gradle 8 fails 7.x and 9.x builds.
     */
    public static function gradleBuildCommand(string $projectDir): string
    {
        $args = '--no-daemon -x test build';
        if (! self::hasGradleWrapper($projectDir)) {
            return 'gradle '.$args;
        }

        return 'chmod +x ./gradlew && ./gradlew '.$args;
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
        if ($projectDir === '' || ! is_file($projectDir.'/'.$relative)) {
            return null;
        }
        $contents = AccountHomeRead::contents($projectDir.'/'.$relative);

        return is_string($contents) ? $contents : null;
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * Starts Quarkus's launcher, else the largest runnable jar in the output dir, else the shallowest below,
     * skipping `-plain`/`-sources`/`-javadoc`, wrapper and copied jars and any jar without a Main-Class.
     */
    public static function startCommand(bool $gradle): string
    {
        $dir = $gradle ? 'build/libs' : 'target';
        $quarkus = ($gradle ? 'build' : 'target').'/quarkus-app/quarkus-run.jar';

        return 'has_main() { if command -v unzip >/dev/null 2>&1; then'
            .' unzip -p "$1" META-INF/MANIFEST.MF 2>/dev/null | grep -qi "^Main-Class:";'
            .' elif command -v jar >/dev/null 2>&1; then'
            .' d="$(mktemp -d)"; (cd "$d" && jar xf "$1" META-INF/MANIFEST.MF) >/dev/null 2>&1;'
            .' grep -qi "^Main-Class:" "$d/META-INF/MANIFEST.MF" 2>/dev/null; r=$?; rm -rf "$d"; return $r;'
            .' fi; };'
            .' runnable() { while IFS= read -r j; do has_main "$PWD/$j" && { printf "%s\n" "$j"; return 0; }; done; return 0; };'
            .' jar="$(ls '.$quarkus.' */'.$quarkus.' 2>/dev/null | head -1)";'
            .' [ -n "$jar" ] || jar="$(ls -S '.$dir.'/*.jar 2>/dev/null'
            .' | grep -v -- "-plain\.jar$"'
            .' | grep -v -- "-sources\.jar$"'
            .' | grep -v -- "-javadoc\.jar$"'
            .' | runnable)";'
            .' [ -n "$jar" ] || jar="$(find . -mindepth 2 -maxdepth 4 -name "*.jar"'
            .' -not -path "./.git/*"'
            .' -not -path "./gradle/*"'
            .' -not -path "./.mvn/*"'
            .' -not -path "*/dependency/*"'
            .' -not -name "*-wrapper.jar"'
            .' -printf "%d %s %p\n" 2>/dev/null'
            .' | grep -v -- "-plain\.jar$"'
            .' | grep -v -- "-sources\.jar$"'
            .' | grep -v -- "-javadoc\.jar$"'
            .' | sort -k1,1n -k2,2nr | cut -d" " -f3- | runnable)";'
            .' [ -n "$jar" ] || { echo "PANELALPHA: no runnable jar in '.$dir.'"; exit 1; };'
            .' exec java -jar "$jar"';
    }

    /**
     * Builder image for a project directory, for callers only warming an image.
     */
    public static function imageFor(string $projectDir): string
    {
        return self::imageTag(self::toolchainFor($projectDir));
    }

    /**
     * Maven wins a repository that ships both, as in resolve(): spring-petclinic disagreeing built into target/
     * while the start command looked in build/libs.
     */
    public static function toolchainFor(string $projectDir): string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir !== '' && ! is_file($projectDir.'/pom.xml')
            && (is_file($projectDir.'/build.gradle') || is_file($projectDir.'/build.gradle.kts'))
        ) {
            return self::detectedToolchain('gradle', self::gradleJdk($projectDir)[0]);
        }

        return self::detectedToolchain('maven', self::mavenJdk($projectDir)[0]);
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
