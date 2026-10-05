<?php

declare(strict_types=1);

namespace App\Support;

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
 * `readonly`: once read from the request, the values never change.
 */
final readonly class BatchCardFilters
{
    private function __construct(
        public string $prefix,
        public string $search,
        public int $collegeId,
    ) {}

    /**
     * Read "{prefix}_q" and "{prefix}_college". Anything malformed (an array,
     * a non-number college) reads as "no filter" rather than an error.
     */
    public static function fromRequest(Request $request, string $prefix): self
    {
        $search = $request->query($prefix.'_q');
        $college = $request->query($prefix.'_college');

        return new self(
            prefix: $prefix,
            search: is_string($search) ? trim($search) : '',
            collegeId: is_string($college) && ctype_digit($college) ? (int) $college : 0,
        );
    }

    /**
     * Narrow a batch query to this card's filters: the Batch ID contains the
     * search, and the batch belongs to the picked college. Both values are
     * bound parameters, never pasted into the SQL.
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->search !== '', fn (Builder $batches) => $batches->where('reference_no', 'like', '%'.$this->search.'%'))
            ->when($this->collegeId > 0, fn (Builder $batches) => $batches->where('college_id', $this->collegeId));
    }

    public function isActive(): bool
    {
        return $this->search !== '' || $this->collegeId > 0;
    }

    /** The page parameter of this card's pager. */
    public function pageName(): string
    {
        return $this->prefix.'_page';
    }

    /**
     * The query-string keys this card owns. A new search drops all three — the
     * old page number may not exist in the new results.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [$this->prefix.'_q', $this->prefix.'_college', $this->pageName()];
    }
}
