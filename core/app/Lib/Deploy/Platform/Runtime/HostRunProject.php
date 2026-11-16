<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Applications served like PHP: our stock image with the host-built account directory bind-mounted
 * read-write at /app (Next.js writes `.next/cache` at runtime), nothing in an image.
 */
final class HostRunProject
{
    /**
     * Build output stays in the mounted project; go, rust and java only need the resolved image, which
     * AppLauncher and ImageSeeding provide. Ruby stays off: the Rack fixture pins 3.1.3 against 3.2-3.4.
     *
     * @var list<string>
     */
    public const STRATEGIES = [
        'nextjs',
        'nestjs',
        'astro',
        'express',
        'fastify',
        'remix',
        'sveltekit',
        'python',
        'django',
        'go',
        'java',
        'java-gradle',
        'rust',
    ];

    /** Strategies whose interpreter is chosen from a lockfile. */
    private const NODE_STRATEGIES = ['nextjs', 'nestjs', 'astro', 'express', 'fastify', 'remix', 'sveltekit'];

    /**
     * The host compile picks its image from the lockfile for these, since a tree installed by bun
     * and imported by node fails, and from the recipe's own `image` otherwise.
     */
    public static function isNode(?string $strategy): bool
    {
        return $strategy !== null && in_array($strategy, self::NODE_STRATEGIES, true);
    }

    /** nuxt and tanstack-start are left out: they stay on {@see StandaloneNodeServe}. */
    public static function isStrategy(?string $strategy): bool
    {
        return $strategy !== null && in_array($strategy, self::STRATEGIES, true);
    }

    /**
     * Shell prefix giving the plain-Node run container the corepack pnpm or Yarn 2+ the host build provisioned without
     * pinning it in the checkout's package.json; empty for npm, Yarn 1 and every non-Node strategy.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function packageManagerPrefix(array $decision): string
    {
        $strategy = is_string($decision['strategy'] ?? null) ? $decision['strategy'] : null;
        $pm = $decision['package_manager'] ?? '';
        $install = (string) ($decision['install_command'] ?? '');
        $berry = $pm === 'yarn' && str_contains($install, '--immutable');
        if (! self::isNode($strategy) || ($pm !== 'pnpm' && ! $berry)) {
            return '';
        }
        $prepare = HostNodeBuild::provisioning($install);
        if ($prepare === '') {
            return '';
        }

        return 'export COREPACK_HOME=/tmp/corepack COREPACK_ENABLE_DOWNLOAD_PROMPT=0 COREPACK_ENABLE_AUTO_PIN=0'
            .' npm_config_cache=/tmp/npm-cache'
            .' PATH='.HostNodeBuild::PROVISIONED_PATH.':$PATH && '.$prepare.' && ';
    }

    /**
     * The recipe's start command, `{{js.start:…}}` resolved against the project's `package.json`
     * scripts, else the framework's own default.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function startCommand(array $decision): ?string
    {
        $command = trim((string) ($decision['start_command'] ?? ''));

        return $command === '' ? null : $command;
    }
}
