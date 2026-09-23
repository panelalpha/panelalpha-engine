<?php

namespace App\Console\Wizard\Sections;

use App\Auth\TokenAbilities;
use App\Console\Wizard\Scopes\RouteScope;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Console\Wizard\TokenManager;

/**
 * Tokens for the operator's own software, and which of the engine's REST
 * operations each may call.
 *
 * Whole thing is one {@see TokenManager} over a {@see RouteScope}: this
 * section has no settings of its own, only tokens.
 */
class ApiTokensSection implements Section
{
    use KeepsAReceipt;

    public static function key(): string
    {
        return 'api-tokens';
    }

    public static function label(): string
    {
        return 'API tokens — for your own software';
    }

    public static function hint(): string
    {
        return 'Mint, limit to some of the API, revoke and delete.';
    }

    public function run(bool $dryRun): int
    {
        $manager = new TokenManager(
            title: self::label(),
            dryRun: $dryRun,
            ability: TokenAbilities::API,
            scope: new RouteScope(),
            noun: 'your own software',
            narrow: 'Change which parts of the API it may call…',
            mintHint: 'Send it as `Authorization: Bearer <token>`. The API is documented at /api/documentation.',
        );

        $code = $manager->run();
        $this->receipt = $manager->receipt();

        return $code;
    }
}
