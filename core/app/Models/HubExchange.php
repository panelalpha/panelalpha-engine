<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One signed request this engine sent to the Hub: a report, a backfill upload, a secret rotation or an upgrade.
 * Never holds a body or a secret; status 0 is an answer that never came.
 *
 * @property int $id
 * @property Carbon $at
 * @property string $kind
 * @property int $status
 * @property int $duration_ms
 * @property int $bytes
 * @property ?string $detail
 */
class HubExchange extends Model
{
    public const KEEP_DAYS = 30;

    public const REPORT = 'report';

    public const BACKFILL = 'backfill';

    public const ROTATE = 'rotate';

    public const UPGRADE = 'upgrade';

    private const DETAIL_LENGTH = 500;

    public $timestamps = false;

    protected $fillable = ['at', 'kind', 'status', 'duration_ms', 'bytes', 'detail'];

    protected $casts = [
        'at' => 'datetime',
        'status' => 'integer',
        'duration_ms' => 'integer',
        'bytes' => 'integer',
    ];

    public static function record(string $kind, int $status, float $startedAt, int $bytes, ?string $detail = null): void
    {
        self::query()->create([
            'at' => now(),
            'kind' => $kind,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'bytes' => $bytes,
            'detail' => $detail === null ? null : mb_substr($detail, 0, self::DETAIL_LENGTH),
        ]);
    }
}
