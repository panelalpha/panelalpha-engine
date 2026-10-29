<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * The token a rotation minted, kept encrypted only for the old token's grace
 * period, so a retried rotate hands back the same one.
 *
 * @property int $id
 * @property int $from_token_id
 * @property int $to_token_id
 * @property string $plain_token
 * @property Carbon $expires_at
 * @property ?Carbon $from_expires_at the old token's expiry before the grace replaced it
 */
class TokenRotation extends Model
{
    use Prunable;

    protected $table = 'personal_access_token_rotations';

    protected $fillable = ['from_token_id', 'to_token_id', 'plain_token', 'expires_at', 'from_expires_at'];

    protected $hidden = ['plain_token'];

    protected $casts = [
        'plain_token' => 'encrypted',
        'expires_at' => 'datetime',
        'from_expires_at' => 'datetime',
    ];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }
}
