<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Rust identity, from Cargo.toml's `[package]`; a workspace root has none and reports its members instead.
 * Versions come from the manifest, not Cargo.lock, whose array of tables a pattern cannot read safely.
 */
final class CargoMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'Cargo.toml';

    /**
     * Rust web frameworks worth naming, most specific first.
     *
     * @var array<string, string>
     */
    private const FRAMEWORKS = [
        'axum' => 'Axum',
        'actix-web' => 'Actix Web',
        'rocket' => 'Rocket',
        'poem' => 'Poem',
        'salvo' => 'Salvo',
        'warp' => 'Warp',
        'tide' => 'Tide',
    ];

    public function id(): string
    {
        return 'rust';
    }

    public function read(ProjectContext $context): ?AppPackage
    {
        $toml = $context->contents(self::FILE);
        if ($toml === null) {
            return null;
        }

        $package = self::tomlSection($toml, 'package');
        $workspace = self::tomlSection($toml, 'workspace');
        if ($package === null && $workspace === null) {
            return null;
        }

        return $this->package($context, $toml, $package, $workspace);
    }

    private function package(ProjectContext $context, string $toml, ?string $package, ?string $workspace): AppPackage
    {
        $name = $package === null ? null : self::tomlString($package, 'name');
        $dependencies = self::tomlSection($toml, 'dependencies') ?? '';

        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: $name ?? self::directoryName($context),
            nameSource: $name !== null ? self::FILE.' package.name' : AppPackage::NAME_FROM_DIRECTORY,
            description: $package === null ? null : self::tomlString($package, 'description'),
            version: $package === null ? null : self::tomlString($package, 'version'),
            license: $package === null ? null : self::tomlString($package, 'license'),
            homepage: $package === null ? null : self::tomlString($package, 'homepage'),
            repository: $package === null ? null : self::tomlString($package, 'repository'),
            authors: $package === null ? [] : self::tomlList($package, 'authors'),
            keywords: $package === null ? [] : self::tomlList($package, 'keywords'),
            workspaces: $workspace === null ? [] : self::tomlList($workspace, 'members'),
            frameworks: self::matchFrameworks(self::FRAMEWORKS, self::dependencyConstraints($dependencies), [], self::FILE),
            platform: self::minimumRustVersion($package)
        );
    }

    /**
     * `rust-version`, the MSRV: the only platform fact the manifest states, as the edition is a dialect.
     *
     * @return array<string, string>
     */
    private static function minimumRustVersion(?string $package): array
    {
        return $package === null || ($msrv = self::tomlString($package, 'rust-version')) === null
            ? []
            : ['rust' => $msrv];
    }

    /**
     * `[dependencies]` in both spellings, `axum = "0.7"` and `axum = { version = "0.7" }`; a crate from a git
     * revision has no version and is kept with `*`.
     *
     * @return array<string, string>
     */
    private static function dependencyConstraints(string $dependencies): array
    {
        if ($dependencies === '') {
            return [];
        }

        $constraints = [];
        if (preg_match_all('/^[ \t]*([A-Za-z0-9._-]+)[ \t]*=[ \t]*(.*)$/m', $dependencies, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $match) {
            $value = trim($match[2]);
            if (str_starts_with($value, '{')) {
                $value = preg_match('/version[ \t]*=[ \t]*["\'](.*?)["\']/', $value, $version) === 1
                    ? $version[1]
                    : '*';
            }
            $constraints[$match[1]] = self::text(trim($value, " \t\"'")) ?? '*';
        }

        return $constraints;
    }
}
