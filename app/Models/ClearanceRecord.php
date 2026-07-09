<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearanceRecord extends Model
{
    use HasFactory;

    /**
     * The 8 medical-system case categories (FR-NRS-03, locked per PRD).
     * Must stay in step with the `case_category` enum in the
     * create_clearance_records_table migration — SQLite (tests) doesn't
     * enforce enums, so the form + validation read from here.
     *
     * @var list<string>
     */
    public const CASE_CATEGORIES = [
        'Alimentary System',
        'Respiratory System',
        'Musculo-Skeletal System',
        'Integumentary System',
        'Urinary System',
        'Metabolic Endocrine System',
        'Cardiovascular System',
        'Eyes, Ears, Nose & Throat Disorders',
    ];

    /**
     * Clearance purposes (FR-NRS-03) — same in-step rule as above.
     *
     * @var list<string>
     */
    public const PURPOSES = [
        'Off Campus Procedure',
        'On-the-job Training',
        'Field Trip/Educational Tour',
        'Sports Activities',
    ];

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
