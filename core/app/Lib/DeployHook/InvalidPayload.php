<?php

namespace App\Lib\DeployHook;

use RuntimeException;

/**
 * A delivery that carried a valid signature but a body the provider's own
 * format does not allow. The sender is authentic and the request is still
 * useless, so it is rejected rather than ignored.
 */
final class InvalidPayload extends RuntimeException
{
}
