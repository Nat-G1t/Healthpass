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
     * when nothing was recorded (a batch-generated or pre-D-28 row).
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
     * Who put this appointment in the diary — "Self-scheduled", or
     * "Booked by <College>" for a batch-generated one. (FR-STU-14, D-51)
     *
     * The student cannot act on a batch appointment themselves (D-39), so the
     * label is what tells them WHY there is no cancel button and, by naming the
     * college, exactly who to go to instead. One accessor rather than the same
     * ternary in two Blade files, so the dashboard card and the My Appointments
     * list can never word it differently.
     *
     * Null-safe on the relation: a batch row whose request or college has gone
     * missing degrades to the generic "your college" instead of throwing in a
     * view. Callers should eager-load `batchRequest.college` to avoid an N+1.
     */
    public function scheduledByLabel(): string
    {
        if ($this->source !== 'batch') {
            return 'Self-scheduled';
        }

        return 'Booked by '.($this->batchRequest?->college?->name ?? 'your college');
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

    /**
     * How far this appointment has got, as ONE key the Batch Results popup maps
     * to a label and a badge (FR-ADM-12, D-55 — introduced by D-53 for the
     * roster, which no longer shows it).
     *
     * The College Admin who booked a graduation cohort needs to know who still
     * has to turn up, and `appointments.status` alone cannot tell them: the
     * kiosk LINKS a clinic visit to the appointment but leaves the status on
     * `scheduled` (see SubmitKioskVisit::todaysAppointmentId), and only the
     * nurse's encode flips it to `completed`. So a student can be standing at
     * the clinic with their vitals captured while this row still reads
     * "scheduled". The visit and its clearance record are what carry that.
     *
     *   withdrawn  — the admin pulled this seat (FR-ADM-07)
     *   absent     — no visit, and the server clock has reached
     *                `healthpass.absent_cutoff` (8:00 PM) on the clinic date,
     *                or any later day (D-55 — was `missed`, flipped at midnight)
     *   awaiting   — no visit yet, and it is still before that cutoff
     *   in_clinic  — vitals captured at the kiosk, waiting on the nurse
     *   completed  — the nurse has encoded it; clearanceResult() has a value
     *
     * `checked_in` is deliberately not consulted: nothing in the app ever
     * writes it (the enum value predates the kiosk flow), so keying on it
     * would produce a state no real row can reach.
     *
     * Reads $this->clinicVisit and its clearanceRecord — Batch Tracking
     * eager-loads both, so a 60-student batch stays a handful of queries
     * rather than 121.
     */
    public function clearanceProgress(): string
    {
        if ($this->status === 'cancelled') {
            return 'withdrawn';
        }

        $visit = $this->clinicVisit;

        if ($visit === null) {
            // D-55: a no-show may still turn up all clinic day, so they only
            // become absent from the cutoff. Server clock only (like BR-23).
            $absentFrom = $this->scheduled_date->copy()
                ->setTimeFromTimeString((string) config('healthpass.absent_cutoff'));

            return now()->gte($absentFrom) ? 'absent' : 'awaiting';
        }

        return $visit->clearanceRecord === null ? 'in_clinic' : 'completed';
    }

    /**
     * D-53: the nurse's clearance OUTCOME for this appointment — 'Fit',
     * 'Unfit', or null while there is nothing encoded yet.
     *
     * Outcome only. The vitals, the screening answers and the nurse's notes
     * stay out of the College Admin's reach: PRD §6.6 opens exactly this one
     * field to the admin of the student's own college, and nothing else on the
     * record — shown on the Batch Results popup since D-55.
     */
    public function clearanceResult(): ?string
    {
        return $this->clinicVisit?->clearanceRecord?->result;
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
