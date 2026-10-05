<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-113 — a College Admin asks the Director to release a finished batch's
 * official documents (table #12, `document_release_requests`).
 *
 * The admin writes a reason and attaches one file (a photo, image or PDF on
 * the private disk, like the D-109 memo). The Director approves it — the
 * college may then download its encoded students' forms — or rejects it with
 * a written message the college reads on the results page. A rejected batch
 * may be asked for again; an approval never expires.
 */
class DocumentReleaseRequest extends Model
{
    protected $fillable = [
        'batch_request_id',
        'requested_by',
        'reason',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_message',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** A photo / image (thumbnail + zoom) rather than a PDF? */
    public function attachmentIsImage(): bool
    {
        return str_starts_with($this->attachment_mime, 'image/');
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
}
