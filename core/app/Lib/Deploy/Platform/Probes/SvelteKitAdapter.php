<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The SvelteKit adapter svelte.config imports (vite.config in SvelteKit 3), which decides what the build writes:
 * an HTML tree, `build/index.js`, or (platform adapters, adapter-auto) nothing a server here can run.
 */
final class SvelteKitAdapter
{
    /** Adapters whose output only their own platform can serve. */
    public const PLATFORM_ONLY = [
        'auto' => null,
        'cloudflare' => 'Cloudflare',
        'cloudflare-workers' => 'Cloudflare Workers',
        'vercel' => 'Vercel',
        'netlify' => 'Netlify',
    ];

    private const CONFIGS = ['svelte.config.js', 'svelte.config.mjs', 'svelte.config.ts', 'svelte.config.cjs'];

    /** Where SvelteKit 3 takes the adapter, passed to its Vite plugin, when svelte.config does not name one. */
    private const VITE_CONFIGS = [
        'vite.config.js', 'vite.config.mjs', 'vite.config.ts',
        'vite.config.cjs', 'vite.config.mts', 'vite.config.cts',
    ];

    /**
     * `static`, `node`, `auto`, ... for `@sveltejs/adapter-<x>`, or null when no config imports one,
     * or one imports more than one (a config choosing by environment).
     */
    public static function configured(ProjectContext $context): ?string
    {
        return self::configuredIn($context)['adapter'] ?? null;
    }

    /**
     * The adapter and the file importing it: svelte.config, else vite.config when svelte.config imports none.
     *
     * @return ?array{adapter: string, file: string}
     */
    public static function configuredIn(ProjectContext $context): ?array
    {
        foreach ([self::CONFIGS, self::VITE_CONFIGS] as $names) {
            [$file, $text] = self::firstConfig($context, $names);
            $adapters = self::importedAdapters($text);
            if (count($adapters) > 1) {
                return null;
            }
            if ($file !== null && $adapters !== []) {
                return ['adapter' => $adapters[0], 'file' => $file];
            }
        }

        return null;
    }

    /**
     * The first of $names the project has, with its text; [null, ''] when it has none.
     *
     * @param  list<string>  $names
     * @return array{0: ?string, 1: string}
     */
    private static function firstConfig(ProjectContext $context, array $names): array
    {
        foreach ($names as $name) {
            $text = $context->contents($name);
            if ($text !== null) {
                return [$name, $text];
            }
        }

        return [null, ''];
    }

    /** @return list<string> */
    private static function importedAdapters(string $text): array
    {
        preg_match_all(
            '~(?:\bfrom|\bimport|\brequire\s*\()\s*[\'"]@sveltejs/adapter-([a-z0-9-]+)[\'"]~',
            $text,
            $m
        );

        return array_values(array_unique($m[1]));
    }

    /** Why a project configured for $adapter cannot be served here, or null when it can. */
    public static function refusal(string $adapter, string $file = 'svelte.config'): ?string
    {
        if (! array_key_exists($adapter, self::PLATFORM_ONLY)) {
            return null;
        }
        $platform = self::PLATFORM_ONLY[$adapter];
        $head = $platform === null
            ? "{$file} uses @sveltejs/adapter-auto, which writes no output outside Vercel, Netlify or Cloudflare."
            : "{$file} uses @sveltejs/adapter-{$adapter}, which builds for {$platform} only.";

        return $head.' Switch it to @sveltejs/adapter-node (a Node server) or @sveltejs/adapter-static '
            .'(a static site) to deploy it here.';
    }
}
