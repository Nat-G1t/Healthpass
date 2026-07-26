{{--
    One row of <x-hp.table>. Below `md` it is a bordered card; at `md`+ it is
    a normal <tr> and the card chrome falls away.
--}}
<tr {{ $attributes->merge([
    'class' => 'block rounded-lg border border-hp-slate/15 p-3 text-hp-slate md:table-row md:rounded-none md:border-0 md:p-0',
]) }}>
    {{ $slot }}
</tr>
