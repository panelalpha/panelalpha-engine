<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;

/**
 * Where `vite build` writes, without running it: the build script's `--outDir`, else the config's `build.outDir`, else
 * `dist`, resolved against `root` as Vite does (kresus: `build/client`); the config is the one the build script's
 * `--config` names, else Vite's own; the default when it is not a literal.
 */
final class ViteOutputDir
{
    /** Vite's own lookup order. */
    private const CONFIGS = [
        'vite.config.js', 'vite.config.mjs', 'vite.config.ts',
        'vite.config.cjs', 'vite.config.mts', 'vite.config.cts',
    ];

    /** The working directory, which Vite resolves `root` and `resolve()` against. */
    private const CWD = '(?:process\.cwd\(\))';

    /** The directory the config file is in. */
    private const CONFIG_DIR = '(?:__dirname|import\.meta\.dirname)';

    /** A call (two levels of nested parentheses), a string literal, or a (dotted) name. */
    private const EXPR = '((?:[\w.$]+\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))|([\'"`])[^\'"`]*\2|[\w.$]+)';

    /** `vite build`, up to the end of its command. */
    private const VITE_BUILD = '/\bvite\s+build\b[^&|;]*/';

    private const OPTION_VALUE = '(?:=|\s+)([\'"]?)([^\s\'"&|;]+)\1';

