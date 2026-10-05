<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * D-120 — what one viewer (a College Admin or the Director) last saw of one
 * batch (table #13, `batch_seen`): the status they were shown and when.
 * Written and read only by App\Support\BatchNotifications.
 */
class BatchSeen extends Model
{
    protected $table = 'batch_seen';

    // Only seen_at matters; created_at / updated_at would repeat it.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'batch_request_id',
        'seen_status',
        'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
        ];
    }
}
