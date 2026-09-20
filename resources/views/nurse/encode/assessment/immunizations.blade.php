{{-- ── II. Immunization Profile (D-69) ───────────────────────────────────────
     The back page's four groups of tick boxes plus the "Others:" line. The two
     "None" boxes belong to different groups and mean different things, so they
     are separate keys (child_none, adult_none).

     Posts as immunizations[given][] and immunizations[others] — the JSON shape
     the column stores. Optional, like every section on this form.
     Variables from the parent view: $readOnly, $immunizationChecked,
     $immunizationOthers.
──────────────────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">II. Immunization Profile</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">Tick every vaccine the student reports having received.</p>

    <div class="mt-4 space-y-4">
        @foreach (\App\Models\MedicalAssessment::IMMUNIZATIONS as $group => $vaccines)
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $group }}</p>
                <div class="mt-1.5 flex flex-wrap gap-x-5 gap-y-2">
                    @foreach ($vaccines as $key => $label)
                        <label class="inline-flex items-center gap-1.5 text-sm text-hp-slate/70
                                      {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                            <input type="checkbox" name="immunizations[given][]" value="{{ $key }}"
                                   class="h-4 w-4 rounded text-hp-orange focus:ring-hp-orange"
                                   @checked($immunizationChecked($key))
                                   @disabled($readOnly)>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div>
            <label for="immunizations-others"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Others</label>
            <input type="text" id="immunizations-others" name="immunizations[others]"
                   value="{{ $immunizationOthers() }}"
                   placeholder="Any other vaccine"
                   maxlength="{{ \App\Models\MedicalAssessment::IMMUNIZATION_OTHERS_MAX_LENGTH }}"
                   @disabled($readOnly)
                   class="mt-1 w-full max-w-md rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2 text-sm
                          text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                          focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                          disabled:bg-hp-slate/5 disabled:cursor-not-allowed
                          @error('immunizations.others') border-red-400 hp-anim-shake @enderror">
            @error('immunizations.others')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-hp.card>
