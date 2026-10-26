<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;

/**
 * A single-page app whose `start` is only a bundler's dev server, or which has
 * a `build` script and no `start`: what ships is the `build` output, served
 * statically. `start: webpack serve` restart-loops in production.
 */
final class BundlerSpaProbe implements PlatformProbe
{
    // Anchored, so a command that also runs an entry file does not match.
    private const DEV_SERVER = '/^(?:npx\s+|pnpm\s+(?:exec\s+)?|yarn\s+)?'
        .'(?:webpack(?:-cli)?\s+serve|webpack-dev-server|vue-cli-service\s+serve|ng\s+serve'
        .'|parcel(?!\s+build\b)|vite(?!\s+build\b))(?:\s[^&|;]*)?$/';

    // `npm install && webpack serve` is still only the dev server.
    private const INSTALL_PREFIX = '/^(?:(?:npm\s+(?:install|i|ci)|pnpm\s+(?:install|i)|yarn(?:\s+install)?)'
        .'(?:\s+-[^&|;]*)?\s*&&\s*)+/';

    private const ENV_PREFIX = '/^(?:cross-env\s+)?(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)*/';

    private const SCRIPT_CALL = '/^(?:npm\s+run|pnpm(?:\s+run)?|yarn(?:\s+run)?)\s+([\w:.-]+)$/';

    // Any of these means the project has a real server; leave it to Node.
    private const SERVER_DEPS = [
        'express', 'fastify', 'koa', '@hapi/hapi', 'hapi', 'restify', 'polka', 'micro',
        '@nestjs/core', 'next', 'nuxt', 'hono', 'h3', 'sails', '@adonisjs/core',
        '@feathersjs/feathers', '@remix-run/serve', 'socket.io',
    ];

    public function id(): string
    {
        return 'bundler-spa';
    }

    public function evaluate(ProjectContext $context): bool
    {
        if ($context->script('build') === '' || $context->hasFile('server.js')) {
            return false;
        }
        foreach (self::SERVER_DEPS as $dep) {
            if ($context->hasDep($dep)) {
                return false;
            }
        }

        $start = $context->script('start');

        if (trim($start) !== '') {
            return self::isDevServerOnly($context, $start);
        }

        // No start script: a bundler-build SPA (kiwiirc) has a front page, a
        // compiled TypeScript CLI (bubo-rss) does not. `public/index.html`
        // needs isFile() -- hasFile() reads the root listing by basename.
        return $context->hasFile('index.html') || $context->isFile('public/index.html');
    }

    /** True when $command, following `npm run x` a few levels, only starts a dev server. */
    public static function isDevServerOnly(ProjectContext $context, string $command, int $depth = 0): bool
    {
        $command = trim((string) preg_replace(self::INSTALL_PREFIX, '', trim($command)));
        $command = trim((string) preg_replace(self::ENV_PREFIX, '', $command));
        if ($command === '') {
            return false;
        }
        if ($depth < 3 && preg_match(self::SCRIPT_CALL, $command, $m) === 1 && $context->script($m[1]) !== '') {
            return self::isDevServerOnly($context, $context->script($m[1]), $depth + 1);
        }

        return preg_match(self::DEV_SERVER, $command) === 1;
    }

    /**
     * Where webpack writes, read from webpack.config.* without running it.
     * $default when there is no config or its output path is not a literal.
     */
    public static function outputDir(string $projectDir, string $default = 'dist'): string
    {
        foreach (['webpack.config.js', 'webpack.config.cjs', 'webpack.config.mjs'] as $name) {
            $path = rtrim($projectDir, '/').'/'.$name;
            if (! is_file($path)) {
                continue;
            }
            $dir = self::webpackOutputPath((string) @file_get_contents($path));
            if ($dir !== null) {
                return NodeRuntime::safeOutputDir($dir, $default);
            }
        }

        return $default;
    }

    private static function webpackOutputPath(string $config): ?string
    {
        // Only inside `output: {...}`: `context` and `devServer.static` are paths too.
        if (preg_match('/\boutput\s*:\s*\{/', $config, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $block = self::braceBlock($config, $m[0][1] + strlen($m[0][0]) - 1);
        $base = '(?:__dirname|import\.meta\.dirname|process\.cwd\(\))';

        if (preg_match('/\bpath\s*:\s*(?:path\.)?(?:resolve|join)\(\s*'.$base.'\s*,([^()]*)\)/', $block, $m) === 1
            && preg_match('/^\s*(?:[\'"`][^\'"`$]*[\'"`]\s*,?\s*)+$/', $m[1]) === 1
        ) {
            preg_match_all('/[\'"`]([^\'"`$]*)[\'"`]/', $m[1], $parts);

            return self::relative(implode('/', $parts[1]));
        }
        if (preg_match('/\bpath\s*:\s*'.$base.'\s*\+\s*[\'"`]([^\'"`$]+)[\'"`]/', $block, $m) === 1) {
            return self::relative($m[1]);
        }
        if (preg_match('/\bpath\s*:\s*[\'"`]([^\'"`$]+)[\'"`]/', $block, $m) === 1
            && ! str_starts_with($m[1], '/')
        ) {
            return self::relative($m[1]);
        }

        return null;
    }

    /** The text from the `{` at $open to its matching `}`; strings are not special-cased. */
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

    private static function relative(string $dir): ?string
    {
        $dir = (string) preg_replace('#/+#', '/', trim($dir));
        $dir = rtrim((string) preg_replace('#^(?:/|\./)+#', '', $dir), '/');

        return $dir === '' || $dir === '.' ? null : $dir;
    }
}
