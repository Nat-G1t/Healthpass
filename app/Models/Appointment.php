<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'student_id',
        'service_type',
        'purpose',
        'purpose_other',
        'scheduled_date',
        'status',
        'source',
        'batch_request_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The student this appointment belongs to. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** The batch request that generated this appointment (null for self-booked). */
    public function batchRequest(): BelongsTo
    {
        return $this->belongsTo(BatchRequest::class);
    }

    /** The user who created this appointment (Director for batch; null for self-booked). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The kiosk visit linked to this appointment, if one exists. */
    public function clinicVisit(): HasOne
    {
        return $this->hasOne(ClinicVisit::class);
    }

    /** The batch pivot row that links back to this appointment (batch-source only). */
    public function batchRequestStudent(): HasOne
    {
        return $this->hasOne(BatchRequestStudent::class);
    }
}
