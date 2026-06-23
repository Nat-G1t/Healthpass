<x-layout.sidebar title="My Records">

@php
    $visitData = $visits
        ->filter(fn ($v) => $v->clearanceRecord !== null)
        ->map(fn ($v) => [
            'id'            => $v->id,
            'reference_no'  => $v->reference_no,
            'date'          => ($v->checked_in_at ?? $v->created_at)->format('M j, Y'),
            'service'       => $v->appointment
                                   ? ucfirst($v->appointment->service_type) . ' Clearance'
                                   : 'Medical Clearance',
            'result'        => $v->clearanceRecord->result,
            'case_category' => $v->clearanceRecord->case_category,
            'encoded_at'    => ($v->clearanceRecord->encoded_at
                                   ?? $v->clearanceRecord->created_at)->format('M j, Y'),
            'vitals'        => [
                'height_cm'       => $v->vitalSigns?->height_cm,
                'weight_kg'       => $v->vitalSigns?->weight_kg,
                'bmi'             => $v->vitalSigns?->bmi,
                'temperature_c'   => $v->vitalSigns?->temperature_c,
                'heart_rate_bpm'  => $v->vitalSigns?->heart_rate_bpm,
                'bp_systolic'     => $v->vitalSigns?->bp_systolic,
                'bp_diastolic'    => $v->vitalSigns?->bp_diastolic,
                'is_bmi_flagged'  => $v->vitalSigns?->is_bmi_flagged ?? false,
                'is_temp_flagged' => $v->vitalSigns?->is_temp_flagged ?? false,
                'is_bp_flagged'   => $v->vitalSigns?->is_bp_flagged ?? false,
            ],
            'screening'     => [
                'vision'      => $v->screeningResponse?->vision ?? false,
                'hearing'     => $v->screeningResponse?->hearing ?? false,
                'nose'        => $v->screeningResponse?->nose ?? false,
                'skin'        => $v->screeningResponse?->skin ?? false,
                'respiratory' => $v->screeningResponse?->respiratory ?? false,
                'heart'       => $v->screeningResponse?->heart ?? false,
                'digestive'   => $v->screeningResponse?->digestive ?? false,
                'bones'       => $v->screeningResponse?->bones ?? false,
                'nervous'     => $v->screeningResponse?->nervous ?? false,
            ],
        ])
        ->values()
        ->toArray();
@endphp

<script>
function recordsPageData() {
    return {
        open: false,
        rec: null,
        records: @json($visitData),
        openRecord(id) {
            this.rec = this.records.find(r => r.id === id) ?? null;
            this.open = !!this.rec;
        },
    };
}
</script>

