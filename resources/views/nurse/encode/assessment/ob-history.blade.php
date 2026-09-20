{{-- ── VI. OB/Pregnancy History (D-70) ───────────────────────────────────────
     Female students only, same gate as section V: greyed and disabled for
     anyone else, and ignored server-side regardless of what is posted.

     Past Surgical History sits under this section on paper but is NOT
     female-only — it is its own partial (D-69) and stays open to everyone.

     Posts as ob_history[<field>]. Every field is optional.
     Variables from the parent view: $readOnly, $isFemale, $ob, $yesNo.
──────────────────────────────────────────────────────────────────────────── --}}
@php
    $lock = $readOnly || ! $isFemale;

    $ranges = \App\Models\MedicalAssessment::OB_RANGES;

    // The paper's "Gravida: __ Para: __ T: __ P: __ A: __ L: __" line.
    $counts = [
        'gravida' => 'Gravida',
        'para' => 'Para',
        'term' => 'T',
        'preterm' => 'P',
        'abortion' => 'A',
        'living' => 'L',
    ];

    $inputClass = 'mt-1 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2 text-sm
                   text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                   focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                   disabled:bg-hp-slate/5 disabled:cursor-not-allowed';
@endphp

<x-hp.card class="{{ $isFemale ? '' : 'opacity-50' }}">
    <div class="flex items-baseline justify-between gap-3">
        <h3 class="text-sm font-semibold text-hp-slate">VI. OB/Pregnancy History</h3>
        @unless ($isFemale)
            <span class="rounded-full bg-hp-slate/10 px-2.5 py-0.5 text-[11px] font-semibold text-hp-slate/60">
                For female students only
            </span>
        @endunless
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-6">
        @foreach ($counts as $key => $label)
            <div>
                <label for="ob-{{ $key }}"
                       class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $label }}</label>
                <input type="number" id="ob-{{ $key }}" name="ob_history[{{ $key }}]"
                       value="{{ $ob($key) }}"
                       min="{{ $ranges[$key][0] }}" max="{{ $ranges[$key][1] }}" step="1" inputmode="numeric"
                       @disabled($lock)
                       class="{{ $inputClass }} @error('ob_history.'.$key) border-red-400 hp-anim-shake @enderror">
                @error('ob_history.'.$key)
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap items-end gap-x-8 gap-y-3">
        <div class="min-w-[16rem] flex-1">
            <label for="ob-delivery-type"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Type of Delivery</label>
            <input type="text" id="ob-delivery-type" name="ob_history[delivery_type]"
                   value="{{ $ob('delivery_type') }}"
                   maxlength="{{ \App\Models\MedicalAssessment::SPECIFY_MAX_LENGTH }}"
                   placeholder="e.g. Normal spontaneous delivery"
                   @disabled($lock)
                   class="{{ $inputClass }} @error('ob_history.delivery_type') border-red-400 hp-anim-shake @enderror">
            @error('ob_history.delivery_type')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <span class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Pregnancy Induced Hypertension
            </span>
            <div class="mt-1.5 flex items-center gap-5">
                @foreach (['Yes' => '1', 'No' => '0'] as $label => $value)
                    <label class="inline-flex items-center gap-1.5 text-sm text-hp-slate/70
                                  {{ $lock ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <input type="radio" name="ob_history[pih]" value="{{ $value }}"
                               class="accent-hp-orange"
                               @checked($yesNo('ob_history', 'pih') === $value)
                               @disabled($lock)>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>
    </div>
</x-hp.card>
