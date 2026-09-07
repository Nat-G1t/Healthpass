@props(['headers' => []])

{{--
    Responsive data table (mobile-first).

    A plain `overflow-x-auto` wrapper makes the whole page slide sideways on a
    phone, so instead we re-flow the table itself with Tailwind display
    utilities: below `md` every element becomes a block (each row is a stacked
    label/value card), at `md`+ the native table display comes back and it
    renders exactly like an ordinary table.

    Blade "components" are just reusable view fragments — `<x-hp.table>` maps
    to this file. Use it with <x-hp.table-row> and <x-hp.table-cell>:

        <x-hp.table :headers="['Batch ID', 'Status']">
            @foreach ($rows as $row)
                <x-hp.table-row>
                    <x-hp.table-cell label="Batch ID">{{ $row->reference_no }}</x-hp.table-cell>
                    <x-hp.table-cell label="Status">…</x-hp.table-cell>
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

    The `label` on each cell is what the mobile card shows in place of the
    column header (the real <thead> is hidden below `md`), so it should match
    the matching entry in `$headers`.
--}}
<table {{ $attributes->merge(['class' => 'block w-full text-left text-sm md:table']) }}>

    @if (! empty($headers))
        <thead class="hidden md:table-header-group">
            <tr class="border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40">
                @foreach ($headers as $header)
                    <th class="py-2.5 pr-4 font-semibold last:pr-0">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
    @endif

    <tbody class="block space-y-3 md:table-row-group md:space-y-0 md:divide-y md:divide-hp-slate/10">
        {{ $slot }}
    </tbody>
</table>
