<?php

namespace App\Lib\Deploy\Platform;

/**
 * The strategy ids the deploy pipeline knows how to build and run.
 *
 * A fair question is why any of these are in PHP at all, when every platform
 * declares its own `strategy` in YAML. The answer is that they fall into two
 * groups, and only one of them is irreducible.
 *
 * **Dispatched** — {@see DeployStrategy::apply()} branches on these by name,
 * because "use the PHP writer" is not something a manifest can say: PAEMD,
 * COMPOSE, DOCKERFILE, LARAVEL, PHP, RAILS, RUBY, RAILPACK, STATIC, FALLBACK.
 * Ten ids, and each one exists because a distinct piece of code runs for it.
 *
 * **Grouped** — everything else reaches the one framework writer generically
 * via {@see isGenerated()}. Their individual constants earn their
 * place only in tests, which read better asserting `Strategies::NEXTJS` than
 * `'nextjs'`. Nothing in `app/` switches on them one by one; what it needs is
 * the group membership in {@see JS_FRAMEWORKS} and {@see LANGUAGES}.
 *
 * The lists stay hand-maintained rather than derived from the manifests. A
 * derived list would make the contract test assert that the manifests agree
 * with themselves; this way, a platform whose strategy is genuinely new has
 * to be admitted here, which is the moment to notice that the generators do
 * not handle it yet.
 */
final class Strategies
{
    // ---- Dispatched: apply() branches on these by name ----------------
    // Repository supplies its own build definition.
    public const PAEMD = 'paemd';

    public const COMPOSE = 'compose';

    public const DOCKERFILE = 'dockerfile';

    // Engine-generated recipes.
    public const LARAVEL = 'laravel';

    public const PHP = 'php';

    public const RAILS = 'rails';

    public const RUBY = 'ruby';

    public const DJANGO = 'django';

    public const PYTHON = 'python';

    public const GO = 'go';

    public const RUST = 'rust';

    public const JAVA = 'java';

    public const DOTNET = 'dotnet';

    // ---- Grouped: handled generically; named here for readable tests ---
    // JS frameworks.
    public const NEXTJS = 'nextjs';

    public const NUXT = 'nuxt';

    public const SVELTEKIT = 'sveltekit';

    public const REMIX = 'remix';

    public const ASTRO = 'astro';

    public const NESTJS = 'nestjs';

    public const TANSTACK = 'tanstack-start';

    public const ANGULAR = 'angular';

    public const CRA = 'cra';

    public const VITE = 'vite';

    public const EXPRESS = 'express';

    public const FASTIFY = 'fastify';

    /**
     * A package.json with no framework in it. Grouped with the JS frameworks
     * because the build is the same shape -- install, an optional build
     * script, start -- and it is the runtime that differs from a language
     * strategy, not the recipe.
     */
    public const NODE = 'node';

    // Terminal outcomes; no manifest is required to produce them.
    public const RAILPACK = 'railpack';

    public const STATIC = 'static';

    public const FALLBACK = 'fallback';

    /**
     * Strategies whose image the engine builds from a JS project — the set
     * that used to be `Strategies::JS_FRAMEWORKS`.
     *
     * @var list<string>
     */
    public const JS_FRAMEWORKS = [
        self::NEXTJS,
        self::NUXT,
        self::SVELTEKIT,
        self::REMIX,
        self::ASTRO,
        self::NESTJS,
        self::TANSTACK,
        self::ANGULAR,
        self::CRA,
        self::VITE,
        self::EXPRESS,
        self::FASTIFY,
        self::NODE,
    ];

    /**
     * Strategies built from a language toolchain rather than a JS bundler.
     *
     * @var list<string>
     */
    public const LANGUAGES = [
        self::PYTHON,
        self::DJANGO,
        self::GO,
        self::RUST,
        self::JAVA,
        self::DOTNET,
    ];

    /** @var list<string> */
    public const ALL = [
        self::PAEMD, self::COMPOSE, self::DOCKERFILE,
        self::LARAVEL, self::PHP, self::RAILS, self::RUBY,
        self::DJANGO, self::PYTHON, self::GO, self::RUST, self::JAVA, self::DOTNET,
        self::NEXTJS, self::NUXT, self::SVELTEKIT, self::REMIX, self::ASTRO,
        self::NESTJS, self::TANSTACK, self::ANGULAR, self::CRA, self::VITE,
        self::EXPRESS, self::FASTIFY, self::NODE,
        self::RAILPACK, self::STATIC, self::FALLBACK,
    ];

    public static function isJsFramework(string $strategy): bool
    {
        return in_array($strategy, self::JS_FRAMEWORKS, true);
    }

    public static function isLanguage(string $strategy): bool
    {
        return in_array($strategy, self::LANGUAGES, true);
    }

    /** Handled by a generator rather than by the repository's own files. */
    public static function isGenerated(string $strategy): bool
    {
        return self::isJsFramework($strategy) || self::isLanguage($strategy);
    }

    public static function isKnown(string $strategy): bool
    {
        return in_array($strategy, self::ALL, true);
    }
}
