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
    ];

    protected $fillable = [
        'reference_no',
        'college_id',
        'requested_by',
        'reason',
        'reason_detail',
        'service_type',
        'requested_date',
        'scheduled_date',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'scheduled_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    /** Status as shown to admins (pending → "Pending Director Approval"). */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
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

    /** The individual student pivot rows included in this batch. */
    public function batchRequestStudents(): HasMany
    {
        return $this->hasMany(BatchRequestStudent::class);
    }
}
