@props(['label' => ''])

{{--
    One cell of <x-hp.table-row>.

    Below `md` the cell is a flex row: the column name on the left (the
    <thead> is hidden down there, so each cell carries its own label) and the
    value on the right. At `md`+ the label disappears and the cell goes back
    to being a plain <td>.
--}}
<td {{ $attributes->merge([
    'class' => 'flex items-baseline justify-between gap-4 py-1 md:table-cell md:py-3 md:pr-4 md:last:pr-0',
]) }}>
    @if ($label !== '')
        <span class="shrink-0 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40 dark:text-hp-slate/55 md:hidden">
            {{ $label }}
        </span>
    @endif

    <span class="min-w-0 text-right md:text-left">{{ $slot }}</span>
</td>
