<?php

namespace App\Lib\Deploy\Platform;

/**
 * The strategy ids the pipeline builds and runs, dispatched by name in {@see DeployStrategy::apply()}
 * or grouped for {@see isGenerated()}; hand-listed so a genuinely new one has to be admitted here.
 */
final class Strategies
{
    /**
     * Dispatched by name in {@see DeployStrategy::apply()}, with COMPOSE and DOCKERFILE: the repository
     * supplies its own build definition.
     */
    public const PAEMD = 'paemd';

    public const COMPOSE = 'compose';

    public const DOCKERFILE = 'dockerfile';

    /** Dispatched by name, with PHP, RAILS and RUBY: each has its own engine-generated recipe writer. */
    public const LARAVEL = 'laravel';

    public const PHP = 'php';

    public const RAILS = 'rails';

    public const RUBY = 'ruby';

    /** Grouped from here to NODE: reached generically via {@see isGenerated()}, named for readable tests. */
    public const DJANGO = 'django';

    public const PYTHON = 'python';

    public const GO = 'go';

    public const RUST = 'rust';

    public const JAVA = 'java';

    public const DOTNET = 'dotnet';

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
     * A package.json with no framework in it, grouped with the JS frameworks because the build
     * has the same shape: install, an optional build script, start.
     */
    public const NODE = 'node';

    /** Dispatched by name, with STATIC and FALLBACK: terminal outcomes no manifest is required to produce. */
    public const RAILPACK = 'railpack';

    public const STATIC = 'static';

    public const FALLBACK = 'fallback';

    /**
     * Strategies whose image the engine builds from a JS project.
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
