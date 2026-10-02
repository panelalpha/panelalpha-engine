<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The SvelteKit adapter svelte.config imports, which decides what the build
 * writes: adapter-static an HTML tree, adapter-node `build/index.js`, the
 * platform adapters and adapter-auto nothing a server here can run.
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

    /**
     * `static`, `node`, `auto`, `cloudflare`, ... for `@sveltejs/adapter-<x>`,
     * or null when there is no config, no adapter import, or more than one
     * (a config choosing by environment).
     */
    public static function configured(ProjectContext $context): ?string
    {
        foreach (self::CONFIGS as $name) {
            $text = $context->contents($name);
            if ($text === null) {
                continue;
            }
            preg_match_all(
                '~(?:\bfrom|\bimport|\brequire\s*\()\s*[\'"]@sveltejs/adapter-([a-z0-9-]+)[\'"]~',
                $text,
                $m
            );
            $adapters = array_values(array_unique($m[1]));

            return count($adapters) === 1 ? $adapters[0] : null;
        }

        return null;
    }

    /** Why a project configured for $adapter cannot be served here, or null when it can. */
    public static function refusal(string $adapter): ?string
    {
        if (!array_key_exists($adapter, self::PLATFORM_ONLY)) {
            return null;
        }
        $platform = self::PLATFORM_ONLY[$adapter];
        $head = $platform === null
            ? 'svelte.config uses @sveltejs/adapter-auto, which writes no output outside Vercel, Netlify or Cloudflare.'
            : "svelte.config uses @sveltejs/adapter-{$adapter}, which builds for {$platform} only.";

        return $head . ' Switch it to @sveltejs/adapter-node (a Node server) or @sveltejs/adapter-static '
            . '(a static site) to deploy it here.';
    }
}
