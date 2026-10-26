<?php

namespace App\Lib\Deploy\Platform;

/**
 * A platform manifest is malformed.
 *
 * Thrown at load time, never at detect time: a manifest ships with the
 * engine, so a broken one is a packaging bug that should surface on the
 * first request rather than on the one deploy that happens to reach it.
 */
class ManifestException extends \RuntimeException {}
