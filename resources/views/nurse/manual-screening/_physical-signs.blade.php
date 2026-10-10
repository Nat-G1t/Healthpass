{{-- ── Physical Signs Disorder of (D-63) + pregnancy (D-79) ────────────────────
     The official form's twelve rows, as the kiosk asks them: a Yes / No per
     row, and a details box that opens under a Yes. On a Medical Clearance a
     Yes needs details of at least 3 characters (D-75); on a Medical Assessment
     they stay optional. Pregnancy and LMP are asked of female students only —
     for anyone else the server drops whatever is posted.

     Posts as screening[<row>], screening[details][<row>], screening[isPregnant]
     and screening[lastMenstrualPeriod].
     Variables from the parent view: $formType, $isFemale.
──────────────────────────────────────────────────────────────────────────── --}}
@php
    $detailsRequired = $formType === 'clearance';
    $detailMin = \App\Models\ScreeningResponse::DETAIL_MIN_LENGTH;
    $detailMax = \App\Models\ScreeningResponse::DETAIL_MAX_LENGTH;

    $textClass = 'mt-1.5 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2 text-sm
                  text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                  focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none';
@endphp

<x-hp.card>
    <h3 class="text-sm font-bold text-hp-slate">Physical Signs Disorder of</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">
        Ask the student Yes or No for each.
        {{ $detailsRequired ? "A Yes needs details (at least {$detailMin} characters)." : 'Details under a Yes are optional.' }}
    </p>

    <div class="mt-3 grid gap-x-8 sm:grid-cols-2">
        @foreach (\App\Models\ScreeningResponse::QUESTIONS as $key => $question)
            {{-- The values are STRINGS so old() ('1' / '0') compares exactly. --}}
            <div class="border-b border-hp-slate/10 py-2.5"
                 x-data="{ yes: {{ Js::from(old("screening.{$key}") === '1') }} }">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-hp-slate/80">
                        {{ $question['label'] }}
                        <span class="block text-[11px] text-hp-slate/45">{{ $question['helper'] }}</span>
                    </span>
                    <div class="flex shrink-0 items-center gap-4">
                        @foreach (['Yes' => '1', 'No' => '0'] as $label => $value)
                            <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm text-hp-slate/70">
                                <input type="radio" name="screening[{{ $key }}]" value="{{ $value }}"
                                       class="border-hp-slate/40 text-hp-orange focus:ring-hp-orange" required
                                       @checked(old("screening.{$key}") === $value)
                                       @change="yes = {{ $value === '1' ? 'true' : 'false' }}">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                @error("screening.{$key}")
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror

                <div x-show="yes" @unless (old("screening.{$key}") === '1') x-cloak @endunless>
                    <label for="manual-detail-{{ $key }}" class="sr-only">{{ $question['label'] }} details</label>
                    <input type="text" id="manual-detail-{{ $key }}" name="screening[details][{{ $key }}]"
                           value="{{ old("screening.details.{$key}") }}"
                           maxlength="{{ $detailMax }}"
                           placeholder="{{ $detailsRequired ? 'Details (required)' : 'Details (optional)' }}"
                           class="{{ $textClass }} @error("screening.details.{$key}") border-red-400 hp-anim-shake @enderror">
                    @error("screening.details.{$key}")
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endforeach
    </div>

    {{-- D-79: female students only. The server decides from the profile;
         leaving the rows out here is only a courtesy. --}}
    @if ($isFemale)
        <div class="mt-4 border-t border-hp-slate/10 pt-3"
             x-data="{ pregnant: {{ Js::from(old('screening.isPregnant') === '1') }} }">
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-hp-slate/80">Currently pregnant?</span>
                <div class="flex shrink-0 items-center gap-4">
                    @foreach (['Yes' => '1', 'No' => '0'] as $label => $value)
                        <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm text-hp-slate/70">
                            <input type="radio" name="screening[isPregnant]" value="{{ $value }}"
                                   class="border-hp-slate/40 text-hp-orange focus:ring-hp-orange" required
                                   @checked(old('screening.isPregnant') === $value)
                                   @change="pregnant = {{ $value === '1' ? 'true' : 'false' }}">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            @error('screening.isPregnant')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror

            <div class="mt-2 max-w-xs" x-show="pregnant" @unless (old('screening.isPregnant') === '1') x-cloak @endunless>
                <label for="manual-lmp" class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    Last menstrual period
                </label>
                <input type="date" id="manual-lmp" name="screening[lastMenstrualPeriod]"
                       value="{{ old('screening.lastMenstrualPeriod') }}"
                       max="{{ today()->toDateString() }}"
                       class="{{ $textClass }} @error('screening.lastMenstrualPeriod') border-red-400 hp-anim-shake @enderror">
                @error('screening.lastMenstrualPeriod')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    @endif
</x-hp.card>
