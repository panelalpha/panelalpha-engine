<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * @property int    $id
 * @property string $token
 * @property string $username
 * @property string $cookie_name
 * @property string $cookie_value
 * @property string $redirect
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AppSsoToken extends Model
{
    use Prunable;

    protected $fillable = [
        'token',
        'username',
        'cookie_name',
        'cookie_value',
        'redirect',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    /**
     * Claim a token once. The conditional update is what picks the single
     * winner between concurrent requests; the loser gets null. The cookie is
     * a live app session, so it is blanked in the same statement.
     */
    public static function redeem(string $token, string $username): ?self
    {
        /** @var ?self $record */
        $record = self::where('token', $token)
            ->where('username', $username)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($record === null) {
            return null;
        }

        $claimed = self::whereKey($record->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'cookie_value' => '']);

        return $claimed === 1 ? $record : null;
    }

    /** Used or expired rows are worth nothing; an expired one still holds a cookie. */
    public function prunable(): Builder
    {
        return static::query()->where(
            fn (Builder $q) => $q->whereNotNull('used_at')->orWhere('expires_at', '<=', now())
        );
    }
}
