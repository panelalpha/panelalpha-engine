<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $backup_id
 * @property string $remote_path
 * @property int $size_bytes
 * @property ?array $details
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Backup $backup
 *
 * @method static ?BackupItem find(int $id)
 */
class BackupItem extends Model
{
    protected $fillable = [
        'backup_id',
        'remote_path',
        'size_bytes',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'size_bytes' => 'integer',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
