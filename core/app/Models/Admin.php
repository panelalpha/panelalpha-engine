<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

// Authenticatable because the sanctum guard hands this model to middleware
// that expects one: `throttle` keys its limiter on getAuthIdentifier(), and a
// plain Model answered every bearer request on a throttled route with a 500.
class Admin extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * @return self
     */
    public static function rootAccount()
    {
        /** @var self */
        return self::firstOrCreate(
            ['name' => 'root'],
            ['email' => 'root@localhost'],
        );
    }
}
