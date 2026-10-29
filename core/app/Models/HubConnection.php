<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * This engine's connection to a PanelAlpha Connect account. There is at most
 * one: a new connection replaces it, a disconnect deletes it.
 *
 * @property int $id
 * @property ?string $connection_id
 * @property string $hub_url
 * @property ?string $hub_secret
 * @property string $account_id
 * @property ?string $account_name
 * @property ?string $approved_by
 * @property ?string $license_id
 * @property ?string $license_name
 * @property string $ip
 * @property ?array<string, string> $caller_ips where PanelAlpha Connect calls this engine from: address => last seen (UTC, ISO 8601)
 * @property string $tls_spki
 * @property ?array<int, string> $grants
 * @property ?int $token_id
 * @property string $status
 * @property ?Carbon $connected_at
 * @property ?Carbon $last_hub_call_at
 */
class HubConnection extends Model
{
    public const STATUS_CONNECTED = 'connected';

    protected $fillable = [
        'connection_id',
        'hub_url',
        'hub_secret',
        'account_id',
        'account_name',
        'approved_by',
        'license_id',
        'license_name',
        'ip',
        'tls_spki',
        'grants',
        'token_id',
        'status',
        'connected_at',
    ];

    protected $hidden = ['hub_secret'];

    protected $casts = [
        'hub_secret' => 'encrypted',
        'grants' => 'array',
        'caller_ips' => 'array',
        'connected_at' => 'datetime',
        'last_hub_call_at' => 'datetime',
    ];

    public static function active(): ?self
    {
        /** @var ?self */
        return self::query()
            ->where('status', self::STATUS_CONNECTED)
            ->orderByDesc('id')
            ->first();
    }

    /** Never throws: callers on the deploy path must not fail because of the Hub. */
    public static function isActive(): bool
    {
        try {
            return self::query()->where('status', self::STATUS_CONNECTED)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    public function grantList(): array
    {
        return array_values(array_filter($this->grants ?? [], 'is_string'));
    }
}
