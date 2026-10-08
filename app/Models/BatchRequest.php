<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ClinicScheduleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BatchRequest extends Model
{
    use HasFactory;

    /**
     * D-62: the two official clinic forms a batch can use, DB value => name.
     * The College Admin picks one per batch; it drives the kiosk questions,
     * the encode fields and the printed document for every student on it.
     */
    public const FORM_TYPES = ['clearance' => 'Medical Clearance', 'assessment' => 'Medical Assessment Form'];

    /**
     * D-62 / BR-06: the batch reasons each form offers, key => label. The
     * reason IS the purpose printed on the form, so every label matches the
     * paper exactly — don't reword them. One source of truth for the form
     * options, the validation rule, the encode copy, the print bubbles and
     * the Visits by Purpose chart.
     * Shown to people as the batch's **Purpose** (D-105).
     */
    public const REASONS_BY_FORM = [
        'clearance' => ['fieldtrip' => 'Field Trip/Educational Tour', 'outbound' => 'Outbound Activities', 'others' => 'Others, Specify'],
        'assessment' => ['off_campus' => 'Off Campus Procedure', 'sports' => 'Sports Activities', 'ojt' => 'On-the-job Training', 'rle' => 'Related Learning Experience', 'others' => 'Others, Specify'],
    ];

    /** The one reason that additionally requires reason_detail (BR-06). */
    public const REASON_OTHERS = 'others';

    /**
     * DB status value => label shown to admins. FR-ADM-04/05 word pending
     * as "Pending Director Approval" everywhere the admin sees it.
     */
    public const STATUS_LABELS = [
        'pending' => 'Pending Director Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        // D-52: the college withdrew the request before the Director ruled
        // on it. A terminal state like the other two, but reached from the
        // admin side, so it is worded as an action they took.
        'cancelled' => 'Cancelled',
    ];

    /**
     * Model-side default, mirroring the column default: `$attributes` is what
     * Eloquent puts on a NEW model before anything is set, so a batch created
     * without a form type reads 'clearance' straight away instead of NULL
     * until it is re-fetched.
     */
    protected $attributes = [
        'form_type' => 'clearance',
    ];

    protected $fillable = [
        'reference_no',
        'college_id',
        'requested_by',
        'form_type',
        'reason',
        'reason_detail',
        // D-109: the signed memo — a private file, plus the name and type to
        // show and serve it with. NULL on every batch submitted before D-109.
        'memo_path',
        'memo_original_name',
        'memo_mime',
        'service_type',
        // Legacy since D-108: what a college asked for before the Director
        // started choosing the schedule. New batches leave all three NULL.
        'requested_date',
        'requested_time',
        'requested_blocks',
        // D-108: the date and hour span the Director scheduled at approval.
        'scheduled_date',
        'scheduled_time',
        'scheduled_blocks',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'scheduled_date' => 'date',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    /** Status as shown to admins (pending → "Pending Director Approval"). */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    /**
     * May the college still cancel this request itself (FR-ADM-11)?
     *
     * D-52 allowed PENDING only. D-92 (supersedes that clause and D-87's
     * "nobody cancels an approved batch") also allows an APPROVED batch, but
     * only while nothing has happened yet on the clinic day:
     *   - its first clinic hour has not started (server clock, like BR-23), and
     *   - no student from it has used the kiosk — a visit in ANY status, a
     *     resting one included, means someone came.
     * So a cancel never strands a visit the clinic is working on. A rejected
     * or already-cancelled batch is terminal.
     *
     * One rule, called by the Batch Tracking and Submitted pages (to decide
     * whether to draw the button) and by the cancel endpoint on the LOCKED row,
     * so the button can never offer something the server would refuse.
     */
    public function isCancellable(): bool
    {
        if ($this->status === 'pending') {
            return true;
        }

        return $this->status === 'approved'
            && ! $this->hasFirstHourStarted()
            && ! $this->hasKioskVisit();
    }

    /**
     * D-92: has the approved batch's first clinic hour begun? True when there
     * is no hour to measure (a pre-D-37 batch) — those dates are long past.
     */
    public function hasFirstHourStarted(): bool
    {
        $span = $this->scheduledSpan();

        if ($this->scheduled_date === null || $span === []) {
            return true;
        }

        return now()->gte(Carbon::parse($this->scheduled_date->toDateString().' '.$span[0]));
    }

    /** D-92: has any student on this batch reached the kiosk (any visit status)? */
    public function hasKioskVisit(): bool
    {
        return $this->appointments()->whereHas('clinicVisit')->exists();
    }

    /**
     * D-92: was this batch cancelled AFTER the Director approved it? A decision
     * stamp on a cancelled row can only be an approval — a rejected batch can
     * never be cancelled.
     */
    public function wasCancelledAfterApproval(): bool
    {
        return $this->status === 'cancelled' && $this->reviewed_at !== null;
    }

    /**
     * D-108: the contiguous clinic slots the Director scheduled this batch
     * into, or [] while it has none — every pending, rejected or
     * never-approved batch, and an approved batch from before D-37.
     *
     * Every "which hours does this batch hold?" question reads this: the
     * D-92 first-hour rule, the BR-25 clash description and the college's
     * pages. The legacy requested_* span is never read for it.
     *
     * D-122: read as stored (spanFrom()), not through today's grid — a batch
     * booked at 7 AM or 4 PM before the day became 8–4 keeps its hours.
     *
     * @return list<string>
     */
    public function scheduledSpan(): array
    {
        if ($this->scheduled_time === null || $this->scheduled_blocks === null) {
            return [];
        }

        return app(ClinicScheduleService::class)
            ->spanFrom($this->scheduled_time, (int) $this->scheduled_blocks);
    }

    /** "7:00 AM – 10:00 AM (3 slots)", or "—" for a batch with no scheduled span. */
    public function scheduledSpanLabel(): string
    {
        return app(ClinicScheduleService::class)->spanLabel($this->scheduledSpan());
    }

    /**
     * "Wed, Oct 7, 2026 · 9:00 AM" — what the college asked for BEFORE D-108,
     * or null for a batch submitted since (no requested date). Shown on the
     * Director's Review page as information only; nothing decides on it.
     */
    public function requestedLabel(): ?string
    {
        if ($this->requested_date === null) {
            return null;
        }

        $schedule = app(ClinicScheduleService::class);
        $time = $schedule->isSlot($this->requested_time) ? ' · '.$schedule->startLabel($this->requested_time) : '';

        return $this->requested_date->format('D, M j, Y').$time;
    }

    /**
     * Can the Director still decide this batch? Since D-108 the Director
     * picks the date and the hour, so there is no requested date that can go
     * stale and no requested span to confirm — a pending batch can always be
     * scheduled. Whether a CHOSEN day and hour fit is decided by approve()
     * under a lock.
     */
    public function isApprovable(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * D-109: did the college attach a signed memo? False for every batch
     * submitted before memos were required — those are never backfilled.
     */
    public function hasMemo(): bool
    {
        return $this->memo_path !== null;
    }

    /** D-109: is the memo a photo / image (shown with a thumbnail and zoom) rather than a PDF? */
    public function memoIsImage(): bool
    {
        return str_starts_with((string) $this->memo_mime, 'image/');
    }

    /** "Medical Clearance" / "Medical Assessment Form" (D-62). */
    public function formTypeLabel(): string
    {
        return self::FORM_TYPES[$this->form_type] ?? ucfirst((string) $this->form_type);
    }

    /**
     * The reason's printed label from this batch's form ("Others, Specify"
     * for others), or NULL for a key the form doesn't offer — a pre-D-62
     * reason such as 'graduation'.
     */
    public function reasonLabel(): ?string
    {
        return self::REASONS_BY_FORM[$this->form_type][$this->reason] ?? null;
    }

    /** Human-readable reason: the label, or the admin's own text for "others". */
    public function reasonText(): string
    {
        if ($this->reason === self::REASON_OTHERS && $this->reason_detail !== null) {
            return $this->reason_detail;
        }

        return $this->reasonLabel() ?? ucfirst($this->reason);
    }

    // ── Batch results (FR-ADM-12, D-111) ─────────────────────────────────────

    /**
     * The student statuses (Appointment::clearanceProgress()) that are not
     * final yet: the student may still come, or the clinic still has to encode
     * them. While any student reads one of these, the batch is not finished.
     */
    private const OPEN_PROGRESS = ['scheduled', 'rescheduled', 'rest_retake', 'in_clinic'];

    /**
     * D-111: where this batch stands, as ONE key — the views word and badge it.
     * The rules are tried in this order, on the server clock:
     *
     *   completed           — every student it holds has been encoded. Right
     *                         away, even at 3 PM on day 1 (Q11).
     *   scheduled           — its first clinic hour has not started yet.
     *   in_progress         — one of its clinic days is happening: from the
     *                         batch's earliest hour that day until the 8 PM
     *                         cutoff. Day 1, and its 2nd-chance day (D-110).
     *   still_in_progress   — no clinic day is happening, but some student is
     *                         not final yet (OPEN_PROGRESS): booked for the
     *                         2nd-chance day, or captured at the kiosk and still
     *                         waiting for the clinic's encode.
     *   no_one_attended     — finished, and every student is Unattended.
     *   finished_incomplete — finished, and some students are Unattended.
     *
     * D-110's job books the 2nd chances a few minutes after 8 PM, so in that
     * gap a missed student reads Unattended and the batch can read Finished
     * incomplete until the job runs (accepted by D-110).
     *
     * Reads the same held appointments as resultsSummary() and the rows of the
     * results page, so the badge, the cards and the table can never disagree.
     * Callers eager-load BatchResults::statusRelations().
     */
    public function resultsStatus(): string
    {
        $progress = $this->heldAppointments()
            ->map(fn (Appointment $appointment): string => $appointment->clearanceProgress());

        if ($progress->isNotEmpty() && $progress->every(fn (string $key): bool => $key === 'completed')) {
            return 'completed';
        }

        $firstStart = $this->firstHourStart();

        if ($firstStart !== null && now()->lt($firstStart)) {
            return 'scheduled';
        }

        if ($this->isClinicDayUnderway($firstStart) || $this->isClinicDayUnderway($this->secondChanceStart())) {
            return 'in_progress';
        }

        if ($progress->contains(fn (string $key): bool => in_array($key, self::OPEN_PROGRESS, true))) {
            return 'still_in_progress';
        }

        return $progress->every(fn (string $key): bool => $key === 'unattended')
            ? 'no_one_attended'
            : 'finished_incomplete';
    }

    /**
     * D-113: the batch statuses at which its college may ask the Director to
     * release the documents — every student is final. Not `no_one_attended`:
     * nobody was encoded, so there is nothing to release.
     */
    public const RELEASABLE_STATUSES = ['completed', 'finished_incomplete'];

    /**
     * D-113: may the college ask for this batch's documents yet? Reads
     * resultsStatus(), so callers eager-load BatchResults::statusRelations().
     */
    public function isReadyForRelease(): bool
    {
        return in_array($this->resultsStatus(), self::RELEASABLE_STATUSES, true);
    }

    /**
     * D-113: has the Director released this batch's documents to its college?
     * True once ANY request was approved — an approval never expires.
     */
    public function hasReleasedDocuments(): bool
    {
        return $this->releaseRequests()->where('status', 'approved')->exists();
    }

    /**
     * D-111: the numbers on the results page's three summary cards — how many
     * students the batch holds, how many are encoded, how many of those are
     * Fit, and how many were EVER given a 2nd chance (D-110). That last count
     * reads the roster's current appointment, which stays the 2nd-chance one
     * after the student completes, so a rescheduled student is still counted.
     *
     * @return array{total: int, completed: int, fit: int, rescheduled: int}
     */
    public function resultsSummary(): array
    {
        $held = $this->heldAppointments();

        return [
            'total' => $held->count(),
            'completed' => $held->filter(fn (Appointment $appointment): bool => $appointment->clearanceProgress() === 'completed')->count(),
            'fit' => $held->filter(fn (Appointment $appointment): bool => $appointment->clearanceResult() === 'Fit')->count(),
            'rescheduled' => $held->filter(fn (Appointment $appointment): bool => $appointment->isSecondChance())->count(),
        ];
    }

    /**
     * D-111: when this batch's 2nd-chance day starts — the date and earliest
     * hour of the 2nd-chance appointments on its roster — or null when nobody
     * on it was rescheduled. BR-27 books a batch's missed students together,
     * so there is at most one such day.
     */
    public function secondChanceStart(): ?Carbon
    {
        return $this->heldAppointments()
            ->filter(fn (Appointment $appointment): bool => $appointment->isSecondChance())
            ->map(fn (Appointment $appointment): Carbon => self::hourStart($appointment->scheduled_date, $appointment->scheduled_time))
            ->min();
    }

    /**
     * D-111: the first clinic day's start — its date at the batch's first hour
     * — or null while unscheduled. Public for D-120's badge: from this moment
     * the batch can no longer read Scheduled.
     */
    public function firstHourStart(): ?Carbon
    {
        return $this->scheduled_date === null ? null : self::hourStart($this->scheduled_date, $this->scheduled_time);
    }

    /**
     * Is a clinic day that starts at $start happening now — from that hour
     * until the absent cutoff (8:00 PM) that evening?
     */
    private function isClinicDayUnderway(?Carbon $start): bool
    {
        return $start !== null && now()->gte($start) && now()->lt(Appointment::absentCutoffOn($start));
    }

    /**
     * A clinic date at a slot key ('09:00:00'). A pre-D-37 row has no slot, so
     * it starts at the clinic's first hour (never a hardcoded 7 AM).
     */
    private static function hourStart(Carbon $date, ?string $slot): Carbon
    {
        $slot ??= app(ClinicScheduleService::class)->slots()[0];

        return Carbon::parse($date->toDateString().' '.$slot);
    }

    /**
     * Every roster row's appointment, minus the withdrawn ones.
     *
     * @return Collection<int, Appointment>
     */
    private function heldAppointments(): Collection
    {
        return $this->batchRequestStudents
            ->map(fn (BatchRequestStudent $row): ?Appointment => $row->appointment)
            ->filter(fn (?Appointment $appointment): bool => $appointment !== null
                && $appointment->clearanceProgress() !== 'withdrawn');
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The college this batch request was submitted for. */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /** The college admin who submitted this request. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The director who approved or rejected this request (null while pending). */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The college admin who cancelled this request (null unless cancelled).
     * A college may have more than one admin (D-47), so this is NOT the same
     * person as [[requester]] by construction.
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The individual student pivot rows included in this batch. */
    public function batchRequestStudents(): HasMany
    {
        return $this->hasMany(BatchRequestStudent::class);
    }

    /** The appointments this batch's approval created (none before approval). */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** D-113: every document release request the college made for this batch. */
    public function releaseRequests(): HasMany
    {
        return $this->hasMany(DocumentReleaseRequest::class);
    }

    /**
     * D-113: the college's most recent release request — the one the results
     * page's button state follows (pending / rejected / approved).
     */
    public function latestReleaseRequest(): HasOne
    {
        return $this->hasOne(DocumentReleaseRequest::class)->latestOfMany();
    }

    /**
     * D-125: the batch's APPROVED release request, or null. There is at most
     * one — approval is final and the college cannot ask again (D-113) — so
     * the clinic's Completed batches card eager-loads it for every row in one
     * query, for the Print Requested / Printed tag.
     */
    public function approvedReleaseRequest(): HasOne
    {
        return $this->hasOne(DocumentReleaseRequest::class)->where('status', 'approved');
    }
}
