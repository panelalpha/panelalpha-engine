<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * One toolchain the engine can put in an image, named under a manifest's `requires`; it reads the
 * version from the project (go.mod, `engines.node`, `require.php`) and answers with a `Requirement`.
 */
interface Runtime
{
    /** Name a manifest references under `requires`, e.g. "php". */
    public function id(): string;

    /**
     * What this project needs of this toolchain, or null when it does not
     * use it at all.
     */
    public function resolve(ProjectContext $context): ?Requirement;

    /**
     * This toolchain at its default version, for callers with no project to
     * read — the prewarmer knows the platform but not the account.
     */
    public function defaultRequirement(): Requirement;

    /**
     * The versions the prewarmer puts on the host before anyone asks; not a limit on what
     * `resolve()` may answer, since anything else is acquired on demand.
     *
     * @return list<string> versions in the spelling of `Requirement::$version`
     */
    public function supportedVersions(): array;

    /**
     * The image providing this requirement on its own; combining runtimes into one image is
     * `ImageResolver`'s decision, not a runtime's.
     */
    public function image(Requirement $requirement): string;
}
