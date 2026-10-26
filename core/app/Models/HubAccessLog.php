<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One request made with a PanelAlpha Connect token. Never holds a token or a URL it
 * handed out.
 *
 * @property int $id
 * @property Carbon $at
 * @property string $method
 * @property string $route
 * @property ?string $app
 * @property int $status
 * @property ?string $source_ip
 * @property ?int $token_id
 * @property ?string $detail
 */
class HubAccessLog extends Model
{
    public const KEEP_DAYS = 30;

    protected $table = 'hub_access_log';

    public $timestamps = false;

    protected $fillable = ['at', 'method', 'route', 'app', 'status', 'source_ip', 'token_id', 'detail'];

    protected $casts = [
        'at' => 'datetime',
        'status' => 'integer',
    ];
}
