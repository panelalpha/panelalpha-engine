<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Whether a Java build runs a JS tool, and which Node it gets when it does.
 *
 * Tolgee's `gradle/utils.gradle` runs `which npm` while Gradle configures and
 * throws when it is missing, so no task can be skipped around it. Only a build
 * that names a JS tool gets Node; every other Java build is left alone.
 */
final class JavaNodeTooling
{
    /**
     * A JS tool as a quoted command in a Gradle script: `resolveExecutable("npm")`,
     * `commandLine 'npm', 'ci'`, `commandLine("yarn build")`. Or the node-gradle plugin.
     */
    private const GRADLE_NODE = '/(["\'])(?:npm|npx|pnpm|yarn|node)(?:\.cmd)?(?:\1|\s)'
        . '|\bcom\.github\.node-gradle\.node\b|\bcom\.moowork\.node\b/';

    /**
     * The node-gradle plugin declared in a version catalog, which a script then
     * applies as `alias(libs.plugins.node)` without naming it (halo).
     */
    private const CATALOG_NODE = '/\bcom\.github\.node-gradle\.node\b|\bcom\.moowork\.node\b/';

    /** exec-maven-plugin running a JS tool. */
    private const MAVEN_NODE = '/<executable>\s*(?:npm|npx|pnpm|yarn|node)(?:\.cmd)?\s*<\/executable>/i';

    /** Build scripts are small; anything bigger is not one. */
    private const MAX_BYTES = 1048576;

    /**
     * The Node image the build needs, or null when it runs no JS tool.
     *
     * The Node version is the repository root's (`engines.node`, `.nvmrc`),
     * else the engine's default.
     */
    public static function nodeImageFor(string $projectDir): ?string
    {
        $root = rtrim($projectDir, '/');
        if ($root === '' || !is_dir($root)) {
            return null;
        }

        foreach (self::buildScripts($root) as $path => $pattern) {
            if (!is_file($path) || (int) @filesize($path) > self::MAX_BYTES) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (is_string($contents) && preg_match($pattern, $contents) === 1) {
                return NodeRuntime::imageFor($root);
            }
        }

        return null;
    }

    /**
     * Gradle scripts at the root, in gradle/ and buildSrc/, and in modules
     * two levels down; version catalogs in gradle/; poms at the same depths.
     *
     * @return array<string, string> path => pattern to match it with
     */
    private static function buildScripts(string $root): array
    {
        $scripts = [];
        $gradle = ['/', '/gradle/', '/buildSrc/', '/*/', '/*/*/'];
        foreach ($gradle as $dir) {
            foreach (['*.gradle', '*.gradle.kts'] as $name) {
                foreach (glob($root . $dir . $name) ?: [] as $path) {
                    $scripts[$path] = self::GRADLE_NODE;
                }
            }
        }
        foreach (glob($root . '/gradle/*.versions.toml') ?: [] as $path) {
            $scripts[$path] = self::CATALOG_NODE;
        }
        foreach (['/', '/*/', '/*/*/'] as $dir) {
            foreach (glob($root . $dir . 'pom.xml') ?: [] as $path) {
                $scripts[$path] = self::MAVEN_NODE;
            }
        }

        return $scripts;
    }
}
