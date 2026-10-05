<x-layout.sidebar title="Flagged Anomalies">

    {{-- ── Year filter (D-102) ─────────────────────────────────────────────
         Scopes the cards AND the table to visits checked in that year. Like
         the Analytics filters it reloads on change (GET, so the URL says
         what is on screen); the years come from the server clock. --}}
    <form method="GET" action="{{ route('director.anomalies') }}" class="mb-4 flex flex-wrap items-center gap-3">
        <label for="anomalies-year" class="sr-only">Year</label>
        <select id="anomalies-year" name="year"
                class="rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
            @foreach ($years as $yearOption)
                <option value="{{ $yearOption }}" @selected($yearOption === $year)>{{ $yearOption }}</option>
            @endforeach
        </select>
        <p class="text-[11px] text-hp-slate/40">Cards and table show visits checked in during {{ $year }}</p>
    </form>

    <script>
        (function () {
            const select = document.getElementById('anomalies-year');
            select.addEventListener('change', () => select.form.submit());
            // Back / Forward can restore this page with the select still on
            // the year that navigated away — put it back to the year shown.
            window.addEventListener('pageshow', () => select.form.reset());
        })();
    </script>

    {{-- ── Stat cards (FR-ANL-05): one per flag type ─────────────────────
         Subtitles quote the thresholds from config/healthpass.php (BR-13:
         one source — kiosk badges, queue flags, and this screen agree).
         Five since D-66 — three at two columns, five at three. --}}
    <div class="hp-stagger mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="hp-card-title">
                High Blood Pressure
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['bp'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                {{ config('healthpass.thresholds.bp_systolic') }}/{{ config('healthpass.thresholds.bp_diastolic') }} mmHg or higher
            </p>
        </x-hp.card>

        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="hp-card-title">
                Fever
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['temp'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                above {{ config('healthpass.thresholds.temperature_max') }}&deg;C
            </p>
        </x-hp.card>

        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="hp-card-title">
                Abnormal BMI
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['bmi'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                BMI under {{ config('healthpass.thresholds.bmi_normal_min') }} or {{ config('healthpass.thresholds.bmi_normal_max') }} and above
            </p>
        </x-hp.card>

        {{-- D-66 --}}
        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="hp-card-title">
                High Heart Rate
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['hr'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                above {{ config('healthpass.thresholds.heart_rate_max') }} bpm
            </p>
        </x-hp.card>

        {{-- D-66. The one flag the kiosk cannot raise: the clinic measures the
             respiratory rate at encode (D-65), so it counts encoded visits. --}}
        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="hp-card-title">
                Abnormal Respiratory Rate
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['rr'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                outside {{ config('healthpass.thresholds.respiratory_rate_min') }}&ndash;{{ config('healthpass.thresholds.respiratory_rate_max') }} breaths/min
            </p>
        </x-hp.card>
    </div>

    {{-- ── Flagged visits table (FR-ANL-05) ─────────────────────────────── --}}
    <x-hp.card>
        <div class="mb-2 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-hp-slate">Flagged Visits</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Vitals over a flag threshold &mdash; screening signals, not diagnoses.
                    Includes visits still awaiting the clinic's encode (flags surface from capture).
                </p>
            </div>
            <div class="flex items-center gap-3">
                <x-hp.badge :variant="$visits->isNotEmpty() ? 'flagged' : 'neutral'">
                    {{ $visits->total() }} flagged
                </x-hp.badge>
            </div>
        </div>

        @if ($visits->isEmpty())
            <div class="flex flex-col items-center py-14 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No flagged visits in {{ $year }}</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    Kiosk vitals that trip a flag threshold will appear here.
                </p>
            </div>
        @else
            {{-- ── Phones and small tablets (below md): one card per visit ────────
                 The table needs 720 px, so on a phone it scrolled sideways inside
                 its card and hid View. Each card shows what its row does — the
                 student, the college, each flag beside its value, and View. --}}
            <ul class="space-y-3 md:hidden" data-anomaly-cards>
                @foreach ($visits as $visit)
                    @php $flags = $visit->vitalSigns?->flagDetails() ?? []; @endphp
                    <li class="rounded-xl border border-hp-slate/15 p-4 text-hp-slate">
                        <div class="flex items-baseline justify-between gap-4">
                            <p class="min-w-0 break-words text-sm font-semibold">{{ $visit->student->name ?? '—' }}</p>
                            {{-- Capture-time college snapshot (FR-STU-09, D-17) --}}
                            <p class="shrink-0 text-xs text-hp-slate/60">{{ $visit->college->code ?? '—' }}</p>
                        </div>

                        <ul class="mt-3 space-y-1.5">
                            @foreach ($flags as $flag)
                                <li class="flex items-center justify-between gap-4">
                                    <x-hp.badge variant="flagged">{{ $flag['label'] }}</x-hp.badge>
                                    <span class="shrink-0 text-xs font-bold text-hp-orange">{{ $flag['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ route('director.anomalies.show', ['visit' => $visit, 'year' => $year]) }}"
                           class="mt-3 inline-block text-xs font-semibold text-hp-orange hover:underline">
                            View
                        </a>
                    </li>
                @endforeach
            </ul>

            {{-- ── md and up: the table ────────────────────────────────────────────
                 `relative` keeps the header's screen-reader-only "View" label
                 (absolutely positioned) inside this scroll box. Without it, that
                 label escaped the box and stretched the whole page sideways. --}}
            <div class="relative hidden overflow-x-auto md:block">
                <table class="w-full min-w-[720px] text-left text-sm text-hp-slate">
                    <thead>
                        <tr class="border-b border-gray-200 text-[10px] uppercase tracking-wider text-hp-slate/40">
                            <th class="py-2 pr-3 font-semibold">Student</th>
                            <th class="px-3 py-2 font-semibold">College</th>
                            <th class="px-3 py-2 font-semibold">Flag</th>
                            <th class="px-3 py-2 font-semibold">Value</th>
                            <th class="py-2 pl-3 font-semibold"><span class="sr-only">View</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($visits as $visit)
                            @php $flags = $visit->vitalSigns?->flagDetails() ?? []; @endphp
                            <tr>
                                <td class="py-3 pr-3 font-semibold">{{ $visit->student->name ?? '—' }}</td>
                                {{-- Capture-time college snapshot (FR-STU-09, D-17) --}}
                                <td class="px-3 py-3 text-xs text-hp-slate/60">{{ $visit->college->code ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-col items-start gap-1.5">
                                        @foreach ($flags as $flag)
                                            <x-hp.badge variant="flagged">{{ $flag['label'] }}</x-hp.badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    {{-- Same order as the badges beside them --}}
                                    <div class="flex flex-col gap-1.5 text-xs font-bold leading-[18px] text-hp-orange">
                                        @foreach ($flags as $flag)
                                            <span>{{ $flag['value'] }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="py-3 pl-3 text-right">
                                    <a href="{{ route('director.anomalies.show', ['visit' => $visit, 'year' => $year]) }}"
                                       class="text-xs font-semibold text-hp-orange hover:underline">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-hp.pager :paginator="$visits" />
        @endif
    </x-hp.card>

</x-layout.sidebar>
