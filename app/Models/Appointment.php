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

    /**
     * The full one-hour slot — "9:00 AM – 10:00 AM" — or "—" for the pre-D-37
     * rows that belong to no slot. The wordier counterpart of timeLabel(), used
     * where the student needs to know the whole hour rather than just when it
     * starts (the booking picker, and the appointment email — FR-STU-12).
     */
    public function timeRangeLabel(): string
    {
        if ($this->scheduled_time === null) {
            return '—';
        }

        return app(ClinicScheduleService::class)->label($this->scheduled_time);
    }

    /**
     * Why this clearance is being sought, as one human-readable line, or NULL
     * when nothing was recorded (dental, or a pre-D-28 row).
     *
     * The two sources are genuinely different columns: a self-booked
     * appointment carries the student's own `purpose` (D-28, with
     * `purpose_other` holding the free-text event when they picked "Others"),
     * while a batch-generated one carries none at all — its reason lives on the
     * College Admin's batch request. One accessor so callers never have to know
     * which of the two they are holding.
     */
    public function purposeText(): ?string
    {
        if ($this->source === 'batch') {
            return $this->batchRequest?->reasonText();
        }

        if ($this->purpose === null) {
            return null;
        }

        return $this->purpose === ClearanceRecord::PURPOSE_OTHERS
            ? ($this->purpose_other ?? $this->purpose)
            : $this->purpose;
    }

    /**
     * May the STUDENT cancel this appointment themselves? (FR-STU-06, D-39)
     *
     * Three conditions, and the third is new in D-39: a batch appointment
     * belongs to the cohort its College Admin booked, so only that admin may
     * withdraw it — a student quietly dropping out of a graduation batch left
     * the college's roster silently wrong. Self-booked appointments are
     * unchanged: cancellable right up to the day before.
     *
     * This is the ONE definition, called by both the cancel endpoint and every
     * view that draws a cancel button, so the button and the server can never
     * disagree (same shape as BatchRequest::hasStaleRequestedDate()).
     */
    public function isSelfCancellable(): bool
    {
        return $this->status === 'scheduled'
            && $this->source !== 'batch'
            && $this->scheduled_date->gt(today());
    }

    /**
     * May the COLLEGE ADMIN withdraw this appointment? (FR-ADM-07, D-40)
     *
     * The counterpart of isSelfCancellable(), and deliberately not its mirror
     * image — the two differ on the date. A student loses the right to cancel
     * the day before, so they cannot walk away from a booked seat at the last
     * minute; an admin keeps it through the day itself, because "the student
     * phoned in sick this morning" is the single most common reason a seat
     * needs freeing, and refusing it would leave a seat blocked for a student
     * everyone already knows is not coming.
     *
     * `scheduled` is the hard limit in the other direction: once the visit is
     * `checked_in` or `completed` the student is at (or through) the kiosk and
     * a clinic_visit may already point at this row — cancelling then would
     * contradict a real encounter, not free a seat.
     *
     * A past date is refused because there is no longer a seat to free;
     * today still counts as cancellable.
     */
    public function isAdminCancellable(): bool
    {
        return $this->status === 'scheduled'
            && $this->source === 'batch'
            && ! $this->scheduled_date->lt(today());
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
