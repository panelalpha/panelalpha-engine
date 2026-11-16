<?php

namespace App\Lib\DeployHook;

use RuntimeException;

/**
 * A delivery with a valid signature but a body the provider's format does not allow: authentic and still
 * useless, so rejected rather than ignored.
 */
final class InvalidPayload extends RuntimeException {}
