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
     * The kiosk questionnaire (FR-KSK-10, D-63): the new official forms' twelve
     * "Physical Signs Disorder of" rows, reading DOWN each of the form's three
     * columns — column => the form's label VERBATIM plus one plain-language
     * helper line for the student. (D-63 replaced D-56's nine old-form rows.)
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
        'head' => ['label' => 'HEAD', 'helper' => 'Headaches, head injury or dizziness'],
        'eyes' => ['label' => 'EYES', 'helper' => 'Blurred vision, eye pain or redness'],
        'ears' => ['label' => 'EARS', 'helper' => 'Hearing problems, ear pain or discharge'],
        'nose' => ['label' => 'NOSE', 'helper' => 'Nosebleeds, or a blocked or runny nose'],
        'throat' => ['label' => 'THROAT', 'helper' => 'Sore throat or trouble swallowing'],
        'chest_lungs' => ['label' => 'CHEST/LUNGS', 'helper' => 'Breathing problems, cough or asthma'],
        'heart' => ['label' => 'HEART', 'helper' => 'Chest pain, palpitations or heart problems'],
        'abdomen' => ['label' => 'ABDOMEN', 'helper' => 'Stomach pain or digestive problems'],
        'kidney_bladder' => ['label' => 'KIDNEY/BLADDER', 'helper' => 'Kidney, bladder or urination problems'],
        'brain' => ['label' => 'BRAIN', 'helper' => 'Seizures, fainting or other nerve problems'],
        'mental_disorder' => ['label' => 'MENTAL DISORDER', 'helper' => 'Anxiety, depression or other mental health concerns'],
    ];

    /** Longest optional detail a student may type under a YES answer (D-56). */
    public const DETAIL_MAX_LENGTH = 120;

    protected $fillable = [
        'clinic_visit_id',
        'skin',
        'head',
        'eyes',
        'ears',
        'nose',
        'throat',
        'chest_lungs',
        'heart',
        'abdomen',
        'kidney_bladder',
        'brain',
        'mental_disorder',
        'details',
        'is_pregnant',
        'last_menstrual_period',
    ];

    protected function casts(): array
    {
        return [
            // Nullable booleans (D-63): a cast still returns NULL for NULL.
            'skin' => 'boolean',
            'head' => 'boolean',
            'eyes' => 'boolean',
            'ears' => 'boolean',
            'nose' => 'boolean',
            'throat' => 'boolean',
            'chest_lungs' => 'boolean',
            'heart' => 'boolean',
            'abdomen' => 'boolean',
            'kidney_bladder' => 'boolean',
            'brain' => 'boolean',
            'mental_disorder' => 'boolean',
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
