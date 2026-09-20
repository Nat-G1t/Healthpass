{{-- ── Physical Signs — the official form's twelve rows (D-63) ─────────────────
     Your own answers at the kiosk, with any detail you typed under a Yes.

     On a Medical Clearance the clinic ALSO records its own finding per row
     (`ps_*`), and that column is what prints on the paper — so it is shown
     beside your answer rather than hidden. The Medical Assessment Form has no
     such column: there the table is headed "(Self Assessment)" and your own
     answers are what print, so only they are shown.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $sr     = $visit->screeningResponse;
    $record = $visit->clearanceRecord;

    // D-69: the Assessment's twelve rows ARE the student's, never re-encoded.
    $showsClinicColumn = $visit->formType() !== 'assessment';
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">
        {{ $showsClinicColumn ? 'Physical Signs' : 'Physical Signs Disorder of (Self Assessment)' }}
    </h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">
        @if ($showsClinicColumn)
            Your answers at the kiosk, and the clinic's finding for each — the clinic's is what prints.
        @else
            Your own answers at the kiosk — these are what print on your Medical Assessment Form.
        @endif
    </p>

    @if ($sr)
        <dl class="mt-3">
            @foreach (\App\Models\ScreeningResponse::QUESTIONS as $key => $question)
                @php
                    $answer = $sr->{$key};
                    $detail = $sr->detailFor($key);
                @endphp
                <div class="border-b border-hp-slate/10 py-2.5 last:border-0">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                        <dt class="min-w-0 text-sm text-hp-slate/70">{{ $question['label'] }}</dt>
                        <dd class="flex shrink-0 items-center gap-3">
                            <span class="flex items-center gap-1.5">
                                <span class="text-[11px] uppercase tracking-wide text-hp-slate/35">You</span>
                                <x-hp.answer :answer="$answer" />
                            </span>
                            @if ($showsClinicColumn)
                                <span class="flex items-center gap-1.5">
                                    <span class="text-[11px] uppercase tracking-wide text-hp-slate/35">Clinic</span>
                                    <x-hp.answer :answer="$record->{'ps_'.$key}" />
                                </span>
                            @endif
                        </dd>
                    </div>
                    @if ($answer && $detail !== null)
                        <p class="mt-1 text-xs text-hp-slate/60">{{ $detail }}</p>
                    @endif
                </div>
            @endforeach

            {{-- Pregnancy and last menstrual period, straight from the kiosk. --}}
            <div class="border-t border-hp-slate/10 pt-2.5">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                    <dt class="min-w-0 text-sm text-hp-slate/70">
                        Currently pregnant
                        @if ($sr->is_pregnant)
                            <span class="block text-xs text-hp-slate/45">
                                Last menstrual period: {{ $sr->last_menstrual_period?->format('F j, Y') ?? '—' }}
                            </span>
                        @endif
                    </dt>
                    <dd class="shrink-0"><x-hp.answer :answer="$sr->is_pregnant" /></dd>
                </div>
            </div>
        </dl>
    @else
        <p class="mt-3 text-sm text-hp-slate/40">No questionnaire was recorded for this visit.</p>
    @endif
</x-hp.card>
