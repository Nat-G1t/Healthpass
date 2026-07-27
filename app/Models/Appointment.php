<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ClinicScheduleService;
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
        'scheduled_time',
        'status',
        'source',
        'batch_request_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            // scheduled_time is deliberately NOT cast to a Carbon instance:
            // it is a slot KEY ('07:00:00'), compared as a literal in every
            // query, and a cast would round-trip it through a date object for
            // no gain. Display goes through timeLabel() below.
        ];
    }

    /**
     * The clinic slot as shown to a human — "7:00 AM", or "—" for the
     * pre-D-37 appointments that belong to no slot (scheduled_time NULL).
     * Every list, card and print view uses this, so legacy rows can never
     * render as an empty cell or crash on a null.
     */
    public function timeLabel(): string
    {
        if ($this->scheduled_time === null) {
            return '—';
        }

        return app(ClinicScheduleService::class)->startLabel($this->scheduled_time);
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