    public static function outputDir(string $projectDir, string $default = 'dist'): string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '') {
            return $default;
        }

        $command = self::viteBuildCommand($projectDir);
        $configFile = self::configFile($projectDir, self::option($command, '--config|-c'));
        $config = $configFile === null ? '' : (string) AccountHomeRead::contents($projectDir.'/'.$configFile);
        $configDir = $configFile === null ? '' : self::directoryOf($configFile);

        $dir = self::resolve($config, self::option($command, '--outDir'), $configDir, $default);

        return $dir === null ? $default : NodeRuntime::safeOutputDir($dir, $default);
    }

    /**
     * The output directory relative to the project, or null when the config does not say so in literals; without an
     * `outDir` it is $defaultOutDir under `root`, or null when that is not given either.
     */
    public static function resolve(
        string $config,
        ?string $cliOutDir = null,
        string $configDir = '',
        ?string $defaultOutDir = null
    ): ?string {
        [$build, $rest] = self::splitBuildBlock($config);
        $outDir = null;
        if (preg_match('/\boutDir\s*:\s*'.self::EXPR.'/', $build, $o) === 1) {
            $outDir = self::evaluate(trim($o[1]), $config, $configDir);
            if ($outDir === null) {
                return null;
            }
        }
        if ($cliOutDir !== null) {
            $outDir = [$cliOutDir, null];
        }
        $outDir ??= $defaultOutDir === null ? null : [$defaultOutDir, null];
        if ($outDir === null) {
            return null;
        }

        [$path, $base] = $outDir;
        $base ??= self::root($rest, $config, $configDir);

        return $base === null ? null : self::normalize(self::join($base, $path));
    }

    /**
     * The config's `build` block, and the config without it or Vitest's `test` block, where a `root` is not Vite's.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitBuildBlock(string $config): array
    {
        $build = ConfigBlock::of($config, 'build');
        $rest = str_replace([$build, ConfigBlock::of($config, 'test')], '', $config);

        return [$build, $rest];
    }

    /** The project-relative `root`: '' when the config sets none, null when it is not a literal. */
    private static function root(string $rest, string $config, string $configDir): ?string
    {
        if (preg_match('/\broot\s*:\s*'.self::EXPR.'/', $rest, $r) !== 1) {
            return '';
        }
        $root = self::evaluate(trim($r[1]), $config, $configDir);

        return $root === null ? null : self::join($root[1] ?? '', $root[0]);
    }

    /** The `vite build` command the build script runs, directly or through the scripts it runs by name. */
    private static function viteBuildCommand(string $projectDir): string
    {
        $context = ProjectContext::at($projectDir);
        foreach (self::buildScriptBodies($context->scripts(), $context->script('build')) as $body) {
            if (preg_match(self::VITE_BUILD, $body, $m) === 1) {
                return $m[0];
            }
        }

        return '';
    }

    /**
     * The build script, then each script it runs by `npm run x` / `pnpm x` / `yarn x`, breadth first.
     *
     * @param  array<string, mixed>  $scripts
     * @return list<string>
     */
    private static function buildScriptBodies(array $scripts, string $build): array
    {
        $bodies = [];
        $pending = [$build];
        $seen = ['build' => true];
        while ($pending !== []) {
            $body = (string) array_shift($pending);
            $bodies[] = $body;
            preg_match_all('/\b(?:npm\s+run(?:-script)?|(?:pnpm|yarn|bun)(?:\s+run)?)\s+([\w:.-]+)/', $body, $m);
            foreach ($m[1] as $name) {
                if (! isset($seen[$name]) && is_string($scripts[$name] ?? null)) {
                    $seen[$name] = true;
                    $pending[] = $scripts[$name];
                }
            }
        }

        return $bodies;
    }

    /** The value of the first of `$names` (`--outDir x`, `--outDir=x`) on $command. */
    private static function option(string $command, string $names): ?string
    {
        if (preg_match('/(?:^|\s)(?:'.$names.')'.self::OPTION_VALUE.'/', $command, $m) === 1) {
            return $m[2];
        }

        return null;
    }

    /** The config Vite reads: the one `--config` names, inside the project, else the first of Vite's own. */
    private static function configFile(string $projectDir, ?string $named): ?string
    {
        if ($named !== null) {
            $named = self::normalize($named);

            return $named !== null && is_file($projectDir.'/'.$named) ? $named : null;
        }
        foreach (self::CONFIGS as $name) {
            if (is_file($projectDir.'/'.$name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A config expression as `[path, base]`: a literal (base null, relative to `root`), the config's directory or a
     * path under it, `fileURLToPath(new URL('./x', import.meta.url))`, or `(path.)resolve/join(...)`; a base is the
     * project-relative directory the path is relative to.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function evaluate(string $expr, string $config, string $configDir): ?array
    {
        $expr = rtrim($expr, " \t,");
        $literal = self::literal($expr, $config);
        if ($literal !== null) {
            return [$literal, null];
        }
        $base = self::base($expr, $config, $configDir);
        if ($base !== null) {
            return ['', $base];
        }

        if (preg_match('/^fileURLToPath\(\s*new\s+URL\(\s*([\'"`])([^\'"`$]+)\1\s*,\s*import\.meta\.url\s*\)\s*\)$/', $expr, $m) === 1) {
            return [$m[2], $configDir];
        }

        return self::pathCall($expr, $config, $configDir);
    }

    /**
     * `resolve(...)`, `path.resolve(...)`, `join(...)` or `path.join(...)` of literals, optionally from a base.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function pathCall(string $expr, string $config, string $configDir): ?array
    {
        if (preg_match('/^(?:path\.)?(resolve|join)\(\s*(.*?)\s*\)$/s', $expr, $m) !== 1) {
            return null;
        }
        $args = array_map('trim', explode(',', $m[2]));
        $base = $m[1] === 'resolve' ? '' : null;
        $parts = [];
        foreach ($args as $i => $arg) {
            $argBase = $i === 0 ? self::base($arg, $config, $configDir) : null;
            if ($argBase !== null) {
                $base = $argBase;

                continue;
            }
            $value = self::literal($arg, $config);
            if ($value === null) {
                return null;
            }
            $parts[] = $value;
        }

        return [implode('/', $parts), $base];
    }

    /**
     * The project-relative directory `process.cwd()`, `__dirname` or `import.meta.dirname` stands for, also through a
     * name declared once as one of them; null for anything else.
     */
    private static function base(string $expr, string $config, string $configDir): ?string
    {
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expr) === 1
            && preg_match('/\b(?:const|let|var)\s+'.preg_quote($expr, '/').'\s*=\s*('.self::CWD.'|'.self::CONFIG_DIR.')\s*[;\n]/', $config, $m) === 1
        ) {
            $expr = $m[1];
        }

        return match (true) {
            preg_match('/^'.self::CWD.'$/', $expr) === 1 => '',
            preg_match('/^'.self::CONFIG_DIR.'$/', $expr) === 1 => $configDir,
            default => null,
        };
    }

    /** A string literal, or an identifier declared once as one. */
    private static function literal(string $expr, string $config): ?string
    {
        if (preg_match('/^([\'"`])([^\'"`$]*)\1$/', $expr, $m) === 1) {
            return $m[2];
        }
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expr) === 1
            && preg_match('/\b(?:const|let|var)\s+'.preg_quote($expr, '/').'\s*=\s*([\'"`])([^\'"`$]*)\1/', $config, $m) === 1
        ) {
            return $m[2];
        }

        return null;
    }

    private static function join(string $base, string $path): string
    {
        return $base === '' ? $path : $base.'/'.$path;
    }

    private static function directoryOf(string $file): string
    {
        $dir = dirname($file);

        return $dir === '.' ? '' : $dir;
    }

    /** Collapse `.` and `..`; null when the path is absolute, leaves the project or is the project itself. */
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
}
