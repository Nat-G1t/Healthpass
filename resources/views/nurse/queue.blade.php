<x-layout.sidebar title="Live Queue">

@php
    $count = $visits->count();
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────────────
     Blinking LIVE pill + a live count. "updated just now" is static on this
     first pass — polling (FR-NRS-02) replaces it with a real timestamp tomorrow.
────────────────────────────────────────────────────────────────────────────── --}}
<div class="mb-7 flex flex-wrap items-center justify-between gap-3">
    <div>
        <div class="flex items-center gap-2.5">
            <h2 class="text-xl font-semibold text-hp-slate">Live Queue</h2>

            {{-- Blinking LIVE pill: the pulsing dot reads as "receiving updates". --}}
            <span class="inline-flex items-center gap-1.5 rounded-full bg-hp-orange px-2.5 py-1
                         text-[10px] font-bold uppercase tracking-widest text-white">
                <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                Live
            </span>
        </div>
        <p class="mt-0.5 text-sm text-hp-slate/50">
            {{ $count }} {{ \Illuminate\Support\Str::plural('student', $count) }} waiting · updated just now
        </p>
    </div>
</div>

<x-hp.card>

    @if ($count === 0)

        {{-- ── Empty state — the queue is clear ───────────────────────────────── --}}
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">Queue is clear</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                Students appear here the moment they finish at the kiosk
            </p>
        </div>

    @else

        {{-- Horizontal scroll keeps the full table intact on narrow screens;
             the nurse terminal is a desktop, so the table is the primary view. --}}
        <div class="overflow-x-auto">
            {{-- border-separate (not the default collapse) so the NEXT row's
                 rounded corners actually clip; border-spacing-y gives every row —
                 the highlight especially — breathing room instead of butting flush. --}}
            <table class="w-full text-left border-separate border-spacing-x-0 border-spacing-y-1.5">
                <thead>
                    <tr class="[&>th]:border-b [&>th]:border-hp-slate/15">
                        <th class="pb-3 pl-4 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Student</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">College</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Vitals Summary</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Flags</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Time</th>
                        <th class="pb-3 pr-4 text-right text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visits as $visit)
                    @php
                        $isNext = $loop->first;   // FCFS: the top row is longest-waiting.
                        $vs     = $visit->vitalSigns;

                        // Initials avatar — same treatment as the sidebar footer.
                        $initials = collect(explode(' ', $visit->student->name ?? ''))
                            ->map(fn ($w) => strtoupper(substr($w, 0, 1)))
                            ->take(2)
                            ->implode('');

                        // Capture time = kiosk check-in (frozen at submit). Fall
                        // back to created_at only if a legacy row lacks it.
                        $capturedAt = $visit->checked_in_at ?? $visit->created_at;

                        // A vital's value renders bold orange when its stored flag
                        // is set (values are server-computed at submit — never here).
                        $flagged = 'font-bold text-hp-orange';
                        $normal  = 'text-hp-slate/70';

                        // NEXT row highlight: the peach fill is applied PER CELL (not
                        // on the <tr>) so the rounded end-caps clip cleanly — the table
                        // is border-separate, and the row's own vertical spacing gives
                        // the band air above and below.
                        $hi = $isNext ? 'bg-hp-peach/45' : '';
                    @endphp
                    <tr>

                        {{-- Student: avatar + name, NEXT tag on the top row --}}
                        <td class="py-4 pl-4 pr-6 whitespace-nowrap {{ $hi }} {{ $isNext ? 'rounded-l-2xl' : '' }}">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                                            bg-hp-peach text-xs font-bold text-hp-orange">
                                    {{ $initials }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-hp-slate">
                                        {{ $visit->student->name ?? '—' }}
                                    </p>
                                    @if ($isNext)
                                        <span class="mt-0.5 inline-flex items-center rounded-full bg-hp-orange
                                                     px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-white">
                                            Next
                                        </span>
                                    @else
                                        <p class="font-mono text-[11px] text-hp-slate/35">{{ $visit->reference_no }}</p>
                                    @endif
                                </div>
                            </div>
                        </td>

                        {{-- College (capture-time snapshot) --}}
                        <td class="py-4 pr-6 text-sm text-hp-slate/60 whitespace-nowrap {{ $hi }}">
                            {{ $visit->college->name ?? '—' }}
                        </td>

                        {{-- Inline vitals summary — flagged values bold orange --}}
                        <td class="py-4 pr-6 text-sm whitespace-nowrap {{ $hi }}">
                            @if ($vs)
                                <div class="flex items-center gap-x-3">
                                    <span class="{{ $vs->is_temp_flagged ? $flagged : $normal }}">
                                        {{ $vs->temperature_c }}°C
                                    </span>
                                    <span class="text-hp-slate/20">·</span>
                                    <span class="{{ $vs->is_bp_flagged ? $flagged : $normal }}">
                                        {{ $vs->bp_systolic }}/{{ $vs->bp_diastolic }}
                                    </span>
                                    <span class="text-hp-slate/20">·</span>
                                    <span class="{{ $vs->is_bmi_flagged ? $flagged : $normal }}">
                                        BMI {{ $vs->bmi }}
                                    </span>
                                    <span class="text-hp-slate/20">·</span>
                                    <span class="{{ $normal }}">{{ $vs->heart_rate_bpm }} bpm</span>
                                </div>
                            @else
                                <span class="text-hp-slate/30">—</span>
                            @endif
                        </td>

                        {{-- Flags column — a badge per flagged vital, or a dash --}}
                        <td class="py-4 pr-6 whitespace-nowrap {{ $hi }}">
                            @if ($vs && ($vs->is_temp_flagged || $vs->is_bp_flagged || $vs->is_bmi_flagged))
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($vs->is_temp_flagged)
                                        <x-hp.badge variant="flagged">Temp</x-hp.badge>
                                    @endif
                                    @if ($vs->is_bp_flagged)
                                        <x-hp.badge variant="flagged">BP</x-hp.badge>
                                    @endif
                                    @if ($vs->is_bmi_flagged)
                                        <x-hp.badge variant="flagged">BMI</x-hp.badge>
                                    @endif
                                </div>
                            @else
                                <span class="text-hp-slate/30">—</span>
                            @endif
                        </td>

                        {{-- Capture time, humanized (e.g. "2m ago") --}}
                        <td class="py-4 pr-6 text-sm text-hp-slate/50 whitespace-nowrap {{ $hi }}">
                            {{ $capturedAt?->diffForHumans() ?? '—' }}
                        </td>

                        {{-- Encode Result — stub until the encode flow lands (FR-NRS-03).
                             Primary on the top row, ghost on the rest (FR-NRS-01). --}}
                        <td class="py-4 pr-4 text-right whitespace-nowrap {{ $hi }} {{ $isNext ? 'rounded-r-2xl' : '' }}">
                            <x-hp.button type="button" size="sm"
                                         :variant="$isNext ? 'primary' : 'ghost'">
                                Encode Result
                            </x-hp.button>
                        </td>

                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    @endif

</x-hp.card>

</x-layout.sidebar>
