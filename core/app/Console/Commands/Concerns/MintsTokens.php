<?php

namespace App\Console\Commands\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The `--expires` option, shared by the two commands that mint tokens.
 *
 * Sanctum's guard always honoured `expires_at`; nothing ever passed one, so
 * every token on every engine is immortal. This is the opt-in.
 */
trait MintsTokens
{
    /**
     * @return CarbonInterface|null|false the moment, null for never, or false
     *                                    when unreadable — a refusal, not "never"
     */
    protected function expiry(): CarbonInterface|null|false
    {
        $given = $this->option('expires');

        if ($given === null || $given === '' || $given === false) {
            return null;
        }

        if (!is_string($given) || preg_match('/^(\d+)\s*([smhdw])$/i', trim($given), $m) !== 1) {
            $this->error(sprintf(
                'Could not read --expires=%s. Give a number and a unit: 30m, 12h, 90d, 2w.',
                is_string($given) ? $given : gettype($given)
            ));

            return false;
        }

        $amount = (int) $m[1];

        if ($amount < 1) {
            $this->error('--expires has to be at least 1.');

            return false;
        }

        return Carbon::now()->add(match (strtolower($m[2])) {
            's' => 'seconds',
            'm' => 'minutes',
            'h' => 'hours',
            'w' => 'weeks',
            default => 'days',
        }, $amount);
    }

    /** How long the token lasts, for the line that says it was created. */
    protected function lifetime(?CarbonInterface $expiry): string
    {
        return $expiry === null
            ? 'never expires'
            : 'expires ' . $expiry->format('Y-m-d H:i');
    }
}
