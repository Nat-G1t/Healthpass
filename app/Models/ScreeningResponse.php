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

    /**
     * Shortest detail a Medical Clearance "Yes" accepts, counted after trimming
     * (D-75). Enough to refuse a lone "." without asking for an essay.
     * Mirrored by DETAIL_MIN in resources/js/kiosk/state-machine.js.
     */
    public const DETAIL_MIN_LENGTH = 3;

    /**
     * Personal / Social History — section I of the Medical Assessment Form's
     * back page (D-68), column => the form's label VERBATIM. Asked ONLY on an
     * `assessment` visit; every one of these is NULL on a `clearance` visit,
     * because the Medical Clearance has no such section.
     *
     * The three habit rows are separate from `sexually_active` because the
     * paper gives them a third box: Yes / No / Quit.
     *
     * Mirrors SOCIAL_HISTORY in resources/js/kiosk/state-machine.js — keep the
     * two lists in step.
     *
     * @var array<string, string>
     */
    public const SOCIAL_HISTORY = [
        'smoking' => 'Smoking',
        'alcohol' => 'Alcohol',
        'illicit_drugs' => 'Illicit Drugs',
    ];

    /** The only values a SOCIAL_HISTORY column may hold (validation-gated). */
    public const SOCIAL_HISTORY_VALUES = ['yes', 'no', 'quit'];

    /** The fourth Personal / Social History row — Yes / No only, so a boolean. */
    public const SEXUALLY_ACTIVE_LABEL = 'Sexually Active';

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
        // D-68 — Personal / Social History, Medical Assessment Form only.
        'smoking',
        'alcohol',
        'illicit_drugs',
        'sexually_active',
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
            // D-68. The three habit columns stay plain strings ('yes'|'no'|
            // 'quit'), so no cast; only this one is a (nullable) boolean.
            'sexually_active' => 'boolean',
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

    /**
     * The four Personal / Social History rows ready to display (D-68), in the
     * form's order, each as `['label' => …, 'answer' => 'Yes'|'No'|'Quit'|null]`.
     * `null` means unanswered — which is every row on a Medical Clearance
     * visit, and every row on a visit captured before D-68.
     *
     * The ONE place the stored value becomes display text, shared by the nurse
     * encode card and the student's My Records modal.
     *
     * @return list<array{label: string, answer: ?string}>
     */
    public function socialHistoryRows(): array
    {
        $rows = [];

        foreach (self::SOCIAL_HISTORY as $column => $label) {
            $value = $this->{$column};
            $rows[] = ['label' => $label, 'answer' => $value === null ? null : ucfirst((string) $value)];
        }

        $rows[] = [
            'label' => self::SEXUALLY_ACTIVE_LABEL,
            'answer' => $this->sexually_active === null ? null : ($this->sexually_active ? 'Yes' : 'No'),
        ];

        return $rows;
    }

    // ── Kiosk re-check payload (D-72) ────────────────────────────────────────
    // A re-check pass skips the questionnaire and the social history, so the
    // kiosk is handed back what this visit already stored, in the shapes the
    // Alpine state uses. Display only: the re-check submit re-reads these rows
    // itself and accepts nothing of the sort from the browser.

    /**
     * The twelve Physical Signs answers keyed the way the kiosk keys them
     * (D-63), each true (Yes) or false (No).
     *
     * @return array<string, bool>
     */
    public function kioskAnswers(): array
    {
        $answers = [];

        foreach (array_keys(self::QUESTIONS) as $question) {
            $answers[$question] = (bool) $this->{$question};
        }

        return $answers;
    }

    /**
     * The Personal / Social History in the kiosk's own camelCase keys (D-68),
     * or null on a Medical Clearance visit, which was never asked.
     *
     * @return array<string, mixed>|null
     */
    public function kioskSocialHistory(): ?array
    {
        if ($this->smoking === null) {
            return null;
        }

        return [
            'smoking' => $this->smoking,
            'alcohol' => $this->alcohol,
            'illicitDrugs' => $this->illicit_drugs,
            'sexuallyActive' => (bool) $this->sexually_active,
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit this questionnaire belongs to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
