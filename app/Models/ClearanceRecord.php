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
     * The only two outcomes a nurse encodes (FR-NRS-03, D-32 dropped case
     * categories) — mirrors the `result` enum. Used by the Nurse Dashboard's
     * result filter (D-44); the encode form keeps its own Rule::in pair.
     *
     * @var list<string>
     */
    public const RESULTS = ['Fit', 'Unfit'];

    // D-62: the purpose lists live on BatchRequest::REASONS_BY_FORM — the
    // batch reason IS the printed purpose, copied here at encode as its label
    // (`purpose`) plus the admin's specify text (`purpose_other`).

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

    /**
     * Physician block defaults (FR-PRT-04 / §7.5). The DB column defaults in
     * the clearance_records migration remain the source for SAVED records;
     * these constants exist for the print PREVIEW, which renders a transient
     * (never-saved) record that no DB default can fill. Keep in step with
     * the migration.
     */
    public const PHYSICIAN_NAME = 'REYNALDO S. ALIPIO, MD';

    public const PHYSICIAN_LICENSE_NO = '60252';

    protected $fillable = [
        'clinic_visit_id',
        'encoded_by',
        'result',
        'purpose',
        'purpose_other',
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

    /** The nurse who encoded this result. */
    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }
}
