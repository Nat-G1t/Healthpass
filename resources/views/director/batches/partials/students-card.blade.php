{{--
    "Students in this batch" — Card 2 of the Review page (FR-DIRA-07) and of
    a rejected or cancelled batch's details page (FR-DIRA-10, D-112).

    Ten rows tall, then it scrolls inside the card under a sticky header —
    no pager (Q13).

    Takes $students: the batch's BatchRequestStudent rows, loaded with
    `student:id,name` and
    `student.studentProfile:id,user_id,student_number,course,year_level`.
--}}
<x-hp.card>
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Students in this batch <span class="ml-1 normal-case tracking-normal text-hp-slate/50">({{ $students->count() }})</span>
    </p>

    {{-- 2.75rem rows: the header plus ten rows is 30.25rem. --}}
    <div class="max-h-[30.25rem] overflow-auto rounded-lg border border-hp-slate/10">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 z-10 bg-hp-white">
                <tr class="h-11 border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40">
                    <th class="whitespace-nowrap px-4 font-semibold">Name</th>
                    <th class="whitespace-nowrap pr-4 font-semibold">Student No.</th>
                    <th class="whitespace-nowrap pr-4 font-semibold">Program</th>
                    <th class="whitespace-nowrap pr-4 font-semibold">Year</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hp-slate/10">
                @foreach ($students as $row)
                    <tr class="h-11 text-hp-slate">
                        <td class="whitespace-nowrap px-4 font-medium">{{ $row->student?->name ?? 'Unknown student' }}</td>
                        <td class="whitespace-nowrap pr-4 text-hp-slate/70">{{ $row->student?->studentProfile?->student_number ?? '—' }}</td>
                        <td class="whitespace-nowrap pr-4 text-hp-slate/70">{{ $row->student?->studentProfile?->course ?? '—' }}</td>
                        <td class="whitespace-nowrap pr-4 text-hp-slate/70">{{ $row->student?->studentProfile?->year_level ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-hp.card>
