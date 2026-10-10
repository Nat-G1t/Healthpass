{{-- ── Vital Signs (D-133) ────────────────────────────────────────────────────
     The kiosk's six readings, typed by the nurse, posted in the kiosk's own
     shape (vitals[height] … vitals[heartRate]) so they pass the kiosk's own
     rules. On a Medical Assessment the respiratory rate is added — the
     interview's seventh vital (D-65).

     Alpine.js (the app's small reactive JS library) shows the BMI live as
     height and weight are typed. Display only: the server recomputes BMI
     (FR-KSK-09) and every flag (BR-14) and ignores anything sent for them.

     Variables from the parent view: $formType.
──────────────────────────────────────────────────────────────────────────── --}}
@php
    // The kiosk key posted → the clinic's field for the same reading, whose
    // label, unit, step and config bounds (FR-KSK-08) the input uses.
    $vitalFields = \App\Http\Requests\Nurse\ManualScreeningRequest::VITAL_FIELDS;

    if ($formType === 'assessment') {
        $vitalFields['respiratory_rate'] = 'respiratory_rate';
    }

    // vitals[height] for the kiosk's six; respiratory_rate on its own.
    $inputName = fn (string $posted) => $posted === 'respiratory_rate' ? $posted : "vitals[{$posted}]";
    $errorKey  = fn (string $posted) => $posted === 'respiratory_rate' ? $posted : "vitals.{$posted}";
@endphp

<x-hp.card>
    <h3 class="text-sm font-bold text-hp-slate">Vital Signs</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">
        Measure and enter each reading.
        @if ($formType === 'assessment') The respiratory rate is part of the interview. @endif
        Readings above the limits are flagged for the physician — there is no rest &amp; re-check here.
    </p>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
         x-data="{
             heightCm: {{ Js::from(old('vitals.height')) }},
             weightKg: {{ Js::from(old('vitals.weight')) }},
             get bmi() {
                 const metres = Number(this.heightCm) / 100;
                 const kg = Number(this.weightKg);
                 if (!(metres > 0) || !(kg > 0)) return '—';
                 return (kg / (metres * metres)).toFixed(1);
             },
         }">
        @foreach ($vitalFields as $posted => $field)
            @php
                $vital = \App\Models\ClearanceRecord::ENCODED_VITALS[$field];
                $bounds = config("healthpass.validation.{$vital['bounds']}");
            @endphp
            <div>
                <label for="manual-vital-{{ $posted }}"
                       class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    {{ $vital['label'] }} <span class="normal-case tracking-normal">({{ $vital['unit'] }})</span>
                </label>
                <input type="number" id="manual-vital-{{ $posted }}" name="{{ $inputName($posted) }}"
                       value="{{ old($errorKey($posted)) }}"
                       step="{{ $vital['step'] }}" min="{{ $bounds['min'] }}" max="{{ $bounds['max'] }}"
                       inputmode="{{ $vital['step'] === '1' ? 'numeric' : 'decimal' }}"
                       required
                       @if ($posted === 'height') x-model="heightCm" @endif
                       @if ($posted === 'weight') x-model="weightKg" @endif
                       class="mt-1 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2
                              text-lg font-bold text-hp-slate transition-colors duration-hp-fast
                              focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                              @error($errorKey($posted)) border-red-400 hp-anim-shake @enderror">
                @error($errorKey($posted))
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        {{-- BMI is never typed — computed live here, recomputed on the server. --}}
        <div>
            <span class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">BMI</span>
            <p class="mt-1 rounded-lg bg-hp-bg px-3 py-2 text-lg font-bold text-hp-slate" x-text="bmi">—</p>
            <p class="mt-1 text-[11px] text-hp-slate/45">From height &amp; weight</p>
        </div>
    </div>
</x-hp.card>
