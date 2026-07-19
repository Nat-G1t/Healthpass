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
     * Clearance purposes (FR-NRS-03) — the locked PRD list; validation is
     * the real gate (SQLite in tests doesn't enforce the MySQL enum).
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
     * The official form's fifth purpose line, "Others, Specify: ___" — the
     * nurse picks it and types the event into `purpose_other`. Kept out of
     * PURPOSES so the four locked values stay a clean list for loops and
     * future analytics.
     */
    public const PURPOSE_OTHERS = 'Others';

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
