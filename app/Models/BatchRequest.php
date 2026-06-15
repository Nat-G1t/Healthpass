<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'college_id',
        'requested_by',
        'reason',
        'reason_detail',
        'service_type',
        'scheduled_date',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The college this batch request was submitted for. */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /** The college admin who submitted this request. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The director who approved or rejected this request (null while pending). */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The individual student pivot rows included in this batch. */
    public function batchRequestStudents(): HasMany
    {
        return $this->hasMany(BatchRequestStudent::class);
    }
}
