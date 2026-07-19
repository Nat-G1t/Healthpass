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
            ->with(['student:id,name', 'college:id,name', 'vitalSigns'])
            ->orderBy('checked_in_at')
            ->orderBy('id');
    }

    /**
     * Encoded visits only — the base scope for the By-Sex donut (FR-ANL-04)
     * and the analytics month list (CaseMonths). Captured (un-encoded)
     * visits never enter these counts; vitals flags are the one exception
     * (scopeFlagged below).
     */
    public function scopeEncoded(Builder $query): Builder
    {
        return $query->where('status', 'encoded');
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
                ->orWhere('is_bmi_flagged', true);
        });
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

    /** The booked appointment this visit is linked to (null = walk-in). */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** 1:1 vital signs captured at the kiosk. */
    public function vitalSigns(): HasOne
    {
        return $this->hasOne(VitalSigns::class);
    }

    /** 1:1 nine-system questionnaire answers. */
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
