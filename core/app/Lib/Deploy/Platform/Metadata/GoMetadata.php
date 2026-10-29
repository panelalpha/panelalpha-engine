<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Go identity, from go.mod's module path.
 *
 * The thinnest ecosystem here, and deliberately so: Go has no description, no
 * license field and no version — a module's version is a git tag, which is why
 * `go.mod` never mentions one. What it does have is a module path that is
 * usually a repository URL, so the name is real and the repository comes free.
 *
 * The compensation is that dependency versions are exact and cost nothing:
 * `require` lines carry the resolved version already, so a Go project reports
 * `Gin 1.9.1` where a Python one can only report a range.
 *
 * No Laravel dependencies — unit-testable with a temp directory.
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
            // The last segment, because `github.com/acme/shop` names the
            // repository and `shop` names the application.
            name: $module === null ? self::directoryName($context) : basename($module),
            nameSource: $module === null ? AppPackage::NAME_FROM_DIRECTORY : self::FILE.' module',
            repository: $module !== null && str_contains($module, '.') ? $module : null,
            // Constraints and versions are the same map: go.mod records the
            // selected version, so there is no range to report separately.
            frameworks: self::matchFrameworks(self::FRAMEWORKS, $requires, $requires, self::FILE, self::FILE),
            dependencyCounts: ['require' => count($requires)],
            // The `go` directive is a minimum, and since 1.21 the toolchain
            // treats it as one it will go and fetch. Either way it is the one
            // platform fact go.mod states.
            platform: self::toolchain($gomod)
        );
    }

    /**
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
     * Module paths and their versions, from both `require` spellings.
     *
     * Indirect dependencies are skipped: `// indirect` marks something no line
     * of this project imports, and reporting one as the project's framework
     * would be actively misleading.
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
