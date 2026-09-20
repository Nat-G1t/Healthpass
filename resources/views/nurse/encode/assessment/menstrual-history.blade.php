{{-- ── V. Menstrual History (D-70) ───────────────────────────────────────────
     Female students only: for anyone else the whole card greys out and every
     input is disabled. The disabled attribute is a courtesy — the rule lives
     on the server (StoreClearanceRequest), which validates and stores these
     fields ONLY for a female student and writes NULL for everyone else.

     Posts as menstrual_history[<field>]. Every field is optional.
     Variables from the parent view: $readOnly, $isFemale, $menstrual, $yesNo.
──────────────────────────────────────────────────────────────────────────── --}}
@php
    // Locked when the visit is already encoded OR the student is not female.
    $lock = $readOnly || ! $isFemale;

    $ranges = \App\Models\MedicalAssessment::MENSTRUAL_RANGES;

    // label + unit per numeric box. The min/max come from the model constant,
    // so the input and the validation cannot drift apart.
    $boxes = [
        'menarche_age' => ['Menarche', 'yrs old'],
        'first_intercourse_age' => ['Onset of sexual intercourse', 'yrs old'],
        'period_days' => ['Period Duration', 'days'],
        'pads_per_day' => ['No. of Pads per Day', ''],
        'cycle_days' => ['Interval Cycle', 'days'],
    ];

    $inputClass = 'mt-1 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2 text-sm
                   text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                   focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                   disabled:bg-hp-slate/5 disabled:cursor-not-allowed';
@endphp

<x-hp.card class="{{ $isFemale ? '' : 'opacity-50' }}">
    <div class="flex items-baseline justify-between gap-3">
        <h3 class="text-sm font-semibold text-hp-slate">V. Menstrual History</h3>
        @unless ($isFemale)
            <span class="rounded-full bg-hp-slate/10 px-2.5 py-0.5 text-[11px] font-semibold text-hp-slate/60">
                For female students only
            </span>
        @endunless
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        @foreach ($boxes as $key => $box)
            <div>
                <label for="menstrual-{{ $key }}"
                       class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    {{ $box[0] }}
                    @if ($box[1])
                        <span class="normal-case tracking-normal">({{ $box[1] }})</span>
                    @endif
                </label>
                <input type="number" id="menstrual-{{ $key }}" name="menstrual_history[{{ $key }}]"
                       value="{{ $menstrual($key) }}"
                       min="{{ $ranges[$key][0] }}" max="{{ $ranges[$key][1] }}" step="1" inputmode="numeric"
                       placeholder="{{ $ranges[$key][0] }}–{{ $ranges[$key][1] }}"
                       @disabled($lock)
                       class="{{ $inputClass }} @error('menstrual_history.'.$key) border-red-400 hp-anim-shake @enderror">
                @error('menstrual_history.'.$key)
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        {{-- LMP pre-fills from the kiosk's own answer when the student gave
             one (D-70); the clinic can still correct it. --}}
        <div>
            <label for="menstrual-lmp"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Last Menstrual Period
            </label>
            <input type="date" id="menstrual-lmp" name="menstrual_history[lmp]"
                   value="{{ $menstrual('lmp') }}"
                   max="{{ today()->toDateString() }}"
                   @disabled($lock)
                   class="{{ $inputClass }} @error('menstrual_history.lmp') border-red-400 hp-anim-shake @enderror">
            @error('menstrual_history.lmp')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="menstrual-contraceptive"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Contraceptive Method Used
            </label>
            <input type="text" id="menstrual-contraceptive" name="menstrual_history[contraceptive]"
                   value="{{ $menstrual('contraceptive') }}"
                   maxlength="{{ \App\Models\MedicalAssessment::SPECIFY_MAX_LENGTH }}"
                   placeholder="e.g. None"
                   @disabled($lock)
                   class="{{ $inputClass }} @error('menstrual_history.contraceptive') border-red-400 hp-anim-shake @enderror">
            @error('menstrual_history.contraceptive')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Menopause: a Yes / No pair plus the age it happened. Neither box
         ticked means "not answered", which is what the JSON's null says. --}}
    <div class="mt-4 flex flex-wrap items-end gap-x-8 gap-y-3">
        <div>
            <span class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Menopause</span>
            <div class="mt-1.5 flex items-center gap-5">
                @foreach (['Yes' => '1', 'No' => '0'] as $label => $value)
                    <label class="inline-flex items-center gap-1.5 text-sm text-hp-slate/70
                                  {{ $lock ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <input type="radio" name="menstrual_history[menopause]" value="{{ $value }}"
                               class="accent-hp-orange"
                               @checked($yesNo('menstrual_history', 'menopause') === $value)
                               @disabled($lock)>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <label for="menstrual-menopause-age"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Age <span class="normal-case tracking-normal">(yrs)</span>
            </label>
            <input type="number" id="menstrual-menopause-age" name="menstrual_history[menopause_age]"
                   value="{{ $menstrual('menopause_age') }}"
                   min="{{ $ranges['menopause_age'][0] }}" max="{{ $ranges['menopause_age'][1] }}"
                   step="1" inputmode="numeric"
                   placeholder="{{ $ranges['menopause_age'][0] }}–{{ $ranges['menopause_age'][1] }}"
                   @disabled($lock)
                   class="{{ $inputClass }} !w-28 @error('menstrual_history.menopause_age') border-red-400 hp-anim-shake @enderror">
            @error('menstrual_history.menopause_age')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-hp.card>
