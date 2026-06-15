<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'clinic_visit_id',
        'encoded_by',
        'result',
        'case_category',
        'purpose',
        'nurse_notes',
        'physician_name',
        'physician_license_no',
        'encoded_at',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'encoded_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit this clearance result belongs to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    /** The nurse who encoded this result. */
    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }
}
