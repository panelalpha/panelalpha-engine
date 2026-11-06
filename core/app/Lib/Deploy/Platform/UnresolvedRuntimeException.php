<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Findings\Finding;
use App\Lib\Findings\FindingException;

/** A recipe needs a runtime the project's own files do not say the version of, such as PHP with no composer.json. */
final class UnresolvedRuntimeException extends ManifestException implements FindingException
{
    public const CODE = 'runtime_unresolved';

    public function __construct(public readonly string $runtime)
    {
        parent::__construct("Runtime '{$runtime}' is required but could not be resolved for this project");
    }

    public function finding(): Finding
    {
        return new Finding(self::CODE, $this->getMessage(), ['runtime' => $this->runtime]);
    }
}
