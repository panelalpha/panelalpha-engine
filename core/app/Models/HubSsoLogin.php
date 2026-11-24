<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A login ticket from the Hub that was used, kept by its `jti` so it is never accepted twice. Never holds the ticket.
 *
 * @property int $id
 * @property string $jti
 * @property string $app
 * @property ?string $app_user
 * @property ?string $sub
 * @property Carbon $at
 */
class HubSsoLogin extends Model
{
    public const KEEP_DAYS = 30;

    public $timestamps = false;

    protected $fillable = ['jti', 'app', 'app_user', 'sub', 'at'];

    protected $casts = ['at' => 'datetime'];
}
