<?php

namespace App\Exceptions;

use App\Integrations\Tunnels\PanelAlphaConnect;

/**
 * The engine names a tunnel itself -- a `preview-<8>` label under
 * panelalpha.online -- and every label it drew was already registered.
 * HTTP answers 409 with {@see self::CODE}, so a client can tell "try again
 * later" from a request it can correct.
 */
class TunnelHostnameUnavailableException extends \RuntimeException
{
    public const CODE = 'tunnel_hostname_unavailable';

    public function __construct(int $attempts)
    {
        parent::__construct(
            'Could not allocate a free preview hostname under *.' . PanelAlphaConnect::PARENT_DOMAIN
            . " after {$attempts} attempts.",
            409
        );
    }
}
