<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class ClinicVisit extends Model
{
    use HasFactory;

    /** D-125: shown wherever the clinic's print / download is refused or disabled. */
    public const NOT_RELEASED_HINT = 'Available once the Director releases this batch’s documents.';

    protected $fillable = [
        'reference_no',
        'student_id',
        'college_id',
        // CAPTURE-TIME SNAPSHOT of the student's program (D-43), not a live
        // lookup — see the college() docblock below for why. Nullable: visits
        // captured before D-43 have none and are never backfilled.
        'course',
        'appointment_id',
        'login_method',
        'status',
        'privacy_consent_at',
        'checked_in_at',
        // D-72: when a resting student may come back and re-take the flagged
        // reading. Server clock only; NULL on every visit that never rested.
        'resting_until',
        // D-126: the nurse who interviewed a Medical Assessment visit and
        // submitted it to the physician, and when. NULL on every other visit.
        'interviewed_by',
        'interviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'privacy_consent_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'resting_until' => 'datetime',
            'interviewed_at' => 'datetime',
        ];
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    /**
     * D-72 — the statuses that make a visit a REAL, submitted visit.
     *
     * A `resting` visit is a first pass the kiosk saved so the student could
     * sit down and re-take a high temperature, blood pressure or heart rate.
     * It holds real answers, but the clinic has never been told about it: it is
     * not in the queue, not in any analytics count, not on the student's
     * records. Only these statuses are.
     *
     * D-126: `interviewed` is a submitted visit too — the nurse has seen the
     * student and handed them to the physician; only the result is missing.
     */
    public const SUBMITTED_STATUSES = ['captured', 'interviewed', 'encoded'];

    /**
     * What D-76 pre-filled Student remarks with when the student typed no YES
     * details. Since D-84 the box opens EMPTY instead, but records encoded
     * before then saved this text, so the printed form still reads it as
     * "no remarks" and leaves the line blank (ClearanceDocument::remarks).
     */
    public const NO_STUDENT_REMARKS = 'No student remarks';

    /**
     * D-72 — every count of "visits" runs through this scope.
     *
     * A "scope" is a reusable query fragment on the model: `ClinicVisit::submitted()`
     * (or `->submitted()` on an existing query) adds the WHERE below. Having ONE
     * definition means a resting visit cannot be counted on one screen and
     * skipped on another. The column is qualified because several callers apply
     * it to a JOIN that also has a `status` column.
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereIn('clinic_visits.status', self::SUBMITTED_STATUSES);
    }

    /**
     * FR-NRS-01/02 — the Live Queue query, shared by the page (initial render),
     * the JSON feed (polling) and the nav badge, so they can NEVER disagree on
     * which rows show or in what order.
     *
     * D-126: there are two queues, and each clinic role sees only its own (Q1):
     *  - the NURSE's: `captured` Medical Assessment visits, waiting for the
     *    interview;
     *  - the PHYSICIAN's: `captured` Medical Clearance visits (they never pass
     *    through the nurse) plus every `interviewed` visit the nurse submitted.
     * A legacy visit with no batch behind it is a Medical Clearance
     * (ClinicVisit::formType()), so "not an assessment" is written as "has no
     * assessment batch" — whereDoesntHave also matches a visit with no batch.
     *
     * Order: first come, first served by when the visit entered THIS queue —
     * the nurse's submit time for an interviewed visit, the kiosk check-in
     * otherwise. COALESCE (the first non-NULL value) is plain SQL that MySQL
     * and SQLite both run. `id` breaks ties for two visits in the same second.
     */
    public function scopeLiveQueue(Builder $query, User $viewer): Builder
    {
        $isAssessment = fn (Builder $batch) => $batch->where('form_type', 'assessment');

        if ($viewer->isPhysician()) {
            $query->where(fn (Builder $physician) => $physician
                ->where('status', 'interviewed')
                ->orWhere(fn (Builder $clearance) => $clearance
                    ->where('status', 'captured')
                    ->whereDoesntHave('appointment.batchRequest', $isAssessment)));
        } else {
            $query->where('status', 'captured')
                ->whereHas('appointment.batchRequest', $isAssessment);
        }

        return $query
            // appointment.batchRequest: the D-62 form-type badge on every row,
            // loaded here so the page and the feed never query per row (N+1).
            ->with(['student:id,name', 'college:id,name', 'vitalSigns', 'appointment:id,batch_request_id', 'appointment.batchRequest:id,form_type'])
            ->orderByRaw('COALESCE(interviewed_at, checked_in_at)')
            ->orderBy('id');
    }

    /**
     * FR-ANL-01 — visits whose vitals tripped ANY flag threshold.
     *
     * whereHas() filters by a related table: it compiles to an EXISTS
     * subquery on vital_signs, so no join/duplicate rows. The closure's
     * conditions are grouped inside that subquery, so the orWhere chain
     * can't leak into the outer query. Flags surface from CAPTURE
     * (FR-ANL-07) — no status filter here, un-encoded visits count too.
     */
    public function scopeFlagged(Builder $query): Builder
    {
        // D-72: a resting visit's flags are exactly WHY it is resting — they
        // must not appear as clinic anomalies before the student has even
        // re-taken the reading. submitted() sits here, on the shared scope, so
        // every flag consumer (the Director dashboard's Flagged Visits count)
        // gets the exclusion without repeating it.
        return $query->submitted()->whereHas('vitalSigns', function (Builder $vitals): void {
            $vitals->where('is_bp_flagged', true)
                ->orWhere('is_temp_flagged', true)
                ->orWhere('is_bmi_flagged', true)
                // D-66. is_hr_flagged is set at capture like the three above;
                // is_rr_flagged only once the clinic types the rate at encode,
                // so a visit can start unflagged here and join later.
                ->orWhere('is_hr_flagged', true)
                ->orWhere('is_rr_flagged', true);
        });
    }

    /**
     * D-72 — TODAY's resting visit for this student, or null.
     *
     * The one definition the kiosk uses at scan, at login and again at
     * re-check submit, so the screens a returning student sees and the row the
     * server updates can never be about different visits (the same rule
     * Appointment::todayFor follows for appointments).
     *
     * Scoped to today on purpose: a student who never came back yesterday is
     * already Absent (D-55/D-72), and that stale row must not hijack today's
     * fresh appointment. Such rows simply stay `resting` forever — they are a
     * record of a pass that never completed, not work waiting to be done.
     *
     * `vitalSigns` and `screeningResponse` are eager-loaded because every
     * caller then asks which steps need re-taking and what the first pass
     * already answered.
     */
    public static function restingTodayFor(int $studentId): ?self
    {
        return self::query()
            ->where('student_id', $studentId)
            ->where('status', 'resting')
            ->whereDate('checked_in_at', today())
            ->with(['vitalSigns', 'screeningResponse'])
            ->latest('id')
            ->first();
    }

    /**
     * FR-KSK-03b — the student's latest SUBMITTED visit (`captured`,
     * `interviewed` — D-126 — or `encoded`) on an appointment dated today, or null.
     *
     * Anchored on the APPOINTMENT's date, not the visit's timestamp, because
     * "used today's slot" is a fact about the appointment: it is what
     * Appointment::todayFor() just skipped, so the two definitions line up
     * exactly — a visit timestamp is a second clock that could only disagree.
     *
     * Used at scan/login for the "You're all done for today" screen and at
     * submit to tell that refusal apart from "No Clinic Schedule Today".
     */
    public static function submittedTodayFor(int $studentId): ?self
    {
        return self::query()
            ->submitted()
            ->where('student_id', $studentId)
            ->whereHas('appointment', fn (Builder $appointment) => $appointment->whereDate('scheduled_date', today()))
            ->latest('id')
            ->first();
    }

    /** D-72 — is the rest over, so the student may re-take the reading now? */
    public function restIsOver(): bool
    {
        return $this->resting_until !== null && now()->gte($this->resting_until);
    }

    // ── D-62 form type ───────────────────────────────────────────────────────

    /**
     * Which official form this visit follows — 'clearance' or 'assessment' —
     * read from the batch that scheduled it (appointment → batchRequest).
     * A legacy visit with no batch behind it used the Medical Clearance.
     *
     * No snapshot column: a batch's form type cannot change once submitted.
     * Callers that loop over visits eager-load `appointment.batchRequest`.
     */
    public function formType(): string
    {
        // One definition, on the appointment (D-68) — the kiosk reads the same
        // one at scan and at submit, so a visit's form can never disagree with
        // the screens the student was actually shown.
        return $this->appointment?->formType() ?? 'clearance';
    }

    /**
     * D-70 — whether this visit's student is female, which is what opens the
     * Medical Assessment Form's sections V (Menstrual History) and VI
     * (OB/Pregnancy History). Read from the profile on the SERVER, never from
     * anything the encode page posts: the greyed-out inputs are a courtesy,
     * the rule is here.
     */
    public function studentIsFemale(): bool
    {
        return $this->student?->studentProfile?->isFemale() ?? false;
    }

    /**
     * The purpose the clearance record stores (D-62): the batch reason's
     * printed LABEL in `purpose`, the admin's specify text in
     * `purpose_other`. Both NULL for a visit with no batch. Shared by Save &
     * Close and Preview & Print, so what previews is exactly what saves.
     *
     * @return array{purpose: ?string, purpose_other: ?string}
     */
    public function batchPurpose(): array
    {
        $batch = $this->appointment?->batchRequest;

        return [
            'purpose' => $batch?->reasonLabel(),
            'purpose_other' => $batch?->reason === BatchRequest::REASON_OTHERS ? $batch->reason_detail : null,
        ];
    }

    /**
     * D-76 — the default Student remarks on a Medical Clearance encode: the
     * student's own kiosk YES details, one "LABEL: detail" line each in the
     * form's row order (ScreeningResponse::detailsAsNotes), or an empty
     * string when they typed none (D-84), so nothing prints under REMARKS.
     * Only a DEFAULT — the encode page puts old() input and a saved record's
     * value ahead of it.
     */
    public function studentRemarks(): string
    {
        return $this->screeningResponse?->detailsAsNotes() ?? '';
    }

    // ── D-126 two queues ─────────────────────────────────────────────────────

    /**
     * D-126 — which clinic role's queue this visit is waiting in: `nurse` for
     * a captured Medical Assessment (the interview), `physician` for a
     * captured Medical Clearance or an interviewed visit (the result), or
     * null once it is encoded (or still resting). The values are `users.role`
     * names, so a controller can compare it with the signed-in user's role.
     *
     * The ONE rule every guard asks — opening the page, submitting the
     * interview, encoding, previewing, removing — and the same split
     * scopeLiveQueue() writes in SQL.
     */
    public function queueRole(): ?string
    {
        return match (true) {
            $this->status === 'interviewed' => 'physician',
            $this->status !== 'captured' => null,
            $this->formType() === 'assessment' => 'nurse',
            default => 'physician',
        };
    }

    /** D-126 — is this visit waiting in $user's own queue? */
    public function isInQueueOf(User $user): bool
    {
        return $this->queueRole() !== null && $this->queueRole() === $user->role;
    }

    /**
     * D-126 — the plain sentence a clinic user sees when they open or post to
     * a visit that is not in their own queue (they land back on the Live
     * Queue with it). Never a 403 or 404 page.
     */
    public function queueRefusal(User $user): string
    {
        $name = $this->student->name ?? 'That student';

        return match ($this->queueRole()) {
            null => "{$name} is no longer waiting in a queue.",
            'physician' => $user->isPhysician()
                ? "Only the nurse submits interviews — {$name} is already in your queue."
                : "{$name} is in the physician's queue — only the physician can open it.",
            default => "{$name} is waiting for the nurse's interview. They reach your queue once the nurse submits them.",
        };
    }

    /** D-126 — the Live Queue row's action for this viewer: the nurse interviews, the physician encodes. */
    public static function queueActionLabel(User $viewer): string
    {
        return $viewer->isPhysician() ? 'Encode Result' : 'Interview';
    }

    /**
     * D-93 / D-132 — take this visit out of the Live Queue, if it is still
     * waiting there. Returns whether it was removed.
     *
     * The ONE removal both callers use: the queue's Remove button (pass the
     * clinic user — each role removes only from its own queue, D-126) and the
     * 8 PM job (pass no one — a visit waiting in EITHER queue goes).
     *
     * The visit is DELETED, not flagged: its vital signs and screening answers
     * go with it (both tables cascade on delete). Nothing was ever encoded, so
     * no clearance record or printed document refers to it. The appointment is
     * untouched and has no submitted visit any more — so before the cutoff the
     * kiosk offers it again (Appointment::todayFor()), and after it the
     * student reads Unattended.
     *
     * The status is re-read under a row lock, so a colleague who submitted or
     * encoded this visit a moment ago wins, and an encoded record can never be
     * deleted. An `interviewed` visit also has the nurse's
     * `medical_assessments` row, whose foreign key RESTRICTS the visit's
     * delete — so it is deleted explicitly first, inside the same transaction.
     */
    public function removeFromQueue(?User $remover = null): bool
    {
        return DB::transaction(function () use ($remover): bool {
            $locked = self::whereKey($this->id)->lockForUpdate()->first();

            $isWaiting = $remover === null
                ? $locked?->queueRole() !== null
                : $locked?->isInQueueOf($remover) === true;

            if (! $isWaiting) {
                return false;
            }

            $locked->medicalAssessment()->delete();
            $locked->delete();

            return true;
        });
    }

    // ── D-125 printing gate ──────────────────────────────────────────────────

    /**
     * D-125 (O2) — may the clinic print or download this visit's official
     * form? Only once the Director has approved a release of its batch's
     * documents (any approved request, BatchRequest::hasReleasedDocuments()).
     * A legacy visit with no batch behind it (pre-D-61) is never released.
     *
     * The ONE rule every clinic print path asks: the batch results page's
     * buttons, the read-only encode page, and the print / reprint / PDF
     * routes.
     *
     * D-127 adds one exception for the person asking ($user): the physician
     * who encoded this visit may print it on the day they encoded it, by the
     * server clock — so they can hand the form over right after Save, and a
     * reload keeps that working. Pass no user to ask about the release alone.
     */
    public function isReleasedForPrint(?User $user = null): bool
    {
        if ($user !== null && $this->wasEncodedTodayBy($user)) {
            return true;
        }

        return $this->appointment?->batchRequest?->hasReleasedDocuments() ?? false;
    }

    /** D-127 — true when $user saved this visit's encode today (server clock). */
    private function wasEncodedTodayBy(User $user): bool
    {
        $record = $this->clearanceRecord;

        return $record !== null
            && (int) $record->encoded_by === $user->id
            && $record->encoded_at?->isToday() === true;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The student who submitted this kiosk visit. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The student's college AT CAPTURE TIME (FR-STU-09 snapshot, D-17).
     * Frozen on submit — not the student's current college, so analytics
     * (FR-ANL-05/08) stay transfer-proof.
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /** The appointment this visit is linked to — always set since D-61 (null only on legacy walk-in rows). */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** 1:1 vital signs captured at the kiosk. */
    public function vitalSigns(): HasOne
    {
        return $this->hasOne(VitalSigns::class);
    }

    /** 1:1 questionnaire answers — the form's twelve Physical Signs rows (D-63). */
    public function screeningResponse(): HasOne
    {
        return $this->hasOne(ScreeningResponse::class);
    }

    /** 1:0..1 clearance result (null until the physician encodes — D-126). */
    public function clearanceRecord(): HasOne
    {
        return $this->hasOne(ClearanceRecord::class);
    }

    /**
     * D-69/D-126 — the Medical Assessment Form's own sections, keyed by the
     * visit since D-126: the nurse saves them at the interview, before any
     * clearance record exists. NULL on every Medical Clearance visit.
     */
    public function medicalAssessment(): HasOne
    {
        return $this->hasOne(MedicalAssessment::class);
    }

    /** D-126 — the nurse who interviewed this visit (prints as "Interviewed/Assessed by"). */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewed_by');
    }
}
