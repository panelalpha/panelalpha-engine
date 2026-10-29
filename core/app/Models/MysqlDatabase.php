<?php

namespace App\Models;

use App\Lib\Traits\Models\HasDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $database
 * @property mixed $size_bytes
 */
class MysqlDatabase extends Model
{
    use HasDetails, HasFactory;

    protected $fillable = [
        'user_id',
        'database',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];
}
