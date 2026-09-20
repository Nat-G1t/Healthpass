<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ClinicScheduleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BatchRequest extends Model
{
    use HasFactory;

    /**
     * D-62: the two official clinic forms a batch can use, DB value => name.
     * The College Admin picks one per batch; it drives the kiosk questions,
     * the encode fields and the printed document for every student on it.
     */
    public const FORM_TYPES = ['clearance' => 'Medical Clearance', 'assessment' => 'Medical Assessment Form'];

    /**
     * D-62 / BR-06: the batch reasons each form offers, key => label. The
     * reason IS the purpose printed on the form, so every label matches the
     * paper exactly — don't reword them. One source of truth for the form
     * options, the validation rule, the encode copy, the print bubbles and
     * the Visits by Purpose chart.
     */
    public const REASONS_BY_FORM = [
        'clearance' => ['fieldtrip' => 'Field Trip/Educational Tour', 'outbound' => 'Outbound Activities', 'others' => 'Others, Specify'],
        'assessment' => ['off_campus' => 'Off Campus Procedure', 'sports' => 'Sports Activities', 'ojt' => 'On-the-job Training', 'rle' => 'Related Learning Experience', 'others' => 'Others, Specify'],
    ];

    /** The one reason that additionally requires reason_detail (BR-06). */
    public const REASON_OTHERS = 'others';

    /**
     * DB status value => label shown to admins. FR-ADM-04/05 word pending
     * as "Pending Director Approval" everywhere the admin sees it.
     */
    public const STATUS_LABELS = [
        'pending' => 'Pending Director Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        // D-52: the college withdrew the request before the Director ruled
        // on it. A terminal state like the other two, but reached from the
        // admin side, so it is worded as an action they took.
        'cancelled' => 'Cancelled',
    ];

    /**
     * Model-side default, mirroring the column default: `$attributes` is what
     * Eloquent puts on a NEW model before anything is set, so a batch created
     * without a form type reads 'clearance' straight away instead of NULL
     * until it is re-fetched.
     */
    protected $attributes = [
        'form_type' => 'clearance',
    ];

    protected $fillable = [
        'reference_no',
        'college_id',
        'requested_by',
        'form_type',
        'reason',
        'reason_detail',
        'service_type',
        'requested_date',
        'requested_time',
        'requested_blocks',
        'scheduled_date',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'scheduled_date' => 'date',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    /** Status as shown to admins (pending → "Pending Director Approval"). */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    /**
     * D-52: may the college still cancel this request itself (FR-ADM-11)?
     *
     * PENDING ONLY. Once the Director has approved it, appointments exist and
     * students have been emailed (FR-STU-12) — unwinding that is the
     * per-student withdrawal on the batch roster (FR-ADM-07), not a
     * whole-batch cancel. A rejected or already-cancelled batch is terminal.
     *
     * One rule, called by the Batch Tracking page (to decide whether to draw
     * the button) and by the cancel endpoint on the LOCKED row, so the button
     * can never offer something the server would refuse.
     */
    public function isCancellable(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * D-36: the requested clinic date sat unreviewed until it passed.
     *
     * Approval is confirm-only, so there is no date left to confirm — the
     * Director must reject and ask the college to resubmit. One rule, called
     * from both the approve endpoint (on the locked row) and the Approvals
     * page (to disable the button), so the two can never disagree.
     */
    public function hasStaleRequestedDate(): bool
    {
        return $this->requested_date !== null && $this->requested_date->lt(today());
    }

    /**
     * D-37: the batch has no clinic-hour span to confirm.
     *
     * True for batches submitted before D-37 (`requested_time` NULL). Exactly
     * like D-36's NULL `requested_date`, approval is confirm-only and there is
     * nothing here to confirm, so those batches must be rejected and
     * resubmitted. One rule, called by both the Approvals page and the approve
     * endpoint, so the button and the server can never disagree.
     */
    public function hasNoRequestedTime(): bool
    {
        return $this->requested_time === null || $this->requested_blocks === null;
    }

    /**
     * The contiguous clinic slots this batch occupies, or [] when it has none
     * (pre-D-37) or its stored span no longer fits the configured clinic day.
     *
     * @return list<string>
     */
    public function requestedSpan(): array
    {
        if ($this->hasNoRequestedTime()) {
            return [];
        }

        return app(ClinicScheduleService::class)
            ->span($this->requested_time, (int) $this->requested_blocks);
    }

    /** "7:00 AM – 10:00 AM (3 slots)", or "—" for a batch with no span. */
    public function requestedSpanLabel(): string
    {
        return app(ClinicScheduleService::class)->spanLabel($this->requestedSpan());
    }

    /**
     * BR-23: hours of this batch's span that have already gone by.
     *
     * Only ever non-empty for a batch requested for TODAY — a future date has
     * no elapsed hours, and a strictly past one is already refused by
     * [[hasStaleRequestedDate]]. Approving into these would create appointments
     * at times that have been and gone, so it is refused; one rule called by
     * both the Approvals page and the approve endpoint, so the disabled button
     * and the server can never disagree.
     *
     * @return list<string>
     */
    public function elapsedSpanHours(): array
    {
        if ($this->requested_date === null) {
            return [];
        }

        return app(ClinicScheduleService::class)
            ->elapsedSlotsIn($this->requested_date->toDateString(), $this->requestedSpan());
    }

    /** "Medical Clearance" / "Medical Assessment Form" (D-62). */
    public function formTypeLabel(): string
    {
        return self::FORM_TYPES[$this->form_type] ?? ucfirst((string) $this->form_type);
    }

    /**
     * The reason's printed label from this batch's form ("Others, Specify"
     * for others), or NULL for a key the form doesn't offer — a pre-D-62
     * reason such as 'graduation'.
     */
    public function reasonLabel(): ?string
    {
        return self::REASONS_BY_FORM[$this->form_type][$this->reason] ?? null;
    }

    /** Human-readable reason: the label, or the admin's own text for "others". */
    public function reasonText(): string
    {
        if ($this->reason === self::REASON_OTHERS && $this->reason_detail !== null) {
            return $this->reason_detail;
        }

        return $this->reasonLabel() ?? ucfirst($this->reason);
    }

    // ── Batch results (FR-ADM-12, D-55) ──────────────────────────────────────

    /**
     * D-55: has every student this batch still holds finished with the clinic?
     *
     * FINISHED means each non-withdrawn student is Completed (the nurse has
     * encoded them) or Absent (no visit by the absent cutoff). A student at the
     * clinic but not yet encoded keeps the batch open, even past the cutoff;
     * withdrawn students are ignored, since they no longer hold a seat.
     *
     * D-72: `rechecking` is unfinished for the same reason `in_clinic` is —
     * only completed and absent are listed here, so a resting student keeps
     * the batch open until they come back (or the cutoff makes them absent).
     *
     * Reads the SAME Appointment::clearanceProgress() the Batch Results popup
     * rows show, so the Time of Completion column and the popup can never
     * disagree. Callers eager-load
     * batchRequestStudents.appointment.clinicVisit.clearanceRecord.
     */
    public function isResultsFinished(): bool
    {
        return $this->heldAppointments()->every(
            fn (Appointment $appointment): bool => in_array($appointment->clearanceProgress(), ['completed', 'absent'], true),
        );
    }

    /**
     * D-55: when the batch finished — the time of the LAST encode among its
     * students. Null while it is unfinished, and null when it finished with
     * nobody Completed (everyone absent or withdrawn); the view words both.
     */
    public function resultsCompletedAt(): ?Carbon
    {
        if (! $this->isResultsFinished()) {
            return null;
        }

        return $this->heldAppointments()
            ->filter(fn (Appointment $appointment): bool => $appointment->clearanceProgress() === 'completed')
            ->map(fn (Appointment $appointment): ?Carbon => $appointment->clinicVisit->clearanceRecord->encoded_at)
            ->max();
    }

    /**
     * Every roster row's appointment, minus the withdrawn ones.
     *
     * @return Collection<int, Appointment>
     */
    private function heldAppointments(): Collection
    {
        return $this->batchRequestStudents
            ->map(fn (BatchRequestStudent $row): ?Appointment => $row->appointment)
            ->filter(fn (?Appointment $appointment): bool => $appointment !== null
                && $appointment->clearanceProgress() !== 'withdrawn');
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

    /**
     * The college admin who cancelled this request (null unless cancelled).
     * A college may have more than one admin (D-47), so this is NOT the same
     * person as [[requester]] by construction.
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The individual student pivot rows included in this batch. */
    public function batchRequestStudents(): HasMany
    {
        return $this->hasMany(BatchRequestStudent::class);
    }
}
