<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningResponse extends Model
{
    use HasFactory;

    /**
     * The kiosk questionnaire (FR-KSK-10, D-56): the official form's nine
     * "Physical Signs Disorder of" rows in the form's order — column => the
     * form's label VERBATIM plus one plain-language helper line for the student.
     *
     * The ONE server-side list: KioskSubmitRequest, SubmitKioskVisit, the nurse
     * encode view and the student records view all read it. Each key equals the
     * suffix of its ClearanceRecord::PHYSICAL_SIGNS column, so the encode
     * pre-fill is simply ps_<key> ← <key>.
     *
     * Mirrors SYSTEMS in resources/js/kiosk/state-machine.js — keep the two
     * lists in step.
     *
     * @var array<string, array{label: string, helper: string}>
     */
    public const QUESTIONS = [
        'skin' => ['label' => 'SKIN', 'helper' => 'Rashes, wounds, itching or other skin problems'],
        'abdomen_git' => ['label' => 'ABDOMEN (GIT)', 'helper' => 'Stomach or digestive problems'],
        'heent' => ['label' => 'HEENT', 'helper' => 'Head, eyes, ears, nose or throat'],
        // TODO(D-56): confirm with the clinic. GUT is ASSUMED to mean the
        // genito-urinary tract; this helper line depends on that reading.
        'gut' => ['label' => 'GUT', 'helper' => 'Kidneys, bladder or urination'],
        'chest_lungs' => ['label' => 'CHEST/LUNGS', 'helper' => 'Breathing problems, cough or asthma'],
        'extremities' => ['label' => 'EXTREMITIES', 'helper' => 'Arms, legs, hands, feet or joints'],
        'heart_cvs' => ['label' => 'HEART/CVS', 'helper' => 'Heart or blood circulation'],
        'neurological' => ['label' => 'NEUROLOGICAL', 'helper' => 'Seizures, numbness, frequent headaches or nerve problems'],
        'breast' => ['label' => 'BREAST', 'helper' => 'Lumps, pain or other breast concerns'],
    ];

    /** Longest optional detail a student may type under a YES answer (D-56). */
    public const DETAIL_MAX_LENGTH = 120;

    protected $fillable = [
        'clinic_visit_id',
        'skin',
        'abdomen_git',
        'heent',
        'gut',
        'chest_lungs',
        'extremities',
        'heart_cvs',
        'neurological',
        'breast',
        'details',
        'is_pregnant',
        'last_menstrual_period',
    ];

    protected function casts(): array
    {
        return [
            // Nullable booleans (D-56): a cast still returns NULL for NULL.
            'skin' => 'boolean',
            'abdomen_git' => 'boolean',
            'heent' => 'boolean',
            'gut' => 'boolean',
            'chest_lungs' => 'boolean',
            'extremities' => 'boolean',
            'heart_cvs' => 'boolean',
            'neurological' => 'boolean',
            'breast' => 'boolean',
            // Question key => the student's typed detail, YES answers only.
            // The 'array' cast turns the JSON column into a PHP array and back.
            'details' => 'array',
            'is_pregnant' => 'boolean',
            'last_menstrual_period' => 'date',
        ];
    }

    /** The detail the student typed under a YES answer, or null (D-56). */
    public function detailFor(string $question): ?string
    {
        return $this->details[$question] ?? null;
    }

    /**
     * The Nurse Notes pre-fill (D-56): one "LABEL: detail" line per typed
     * detail, in the form's order — e.g. "SKIN: itchy rash on left arm".
     * An empty string when the student typed none.
     */
    public function detailsAsNotes(): string
    {
        $lines = [];

        foreach (self::QUESTIONS as $key => $question) {
            $detail = $this->detailFor($key);

            if ($detail !== null) {
                $lines[] = "{$question['label']}: {$detail}";
            }
        }

        return implode("\n", $lines);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit this questionnaire belongs to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
