<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ClinicScheduleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchRequest extends Model
{
    use HasFactory;

    /**
     * The locked batch reasons (FR-ADM-02 / BR-06): DB enum value => label
     * shown in the UI. One source of truth for the form options, the
     * validation rule, and display — the keys must match the `reason`
     * enum in the batch_requests migration exactly.
     */
    public const REASONS = [
        'graduation' => 'Graduation Clearance',
        'ojt' => 'OJT / Practicum',
        'enrollment' => 'General Enrollment',
        'scholarship' => 'Scholarship',
        'sports' => 'Sports / Athletics',
        'fieldtrip' => 'Field Trip / Educational Tour',
        'others' => 'Others',
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

    protected $fillable = [
        'reference_no',
        'college_id',
        'requested_by',
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

    /** Human-readable reason: the label, or the admin's own text for "others". */
    public function reasonText(): string
    {
        if ($this->reason === self::REASON_OTHERS && $this->reason_detail !== null) {
            return $this->reason_detail;
        }

        return self::REASONS[$this->reason] ?? ucfirst($this->reason);
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
