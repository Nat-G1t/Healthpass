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
     * FR-ANL-07 — encoded visits only: the shared base scope for ALL case
     * statistics (cases-by-college chart, the FR-ANL-03 matrix, and the
     * FR-ANL-04 by-sex donut), so every analytics number filters "encoded"
     * the same way. Captured (un-encoded) visits never enter case stats;
     * vitals flags are the one exception (scopeFlagged below).
     */
    public function scopeEncoded(Builder $query): Builder
    {
        return $query->where('status', 'encoded');
    }

    /**
     * FR-ANL-02/03/08 + FR-ANL-06 — the shared row set behind every CASE
     * statistic: encoded visits joined to their clearance record and its
     * case-category rows, i.e. one row per record × category (D-23).
     * The college chart, the matrix, the by-system chart, and the CSV
     * exports all GROUP BY over this same base — one source of truth,
     * so a download can never disagree with the screen it sits on.
     */
    public function scopeEncodedCaseRows(Builder $query): Builder
    {
        return $query->encoded() // FR-ANL-07
            ->join('clearance_records', 'clearance_records.clinic_visit_id', '=', 'clinic_visits.id')
            ->join('clearance_case_categories', 'clearance_case_categories.clearance_record_id', '=', 'clearance_records.id');
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
