<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClinicVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'student_id',
        'college_id',
        // CAPTURE-TIME SNAPSHOT of the student's program (D-43), not a live
        // lookup — see the college() docblock below for why. Nullable: visits
        // captured before D-43 have none and are never backfilled.
        'course',
        'appointment_id',
        'login_method',
        'status',
        'privacy_consent_at',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'privacy_consent_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    /**
     * FR-NRS-01/02 — the Live Queue query, shared by the page (initial render)
     * and the JSON feed (polling) so the two can NEVER disagree on order.
     *
     * Captured visits, oldest first (FCFS): the top row is the longest-waiting
     * student. `id` breaks ties for two visits checked in the same second.
     *
     * Index note: the `(status, created_at)` composite index (§6.4) serves the
     * `status = 'captured'` filter via its leading column. The FCFS sort key is
     * `checked_in_at` (per FR-NRS-01), not `created_at`, so the index's second
     * column doesn't cover the ORDER BY — but the queue is only unencoded
     * visits (a handful of rows), so the sort over that tiny set is free.
     */
    public function scopeLiveQueue(Builder $query): Builder
    {
        return $query
            ->where('status', 'captured')
            // appointment.batchRequest: the D-62 form-type badge on every row,
            // loaded here so the page and the feed never query per row (N+1).
            ->with(['student:id,name', 'college:id,name', 'vitalSigns', 'appointment:id,batch_request_id', 'appointment.batchRequest:id,form_type'])
            ->orderBy('checked_in_at')
            ->orderBy('id');
    }

    /**
     * FR-ANL-01/05 — visits whose vitals tripped ANY flag threshold.
     *
     * whereHas() filters by a related table: it compiles to an EXISTS
     * subquery on vital_signs, so no join/duplicate rows. The closure's
     * conditions are grouped inside that subquery, so the orWhere chain
     * can't leak into the outer query. Flags surface from CAPTURE
     * (FR-ANL-07) — no status filter here, un-encoded visits count too.
     */
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->whereHas('vitalSigns', function (Builder $vitals): void {
            $vitals->where('is_bp_flagged', true)
                ->orWhere('is_temp_flagged', true)
                ->orWhere('is_bmi_flagged', true)
                // D-66. is_hr_flagged is set at capture like the three above;
                // is_rr_flagged only once the clinic types the rate at encode,
                // so a visit can start unflagged here and join later.
                ->orWhere('is_hr_flagged', true)
                ->orWhere('is_rr_flagged', true);
        });
    }

    // ── D-62 form type ───────────────────────────────────────────────────────

    /**
     * Which official form this visit follows — 'clearance' or 'assessment' —
     * read from the batch that scheduled it (appointment → batchRequest).
     * A legacy visit with no batch behind it used the Medical Clearance.
     *
     * No snapshot column: a batch's form type cannot change once submitted.
     * Callers that loop over visits eager-load `appointment.batchRequest`.
     */
    public function formType(): string
    {
        // One definition, on the appointment (D-68) — the kiosk reads the same
        // one at scan and at submit, so a visit's form can never disagree with
        // the screens the student was actually shown.
        return $this->appointment?->formType() ?? 'clearance';
    }

    /**
     * D-70 — whether this visit's student is female, which is what opens the
     * Medical Assessment Form's sections V (Menstrual History) and VI
     * (OB/Pregnancy History). Read from the profile on the SERVER, never from
     * anything the encode page posts: the greyed-out inputs are a courtesy,
     * the rule is here.
     */
    public function studentIsFemale(): bool
    {
        return $this->student?->studentProfile?->sex === 'F';
    }

    /**
     * The purpose the clearance record stores (D-62): the batch reason's
     * printed LABEL in `purpose`, the admin's specify text in
     * `purpose_other`. Both NULL for a visit with no batch. Shared by Save &
     * Close and Preview & Print, so what previews is exactly what saves.
     *
     * @return array{purpose: ?string, purpose_other: ?string}
     */
    public function batchPurpose(): array
    {
        $batch = $this->appointment?->batchRequest;

        return [
            'purpose' => $batch?->reasonLabel(),
            'purpose_other' => $batch?->reason === BatchRequest::REASON_OTHERS ? $batch->reason_detail : null,
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The student who submitted this kiosk visit. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The student's college AT CAPTURE TIME (FR-STU-09 snapshot, D-17).
     * Frozen on submit — not the student's current college, so analytics
     * (FR-ANL-05/08) stay transfer-proof.
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /** The appointment this visit is linked to — always set since D-61 (null only on legacy walk-in rows). */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** 1:1 vital signs captured at the kiosk. */
    public function vitalSigns(): HasOne
    {
        return $this->hasOne(VitalSigns::class);
    }

    /** 1:1 questionnaire answers — the form's twelve Physical Signs rows (D-63). */
    public function screeningResponse(): HasOne
    {
        return $this->hasOne(ScreeningResponse::class);
    }

    /** 1:0..1 nurse-encoded clearance result (null until nurse encodes). */
    public function clearanceRecord(): HasOne
    {
        return $this->hasOne(ClearanceRecord::class);
    }
}
