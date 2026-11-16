<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Go identity, from go.mod: no description, license or version (a tag is the version), but a module path that
 * names the repository, and `require` lines that carry exact versions.
 */
final class GoMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'go.mod';

    /**
     * Go web frameworks worth naming, most specific first.
     *
     * @var array<string, string>
     */
    private const FRAMEWORKS = [
        'github.com/gin-gonic/gin' => 'Gin',
        'github.com/labstack/echo/v4' => 'Echo',
        'github.com/labstack/echo' => 'Echo',
        'github.com/gofiber/fiber/v2' => 'Fiber',
        'github.com/gofiber/fiber' => 'Fiber',
        'github.com/go-chi/chi/v5' => 'Chi',
        'github.com/go-chi/chi' => 'Chi',
        'github.com/gorilla/mux' => 'Gorilla Mux',
        'github.com/beego/beego/v2' => 'Beego',
    ];

    public function id(): string
    {
        return 'go';
    }

    /** go.mod records the selected version, so constraints and versions are the same map. */
    public function read(ProjectContext $context): ?AppPackage
    {
        $gomod = $context->contents(self::FILE);
        if ($gomod === null) {
            return null;
        }

        $module = preg_match('/^module\s+(\S+)/m', $gomod, $matches) === 1 ? self::text($matches[1]) : null;
        $requires = self::requires($gomod);

        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: self::name($module, $context),
            nameSource: $module === null ? AppPackage::NAME_FROM_DIRECTORY : self::FILE.' module',
            repository: $module !== null && str_contains($module, '.') ? $module : null,
            frameworks: self::matchFrameworks(self::FRAMEWORKS, $requires, $requires, self::FILE, self::FILE),
            dependencyCounts: ['require' => count($requires)],
            platform: self::toolchain($gomod)
        );
    }

    /** The module path's last segment: `github.com/acme/shop` names the repository, `shop` the application. */
    private static function name(?string $module, ProjectContext $context): ?string
    {
        return $module === null ? self::directoryName($context) : basename($module);
    }

    /**
     * The `go` directive, a minimum the toolchain fetches since 1.21, and the one platform fact go.mod states.
     *
     * @return array<string, string>
     */
    private static function toolchain(string $gomod): array
    {
        if (preg_match('/^go\s+(\S+)/m', $gomod, $matches) !== 1) {
            return [];
        }
        $version = self::text($matches[1]);

        return $version === null ? [] : ['go' => $version];
    }

    /**
     * Module paths and their versions, from both `require` spellings; `// indirect` ones are skipped, as no line
     * of this project imports them.
     *
     * @return array<string, string>
     */
    private static function requires(string $gomod): array
    {
        $requires = [];

        if (preg_match_all('/^require\s*\((.*?)^\)/ms', $gomod, $blocks) !== false) {
            foreach ($blocks[1] ?? [] as $block) {
                foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                    self::addRequire($requires, $line);
                }
            }
        }

        if (preg_match_all('/^require\s+([^\s(].*)$/m', $gomod, $singles) !== false) {
            foreach ($singles[1] ?? [] as $line) {
                self::addRequire($requires, $line);
            }
        }

        return $requires;
    }

    /**
     * @param  array<string, string>  $requires
     */
    private static function addRequire(array &$requires, string $line): void
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '//') || str_contains($line, '// indirect')) {
            return;
        }
        if (preg_match('#^(\S+)\s+v(\S+)#', $line, $matches) !== 1) {
            return;
        }
        $requires[$matches[1]] = $matches[2];
    }
}
