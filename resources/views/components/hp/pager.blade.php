@props(['paginator'])

{{--
    The house pager (FR-UI-06): "Showing X–Y of N" on the left, Previous /
    Page n of m / Next on the right. Pass it any LengthAwarePaginator:

        <x-hp.pager :paginator="$records" />

    Written out by hand rather than $paginator->links() so it uses the hp-*
    tokens like the rest of the app. A disabled Previous/Next is a <span>, not
    a dead <a>. The controller calls withQueryString() on the paginator, so its
    URLs carry the page's active filters. Renders nothing when everything fits
    on one page, so callers stay one line.
--}}
@if ($paginator->hasPages())
    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-hp-slate/10 pt-4">
        <p class="text-xs text-hp-slate/50">
            Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </p>

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="rounded-lg border border-hp-slate/15 px-3 py-1.5 text-xs font-medium text-hp-slate/30">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}"
                   class="rounded-lg border border-hp-slate/25 px-3 py-1.5 text-xs font-medium text-hp-slate hover:bg-hp-slate/8">Previous</a>
            @endif

            <span class="px-1 text-xs text-hp-slate/50">
                Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}"
                   class="rounded-lg border border-hp-slate/25 px-3 py-1.5 text-xs font-medium text-hp-slate hover:bg-hp-slate/8">Next</a>
            @else
                <span class="rounded-lg border border-hp-slate/15 px-3 py-1.5 text-xs font-medium text-hp-slate/30">Next</span>
            @endif
        </div>
    </div>
@endif
