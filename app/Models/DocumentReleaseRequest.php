<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-113 — a College Admin asks the Director to release a finished batch's
 * official documents (table #12, `document_release_requests`).
 *
 * The admin writes a reason and may ask for printed copies of the forms
 * (`wants_printed_copies`, D-123). The Director reviews it against the
 * batch's own signed memo (D-109) — approves it, and the college may then
 * download its encoded students' forms, or rejects it with a written message
 * the college reads on the results page. A rejected batch may be asked for
 * again; an approval never expires.
 *
 * The attachment_* columns are LEGACY (D-123): requests made before then
 * carry one file on the private disk, which the Director can still open;
 * newer requests leave them NULL.
 *
 * D-125: once an approved request that asked for printed copies is printed,
 * the clinic presses Mark as printed — `copies_printed_at` / `_by`.
 */
class DocumentReleaseRequest extends Model
{
    protected $fillable = [
        'batch_request_id',
        'requested_by',
        'reason',
        'wants_printed_copies',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_message',
        'copies_printed_at',
        'copies_printed_by',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'wants_printed_copies' => 'boolean',
            'copies_printed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** D-123: only requests made before D-123 carry a file. */
    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /**
     * D-125: does this request ask the clinic for printed copies — approved,
     * copies wanted? (Whether they are done yet is `copies_printed_at`.)
     */
    public function asksClinicToPrint(): bool
    {
        return $this->status === 'approved' && $this->wants_printed_copies;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The batch whose documents the college asked for. */
    public function batchRequest(): BelongsTo
    {
        return $this->belongsTo(BatchRequest::class);
    }

    /** The College Admin who asked. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The Director who approved or rejected it (null while pending). */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** D-125: the nurse or physician who pressed Mark as printed (null until then). */
    public function copiesPrinter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'copies_printed_by');
    }
}
