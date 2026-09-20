{{-- ── III. Family Planning Access (D-69) ────────────────────────────────────
     One Yes / No pair from the back page. Neither box ticked means "not
     answered" — which is exactly what the column's NULL says; it is not a No.

     Posts as family_planning_access.
     Variables from the parent view: $readOnly, $familyPlanning.
──────────────────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">III. Family Planning Access</h3>

    <div class="mt-3">
        <span class="text-sm text-hp-slate/70">With access to family planning counseling?</span>
        <div class="mt-1.5 flex items-center gap-5">
            {{-- The values are STRINGS on purpose: PHP turns a numeric array
                 key into an int, and '1' === 1 is false — which would leave the
                 saved answer unchecked on the read-only view. --}}
            @foreach (['Yes' => '1', 'No' => '0'] as $label => $value)
                <label class="inline-flex items-center gap-1.5 text-sm text-hp-slate/70
                              {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                    <input type="radio" name="family_planning_access" value="{{ $value }}"
                           class="accent-hp-orange"
                           @checked($familyPlanning() === $value)
                           @disabled($readOnly)>
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>
</x-hp.card>