{{-- ── Page header ─────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">My Records</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Your clinic visit history and clearance results</p>
</div>

<div x-data="recordsPageData()">

    {{-- ── Clearance history card ───────────────────────────────────────────── --}}
    <x-hp.card>

        <h3 class="mb-5 text-sm font-semibold text-hp-slate">Clearance History</h3>

        @if ($visits->isEmpty())

            <div class="flex flex-col items-center justify-center py-10 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586
                                 a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No clinic visits yet</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    Your records will appear here after your first kiosk visit
                </p>
            </div>

        @else

            {{-- ── Mobile: stacked cards (hidden on sm+) ──────────────────────── --}}
            <div class="space-y-3 sm:hidden">
                @foreach ($visits as $visit)
                @php
                    $isEncoded = $visit->clearanceRecord !== null;
                    $service   = $visit->appointment
                                   ? ucfirst($visit->appointment->service_type) . ' Clearance'
                                   : 'Medical Clearance';
                    $date      = ($visit->checked_in_at ?? $visit->created_at)->format('M j, Y');
                @endphp
                <div class="rounded-xl border border-hp-slate/10 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-hp-slate/50">{{ $date }}</p>
                            <p class="mt-0.5 text-sm font-medium text-hp-orange">{{ $service }}</p>
                            <p class="mt-1 font-mono text-xs text-hp-slate/35">{{ $visit->reference_no }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            @if ($isEncoded)
                                <x-hp.badge :variant="$visit->clearanceRecord->result === 'Fit' ? 'fit' : 'unfit'">
                                    {{ $visit->clearanceRecord->result }}
                                </x-hp.badge>
                                <button type="button"
                                        @click="openRecord({{ $visit->id }})"
                                        class="text-xs font-semibold text-hp-orange hover:underline
                                               focus:outline-none">
                                    View
                                </button>
                            @else
                                <x-hp.badge variant="pending">Pending</x-hp.badge>
                                <span class="text-xs text-hp-slate/30">—</span>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── Desktop: table (hidden below sm) ───────────────────────────── --}}
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hp-slate/15">
                            <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                       tracking-widest text-hp-slate/40 font-normal">Date</th>
                            <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                       tracking-widest text-hp-slate/40 font-normal">Service</th>
                            <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                       tracking-widest text-hp-slate/40 font-normal">Result</th>
                            <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                       tracking-widest text-hp-slate/40 font-normal">Reference No.</th>
                            <th class="pb-3 text-[11px] font-semibold uppercase
                                       tracking-widest text-hp-slate/40 font-normal text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hp-slate/10">
                        @foreach ($visits as $visit)
                        @php
                            $isEncoded = $visit->clearanceRecord !== null;
                            $service   = $visit->appointment
                                           ? ucfirst($visit->appointment->service_type) . ' Clearance'
                                           : 'Medical Clearance';
                            $date      = ($visit->checked_in_at ?? $visit->created_at)->format('M j, Y');
                        @endphp
                        <tr>
                            <td class="py-4 pr-6 text-sm text-hp-slate/60 whitespace-nowrap">
                                {{ $date }}
                            </td>
                            <td class="py-4 pr-6 text-sm font-medium text-hp-orange whitespace-nowrap">
                                {{ $service }}
                            </td>
                            <td class="py-4 pr-6">
                                @if ($isEncoded)
                                    <x-hp.badge :variant="$visit->clearanceRecord->result === 'Fit' ? 'fit' : 'unfit'">
                                        {{ $visit->clearanceRecord->result }}
                                    </x-hp.badge>
                                @else
                                    <x-hp.badge variant="pending">Pending</x-hp.badge>
                                @endif
                            </td>
                            <td class="py-4 pr-6 font-mono text-xs text-hp-slate/40 whitespace-nowrap">
                                {{ $visit->reference_no }}
                            </td>
                            <td class="py-4 text-right whitespace-nowrap">
                                @if ($isEncoded)
                                    <button type="button"
                                            @click="openRecord({{ $visit->id }})"
                                            class="text-sm font-semibold text-hp-orange
                                                   hover:underline focus:outline-none">
                                        View
                                    </button>
                                @else
                                    <span class="text-xs text-hp-slate/30">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

    </x-hp.card>


    {{-- ── Detail modal ────────────────────────────────────────────────────────
         ~30% bigger than before (max-w-xl → max-w-3xl).
         Overlay scrolls so the box never clips on short screens.
         Body columns stack on mobile, side-by-side on sm+.
    ─────────────────────────────────────────────────────────────────────────── --}}
    <div x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background-color: rgba(75,85,99,0.45);">

        <div @click.outside="open = false"
             class="w-full max-w-3xl max-h-[90vh] overflow-y-auto
                    rounded-2xl bg-white shadow-xl">

            {{-- Modal header --}}
            <div class="sticky top-0 flex items-start justify-between gap-4
                        rounded-t-2xl border-b border-hp-slate/10 bg-white px-6 py-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold text-hp-slate"
                            x-text="rec ? rec.reference_no : ''"></h3>
                        <template x-if="rec">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5
                                         text-[11px] font-semibold leading-none"
                                  :class="rec.result === 'Fit'
                                          ? 'bg-hp-peach text-hp-orange'
                                          : 'bg-hp-slate/10 text-hp-slate'"
                                  x-text="rec.result">
                            </span>
                        </template>
                    </div>
                    <p class="mt-0.5 text-xs text-hp-slate/50"
                       x-text="rec ? rec.service + ' · Encoded ' + rec.encoded_at : ''"></p>
                </div>
                <button type="button" @click="open = false"
                        class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center
                               rounded-full text-hp-slate/40 transition-colors
                               hover:bg-hp-slate/10 hover:text-hp-slate focus:outline-none">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Modal body: stacked on mobile, 2-col on sm+ --}}
            <template x-if="rec">
                <div class="grid grid-cols-1 divide-y divide-hp-slate/10
                            sm:grid-cols-2 sm:divide-y-0 sm:divide-x">

                    {{-- Left: Vitals + case category --}}
                    <div class="p-6">
                        <p class="mb-4 text-[11px] font-semibold uppercase
                                  tracking-widest text-hp-slate/40">Vital Signs</p>

                        <dl class="space-y-3">

                            <div class="flex items-center justify-between">
                                <dt class="text-sm text-hp-slate/55">Height</dt>
                                <dd class="text-sm font-medium text-hp-slate"
                                    x-text="rec.vitals.height_cm + ' cm'"></dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt class="text-sm text-hp-slate/55">Weight</dt>
                                <dd class="text-sm font-medium text-hp-slate"
                                    x-text="rec.vitals.weight_kg + ' kg'"></dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt class="text-sm text-hp-slate/55">BMI</dt>
                                <dd class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-hp-slate"
                                          x-text="rec.vitals.bmi"></span>
                                    <span x-show="rec.vitals.is_bmi_flagged"
                                          class="rounded-full bg-hp-peach px-2 py-0.5
                                                 text-[11px] font-semibold text-hp-orange">
                                        Flagged
                                    </span>
                                </dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt class="text-sm text-hp-slate/55">Temperature</dt>
                                <dd class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-hp-slate"
                                          x-text="rec.vitals.temperature_c + ' °C'"></span>
                                    <span x-show="rec.vitals.is_temp_flagged"
                                          class="rounded-full bg-hp-peach px-2 py-0.5
                                                 text-[11px] font-semibold text-hp-orange">
                                        Flagged
                                    </span>
                                </dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt class="text-sm text-hp-slate/55">Heart Rate</dt>
                                <dd class="text-sm font-medium text-hp-slate"
                                    x-text="rec.vitals.heart_rate_bpm + ' bpm'"></dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt class="text-sm text-hp-slate/55">Blood Pressure</dt>
                                <dd class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-hp-slate"
                                          x-text="rec.vitals.bp_systolic + '/' + rec.vitals.bp_diastolic + ' mmHg'"></span>
                                    <span x-show="rec.vitals.is_bp_flagged"
                                          class="rounded-full bg-hp-peach px-2 py-0.5
                                                 text-[11px] font-semibold text-hp-orange">
                                        Flagged
                                    </span>
                                </dd>
                            </div>

                        </dl>

                        <template x-if="rec.case_category">
                            <div class="mt-5 border-t border-hp-slate/10 pt-4">
                                <p class="mb-2 text-[11px] font-semibold uppercase
                                          tracking-widest text-hp-slate/40">Case Category</p>
                                <span class="inline-flex items-center rounded-full bg-hp-peach
                                             px-3 py-1 text-xs font-semibold text-hp-orange"
                                      x-text="rec.case_category">
                                </span>
                            </div>
                        </template>
                    </div>

                    {{-- Right: 9-system questionnaire --}}
                    <div class="p-6">
                        <p class="mb-4 text-[11px] font-semibold uppercase
                                  tracking-widest text-hp-slate/40">Questionnaire</p>

                        <dl class="space-y-3">
                            <template x-for="q in [
                                {key: 'vision',      label: 'Vision'},
                                {key: 'hearing',     label: 'Hearing'},
                                {key: 'nose',        label: 'Nose / Throat'},
                                {key: 'skin',        label: 'Skin'},
                                {key: 'respiratory', label: 'Respiratory'},
                                {key: 'heart',       label: 'Heart'},
                                {key: 'digestive',   label: 'Digestive'},
                                {key: 'bones',       label: 'Bones / Joints'},
                                {key: 'nervous',     label: 'Nervous System'}
                            ]" :key="q.key">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-hp-slate/55" x-text="q.label"></dt>
                                    <dd>
                                        <span class="inline-flex items-center rounded-full
                                                     px-2.5 py-0.5 text-[11px] font-semibold
                                                     leading-none"
                                              :class="rec.screening[q.key]
                                                      ? 'bg-hp-peach text-hp-orange'
                                                      : 'bg-hp-slate/10 text-hp-slate'"
                                              x-text="rec.screening[q.key] ? 'Yes' : 'No'">
                                        </span>
                                    </dd>
                                </div>
                            </template>
                        </dl>
                    </div>

                </div>
            </template>

        </div>
    </div>

</div>{{-- /x-data --}}

</x-layout.sidebar>
