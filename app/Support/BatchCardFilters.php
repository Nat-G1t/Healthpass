<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BatchRequest;
use App\Models\DocumentReleaseRequest;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * D-114 — ONE card's filters on a page that lists batches in two cards (the
 * Director's Batch Tracking, the clinic's Upcoming Batches): a Batch ID
 * search and a College dropdown.
 *
 * Each card reads its own keys from the query string, named after the card —
 * "upcoming_q" and "upcoming_college" for the clinic's Upcoming card, next to
 * its pager's "upcoming_page" — so filtering one card never changes the other.
 * The view's filter form (resources/views/batches/_card-filters.blade.php)
 * carries the other card's keys along as hidden fields.
 *
 * D-130 — a card may also OPT IN to more dropdowns (the Director's Batch
 * Tracking does; the clinic's cards do not, so they render exactly as before):
 *   month    "{prefix}_month"   — "YYYY-MM"; which date it means is the card's
 *                                 own business (see monthBounds())
 *   form     "{prefix}_form"    — the batch's form type
 *   status   "{prefix}_status"  — BatchRequest::resultsStatus(); computed, so
 *                                 the controller filters it in PHP
 *   release  "{prefix}_release" — the batch's LATEST document release request
 * Every value is checked against its own list; anything else reads as "All".
 *
 * `readonly`: once read from the request, the values never change.
 */
final readonly class BatchCardFilters
{
    /** D-130: the dropdowns a card can opt into, in the order they render. */
    public const EXTRAS = ['month', 'form', 'status', 'release'];

    /** D-130: the release-request dropdown — "none" means the batch has no request at all. */
    public const RELEASE_OPTIONS = [
        'none' => 'None',
        'pending' => 'Waiting for review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /**
     * @param  list<string>  $extras  the D-130 dropdowns this card shows
     */
    private function __construct(
        public string $prefix,
        public string $search,
        public int $collegeId,
        public array $extras = [],
        public string $month = '',
        public string $formType = '',
        public string $status = '',
        public string $release = '',
    ) {}

    /**
     * Read "{prefix}_q" and "{prefix}_college", plus the D-130 keys of the
     * $extras this card opted into. Anything malformed (an array, a
     * non-number college, a month like "13-2026", an unknown status) reads as
     * "no filter" rather than an error.
     *
     * @param  list<string>  $extras  any of self::EXTRAS
     */
    public static function fromRequest(Request $request, string $prefix, array $extras = []): self
    {
        $extras = array_values(array_intersect(self::EXTRAS, $extras));
        $read = fn (string $name, array $allowed): string => in_array($name, $extras, true)
            ? self::pick($request->query($prefix.'_'.$name), $allowed)
            : '';

        $search = $request->query($prefix.'_q');
        $college = $request->query($prefix.'_college');
        $month = $request->query($prefix.'_month');

        return new self(
            prefix: $prefix,
            search: is_string($search) ? trim($search) : '',
            collegeId: is_string($college) && ctype_digit($college) ? (int) $college : 0,
            extras: $extras,
            // A real month: four digits, a dash, 01–12.
            month: in_array('month', $extras, true) && is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
                ? $month
                : '',
            formType: $read('form', array_keys(BatchRequest::FORM_TYPES)),
            status: $read('status', array_keys(BatchRequest::RESULTS_STATUSES)),
            release: $read('release', array_keys(self::RELEASE_OPTIONS)),
        );
    }

    /** The value when it is one of $allowed, otherwise '' ("All"). */
    private static function pick(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Narrow a batch query to this card's SQL filters: the Batch ID contains
     * the search, the batch belongs to the picked college, has the picked
     * form, and its latest release request has the picked status. Every value
     * is a bound parameter, never pasted into the SQL. The month (its column
     * is the card's choice) and the status (computed) are the controller's.
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->search !== '', fn (Builder $batches) => $batches->where('reference_no', 'like', '%'.$this->search.'%'))
            ->when($this->collegeId > 0, fn (Builder $batches) => $batches->where('college_id', $this->collegeId))
            ->when($this->formType !== '', fn (Builder $batches) => $batches->where('form_type', $this->formType))
            ->when($this->release === 'none', fn (Builder $batches) => $batches->whereDoesntHave('releaseRequests'))
            ->when(
                $this->release !== '' && $this->release !== 'none',
                fn (Builder $batches) => $batches->where(self::latestReleaseStatus(), $this->release),
            );
    }

    /**
     * The status of the batch's newest release request (highest id) as a
     * subquery — plain ORDER BY + LIMIT, the same SQL in MySQL and SQLite.
     */
    private static function latestReleaseStatus(): Builder
    {
        return DocumentReleaseRequest::query()
            ->select('status')
            ->whereColumn('document_release_requests.batch_request_id', 'batch_requests.id')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * D-130: the picked month as [first day, first day of the NEXT month],
     * both 'Y-m-d', for `column >= first AND column < next` — computed here
     * so the SQL needs no date function (those differ per database). The half-open range also
     * covers a datetime's last evening and a date SQLite stores with
     * " 00:00:00". Null when no month is picked.
     *
     * @return array{0: string, 1: string}|null
     */
    public function monthBounds(): ?array
    {
        if ($this->month === '') {
            return null;
        }

        $first = CarbonImmutable::parse($this->month.'-01');

        return [$first->toDateString(), $first->addMonthNoOverflow()->toDateString()];
    }

    /**
     * D-130: the month dropdown's options — every month in $dates, newest
     * first, "YYYY-MM" => "October 2026". Nulls are skipped.
     *
     * @param  iterable<?DateTimeInterface>  $dates
     * @return array<string, string>
     */
    public static function monthOptions(iterable $dates): array
    {
        $options = collect($dates)
            ->filter()
            ->mapWithKeys(fn (DateTimeInterface $date) => [$date->format('Y-m') => $date->format('F Y')])
            ->all();

        krsort($options);

        return $options;
    }

    public function has(string $extra): bool
    {
        return in_array($extra, $this->extras, true);
    }

    public function isActive(): bool
    {
        return $this->search !== '' || $this->collegeId > 0
            || $this->month !== '' || $this->formType !== '' || $this->status !== '' || $this->release !== '';
    }

    /** The page parameter of this card's pager. */
    public function pageName(): string
    {
        return $this->prefix.'_page';
    }

    /**
     * The query-string keys this card owns. A new search drops them all — the
     * old page number may not exist in the new results.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [
            $this->prefix.'_q',
            $this->prefix.'_college',
            ...array_map(fn (string $extra) => $this->prefix.'_'.$extra, $this->extras),
            $this->pageName(),
        ];
    }
}
