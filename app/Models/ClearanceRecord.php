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
     * The new official forms' twelve "Physical Signs Disorder of" rows,
     * column → label (FR-NRS-03 / D-22, row list replaced by D-63). The
     * physician examines the student; the nurse records YES/NO per row on the
     * encode screen, pre-filled 1:1 from ScreeningResponse::QUESTIONS (same
     * keys, `ps_` prefix). NULL = not examined — the printed bubbles stay
     * blank. One map keeps the encode form, validation, and print view in step.
     *
     * @var array<string, string>
     */
    public const PHYSICAL_SIGNS = [
        'ps_skin' => 'SKIN',
        'ps_head' => 'HEAD',
        'ps_eyes' => 'EYES',
        'ps_ears' => 'EARS',
        'ps_nose' => 'NOSE',
        'ps_throat' => 'THROAT',
        'ps_chest_lungs' => 'CHEST/LUNGS',
        'ps_heart' => 'HEART',
        'ps_abdomen' => 'ABDOMEN',
        'ps_kidney_bladder' => 'KIDNEY/BLADDER',
        'ps_brain' => 'BRAIN',
        'ps_mental_disorder' => 'MENTAL DISORDER',
    ];

    /**
     * The physician block (FR-PRT-04 / BR-17, D-64) for a record this user
     * encodes. A physician's own encode prints their name and license; a
     * nurse's prints nothing, leaving a blank line for a wet signature. Used
     * by Save & Close, the print preview and the demo seeder, so the rule
     * lives in one place.
     *
     * @return array{physician_name: ?string, physician_license_no: ?string}
     */
    public static function physicianBlockFor(User $encoder): array
    {
        if (! $encoder->isPhysician()) {
            return ['physician_name' => null, 'physician_license_no' => null];
        }

        return [
            'physician_name' => mb_strtoupper($encoder->name).', MD',
            'physician_license_no' => $encoder->license_number,
        ];
    }

    protected $fillable = [
        'clinic_visit_id',
        'encoded_by',
        'result',
        'purpose',
        'purpose_other',
        'nurse_notes',
        'ps_skin',
        'ps_head',
        'ps_eyes',
        'ps_ears',
        'ps_nose',
        'ps_throat',
        'ps_chest_lungs',
        'ps_heart',
        'ps_abdomen',
        'ps_kidney_bladder',
        'ps_brain',
        'ps_mental_disorder',
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
            'ps_head' => 'boolean',
            'ps_eyes' => 'boolean',
            'ps_ears' => 'boolean',
            'ps_nose' => 'boolean',
            'ps_throat' => 'boolean',
            'ps_chest_lungs' => 'boolean',
            'ps_heart' => 'boolean',
            'ps_abdomen' => 'boolean',
            'ps_kidney_bladder' => 'boolean',
            'ps_brain' => 'boolean',
            'ps_mental_disorder' => 'boolean',
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
