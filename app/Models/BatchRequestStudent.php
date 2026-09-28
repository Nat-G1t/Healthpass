<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * D-87: leave out students whose appointment was withdrawn.
     *
     * Per-student withdrawal (FR-ADM-07) was removed by D-87, but the rows it
     * already produced stay in the database — an appointment flipped to
     * `cancelled`, its pivot row kept. The college's pages (the Batch Results
     * popup, the roster, the student counts) hide those rows, so this scope is
     * the ONE place that says what "withdrawn" means for them.
     *
     * A row with no appointment yet (pending / rejected / cancelled batch) is
     * kept: whereDoesntHave() only drops rows whose appointment IS cancelled.
     */
    public function scopeNotWithdrawn(Builder $query): Builder
    {
        return $query->whereDoesntHave('appointment', fn (Builder $appointment) => $appointment->where('status', 'cancelled'));
    }

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
