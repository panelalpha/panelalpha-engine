<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One time the outbound limits stopped a project or a build: a scan block, a flood or an SMTP attempt.
 *
 * @property int $id
 * @property string $owner
 * @property string $network
 * @property string $kind
 * @property string $address
 * @property Carbon $started_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $until_at
 * @property Carbon|null $lifted_at
 * @property string|null $lifted_by
 */
class OutboundEvent extends Model
{
    public const KIND_SCAN = 'scan';

    public const KIND_FLOOD = 'flood';

    public const KIND_SMTP = 'smtp';

    public const NETWORK_TENANTS = 'tenants';

    public const NETWORK_BUILD = 'build';

    /** A mark not seen again for this long has ended; the broker's own episode length. */
    public const QUIET_SECONDS = 600;

    protected $fillable = ['owner', 'network', 'kind', 'address', 'started_at', 'last_seen_at', 'until_at', 'lifted_at', 'lifted_by'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'until_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function isBlock(): bool
    {
        return $this->until_at !== null;
    }

    public function isActive(Carbon $now): bool
    {
        if ($this->lifted_at !== null) {
            return false;
        }

        return $this->isBlock()
            ? $this->until_at->greaterThan($now)
            : $this->last_seen_at->greaterThan($now->copy()->subSeconds(self::QUIET_SECONDS));
    }

    /** @param  Builder<self>  $query */
    public function scopeSince(Builder $query, Carbon $since): void
    {
        $query->where('last_seen_at', '>=', $since)->orWhere('until_at', '>=', $since);
    }
}
