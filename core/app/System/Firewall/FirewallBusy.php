<?php

namespace App\System\Firewall;

/**
 * Another writer held the firewall's lock for too long; nothing was changed.
 * Retryable, so the API answers 409 rather than an invalid rule's 422.
 */
class FirewallBusy extends FirewallException {}
