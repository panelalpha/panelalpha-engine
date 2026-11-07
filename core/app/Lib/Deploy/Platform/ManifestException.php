<?php

namespace App\Lib\Deploy\Platform;

/**
 * A platform manifest is malformed. Thrown at load time, never at detect time, so a packaging
 * bug surfaces on the first request rather than on the one deploy that reaches it.
 */
class ManifestException extends \RuntimeException {}
