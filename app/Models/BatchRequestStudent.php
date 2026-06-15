<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchRequestStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_request_id',
        'student_id',
        'appointment_id',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /** The parent batch request. */
    public function batchRequest(): BelongsTo
    {
        return $this->belongsTo(BatchRequest::class);
    }

    /** The student included in this batch. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The appointment generated for this student on batch approval.
     * Null until the Director approves the batch.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
