<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;

/**
 * Where `vite build` writes, read from the project without running it: the
 * build script's `--outDir`, else `build.outDir` in vite.config, resolved
 * against `root` the way Vite does. The default when it is not a literal.
 *
 * Sunshine builds into `build/assets/web` (`outDir: resolve(assetsDstPath)`,
 * a `let` holding a literal) and kresus into `build/client` (`root:
 * './client'`, `outDir: '../build/client'`); serving `dist` for either ends
 * the deploy on `dist/index.html is missing` after a successful build.
 */
final class ViteOutputDir
{
    /** Vite's own lookup order. */
    private const CONFIGS = [
        'vite.config.js', 'vite.config.mjs', 'vite.config.ts',
        'vite.config.cjs', 'vite.config.mts', 'vite.config.cts',
    ];

    private const BASE = '(?:__dirname|import\.meta\.dirname|process\.cwd\(\))';

    /** A call (two levels of nested parentheses), a string literal, or a name. */
    private const EXPR = '((?:[\w.$]+\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))|([\'"`])[^\'"`]*\2|[\w$]+)';

    public static function outputDir(string $projectDir, string $default = 'dist'): string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '') {
            return $default;
        }

        $config = null;
        foreach (self::CONFIGS as $name) {
            if (is_file($projectDir . '/' . $name)) {
                $config = (string) @file_get_contents($projectDir . '/' . $name);
                break;
            }
        }

        $dir = self::resolve($config ?? '', self::cliOutDir($projectDir));

        return $dir === null ? $default : NodeRuntime::safeOutputDir($dir, $default);
    }

    /**
     * The output directory relative to the project, or null when the config
     * does not say so in literals.
     */
    public static function resolve(string $config, ?string $cliOutDir = null): ?string
    {
        $outDir = null;
        $rest = $config;
        if (preg_match('/\bbuild\s*:\s*\{/', $config, $m, PREG_OFFSET_CAPTURE) === 1) {
            $block = self::braceBlock($config, $m[0][1] + strlen($m[0][0]) - 1);
            $rest = str_replace($block, '', $config);
            if (preg_match('/\boutDir\s*:\s*' . self::EXPR . '/', $block, $o) === 1) {
                $outDir = self::evaluate(trim($o[1]), $config);
                if ($outDir === null) {
                    return null;
                }
            }
        }
        if ($cliOutDir !== null) {
            $outDir = [$cliOutDir, false];
        }
        if ($outDir === null) {
            return null;
        }

        [$path, $anchored] = $outDir;
        if (!$anchored && preg_match('/\broot\s*:\s*' . self::EXPR . '/', $rest, $r) === 1) {
            $root = self::evaluate(trim($r[1]), $config);
            if ($root === null) {
                return null;
            }
            $path = $root[0] . '/' . $path;
        }

        return self::normalize($path);
    }

    /**
     * `--outDir x` or `--outDir=x` on the build script's `vite build`.
     */
    private static function cliOutDir(string $projectDir): ?string
    {
        $build = ProjectContext::at($projectDir)->script('build');
        if (preg_match('/\bvite\s+build\b[^&|;]*?--outDir(?:=|\s+)([\'"]?)([^\s\'"&|;]+)\1/', $build, $m) === 1) {
            return $m[2];
        }

        return null;
    }

    /**
     * A config expression as `[path, anchored]`: anchored paths are relative
     * to the project directory, the rest to Vite's `root`. Null when it is
     * not built from literals.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function evaluate(string $expr, string $config): ?array
    {
        $expr = rtrim($expr, " \t,");
        $literal = self::literal($expr, $config);
        if ($literal !== null) {
            return [$literal, false];
        }

        // fileURLToPath(new URL('./x', import.meta.url))
        if (preg_match('/^fileURLToPath\(\s*new\s+URL\(\s*([\'"`])([^\'"`$]+)\1\s*,\s*import\.meta\.url\s*\)\s*\)$/', $expr, $m) === 1) {
            return [$m[2], true];
        }

        // resolve(...), path.resolve(...), join(...), path.join(...)
        if (preg_match('/^(?:path\.)?(resolve|join)\(\s*(.*?)\s*\)$/s', $expr, $m) !== 1) {
            return null;
        }
        $args = array_map('trim', explode(',', $m[2]));
        $anchored = $m[1] === 'resolve';
        $parts = [];
        foreach ($args as $i => $arg) {
            if ($i === 0 && preg_match('/^' . self::BASE . '$/', $arg) === 1) {
                $anchored = true;
                continue;
            }
            $value = self::literal($arg, $config);
            if ($value === null) {
                return null;
            }
            $parts[] = $value;
        }

        return $parts === [] ? null : [implode('/', $parts), $anchored];
    }

    /** A string literal, or an identifier declared once as one. */
    private static function literal(string $expr, string $config): ?string
    {
        if (preg_match('/^([\'"`])([^\'"`$]*)\1$/', $expr, $m) === 1) {
            return $m[2];
        }
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expr) === 1
            && preg_match('/\b(?:const|let|var)\s+' . preg_quote($expr, '/') . '\s*=\s*([\'"`])([^\'"`$]*)\1/', $config, $m) === 1
        ) {
            return $m[2];
        }

        return null;
    }

    /** Collapse `.` and `..`; null when the path is absolute or leaves the project. */
    private static function normalize(string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return null;
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return $out === [] ? null : implode('/', $out);
    }

    /** The text from the `{` at $open to its matching `}`. */
    private static function braceBlock(string $text, int $open): string
    {
        $depth = 0;
        $end = min(strlen($text), $open + 8000);
        for ($i = $open; $i < $end; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}' && --$depth === 0) {
                return substr($text, $open, $i - $open + 1);
            }
        }

        return substr($text, $open, $end - $open);
    }
}
