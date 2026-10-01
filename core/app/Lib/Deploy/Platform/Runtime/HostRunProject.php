<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Applications served the way PHP already is: our stock image, the account's
 * directory built on the host and bind-mounted at /app, nothing in an image.
 * The mount is read-write because Next.js writes `.next/cache` at runtime.
 */
final class HostRunProject
{
    /**
     * The build leaves its output in the project and the start command runs from
     * the mounted directory. `nuxt`/`tanstack-start` stay on StandaloneNodeServe,
     * nginx recipes host-compile to static files, own-Dockerfile repos build.
     *
     * @var list<string>
     */
    public const STRATEGIES = [
        // Node: output stays in the project; start is the package.json script.
        'nextjs',
        'nestjs',
        'astro',
        'express',
        'fastify',
        'remix',
        'sveltekit',
        // Python: the recipe builds a .venv in the project and starts through
        // it, since pip targets the image's site-packages.
        'python',
        'django',
        // Go, Rust and Java compile into the project (./app,
        // ./target/release/app, target/*.jar) and only need the resolved image
        // provided, which AppLauncher and ImageSeeding do.
        'go',
        'java',
        'java-gradle',
        // Rust: `cargo build --release` writes into the project too.
        'rust',
        // Ruby is wired (BUNDLE_PATH puts gems in ~/project/vendor/bundle) but
        // unmeasured: the Rack fixture pins ruby "3.1.3" against a supported
        // set of 3.2-3.4. Adding 'ruby' here turns it on.
    ];

    /** Strategies whose interpreter is chosen from a lockfile. */
    private const NODE_STRATEGIES = ['nextjs', 'nestjs', 'astro', 'express', 'fastify', 'remix', 'sveltekit'];

    /**
     * The host compile picks its container image from the lockfile for these
     * (a tree installed by bun and imported by node fails) and from the
     * recipe's own `image` for everything else.
     */
    public static function isNode(?string $strategy): bool
    {
        return $strategy !== null && in_array($strategy, self::NODE_STRATEGIES, true);
    }

    public static function isStrategy(?string $strategy): bool
    {
        return $strategy !== null && in_array($strategy, self::STRATEGIES, true);
    }

    /**
     * Shell prefix that gives the run container the package manager the host
     * build provisioned. pnpm and Yarn 2+ exist there only through corepack,
     * and the run image is plain Node, so `pnpm start` exited 127. Empty for
     * npm, Yarn 1 (shipped in the image) and every non-Node strategy.
     *
     * @param array<string, mixed> $decision
     */
    public static function packageManagerPrefix(array $decision): string
    {
        $strategy = is_string($decision['strategy'] ?? null) ? $decision['strategy'] : null;
        $pm = $decision['package_manager'] ?? '';
        $install = (string) ($decision['install_command'] ?? '');
        $berry = $pm === 'yarn' && str_contains($install, '--immutable');
        if (!self::isNode($strategy) || ($pm !== 'pnpm' && !$berry)) {
            return '';
        }
        $prepare = HostNodeBuild::provisioning($install);
        if ($prepare === '') {
            return '';
        }

        return 'export COREPACK_HOME=/tmp/corepack COREPACK_ENABLE_DOWNLOAD_PROMPT=0 npm_config_cache=/tmp/npm-cache'
            . ' PATH=' . HostNodeBuild::PROVISIONED_PATH . ':$PATH && ' . $prepare . ' && ';
    }

    /**
     * The recipe's start command: the manifest resolves `{{js.start:…}}`
     * against the project's `package.json` scripts, else the framework's own
     * default.
     *
     * @param array<string, mixed> $decision
     */
    public static function startCommand(array $decision): ?string
    {
        $command = trim((string) ($decision['start_command'] ?? ''));

        return $command === '' ? null : $command;
    }
}
