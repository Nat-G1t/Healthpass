<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClearanceRecord extends Model
{
    use HasFactory;

    /**
     * The 8 medical-system case categories (FR-NRS-03, locked per PRD).
     * A clearance can carry several (D-23) — stored one row per category in
     * `clearance_case_categories`; this list is the validation gate.
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

    /**
     * The official form's "Physical Signs Disorder of" rows, column → label
     * (FR-NRS-03 / D-22). The physician examines the student; the nurse
     * records YES/NO per system on the encode screen. NULL = not examined —
     * the printed bubbles stay blank. One map keeps the encode form,
     * validation, and print view in step.
     *
     * @var array<string, string>
     */
    public const PHYSICAL_SIGNS = [
        'ps_skin' => 'SKIN',
        'ps_abdomen_git' => 'ABDOMEN (GIT)',
        'ps_heent' => 'HEENT',
        'ps_gut' => 'GUT',
        'ps_chest_lungs' => 'CHEST/LUNGS',
        'ps_extremities' => 'EXTREMITIES',
        'ps_heart_cvs' => 'HEART/CVS',
        'ps_neurological' => 'NEUROLOGICAL',
        'ps_breast' => 'BREAST',
    ];

    protected $fillable = [
        'clinic_visit_id',
        'encoded_by',
        'result',
        'purpose',
        'nurse_notes',
        'ps_skin',
        'ps_abdomen_git',
        'ps_heent',
        'ps_gut',
        'ps_chest_lungs',
        'ps_extremities',
        'ps_heart_cvs',
        'ps_neurological',
        'ps_breast',
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
            // Nullable booleans: a cast still returns NULL for NULL columns.
            'ps_skin' => 'boolean',
            'ps_abdomen_git' => 'boolean',
            'ps_heent' => 'boolean',
            'ps_gut' => 'boolean',
            'ps_chest_lungs' => 'boolean',
            'ps_extremities' => 'boolean',
            'ps_heart_cvs' => 'boolean',
            'ps_neurological' => 'boolean',
            'ps_breast' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit this clearance result belongs to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    /** 0..n medical-system case categories for this clearance (D-23). */
    public function caseCategories(): HasMany
    {
        return $this->hasMany(ClearanceCaseCategory::class);
    }

    /**
     * The category names as a plain list — for display (encode read-only,
     * student records).
     *
     * @return list<string>
     */
    public function categoryNames(): array
    {
        return $this->caseCategories->pluck('case_category')->all();
    }

    /** The nurse who encoded this result. */
    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }
}
