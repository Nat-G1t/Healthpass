<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-23 — one medical-system case category on a clearance record. A clearance
 * can carry several (one row each); the valid values are the locked
 * ClearanceRecord::CASE_CATEGORIES list, enforced by validation.
 */
class ClearanceCaseCategory extends Model
{
    protected $fillable = [
        'clearance_record_id',
        'case_category',
    ];

    /** The clearance record this category belongs to. */
    public function clearanceRecord(): BelongsTo
    {
        return $this->belongsTo(ClearanceRecord::class);
    }
}
