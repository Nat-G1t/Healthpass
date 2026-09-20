{{-- ── Pertinent Physical Examination (D-70) ─────────────────────────────────
     The back page's eight groups A–H, each a checkbox list plus its own
     "Others:" line. NO sex gating here — every student gets all eight, which
     is why the DRE group carries the paper's own "Not Applicable" box.

     "Essentially Normal" is not exclusive with the findings under it: the
     paper lets the examiner tick both, and inventing a rule the form does not
     have would lose what the clinician meant.

     Posts as physical_exam[<group>][findings][] and physical_exam[<group>][others].
     Variables from the parent view: $readOnly, $finding, $findingOthers.
──────────────────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Pertinent Physical Examination</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">Tick every finding observed; each group has its own Others line.</p>

    {{-- Two columns on a wide screen, three on a very wide one, stacked on a
         phone — the paper's three columns do not fit a narrow encode column. --}}
    <div class="mt-4 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
        @foreach (\App\Models\MedicalAssessment::PHYSICAL_EXAM as $group => $section)
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    {{ $section['label'] }}
                </p>
                <div class="mt-1.5 space-y-1.5">
                    @foreach ($section['findings'] as $key => $label)
                        <label class="flex items-start gap-1.5 text-sm text-hp-slate/70
                                      {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                            <input type="checkbox" name="physical_exam[{{ $group }}][findings][]" value="{{ $key }}"
                                   class="mt-0.5 h-4 w-4 rounded text-hp-orange focus:ring-hp-orange"
                                   @checked($finding($group, $key))
                                   @disabled($readOnly)>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <input type="text" name="physical_exam[{{ $group }}][others]"
                       value="{{ $findingOthers($group) }}"
                       maxlength="{{ \App\Models\MedicalAssessment::SPECIFY_MAX_LENGTH }}"
                       placeholder="Others"
                       aria-label="{{ $section['label'] }} — Others"
                       @disabled($readOnly)
                       class="mt-2 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-1.5 text-sm
                              text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                              focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                              disabled:bg-hp-slate/5 disabled:cursor-not-allowed
                              @error('physical_exam.'.$group.'.others') border-red-400 hp-anim-shake @enderror">
                @error('physical_exam.'.$group.'.others')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>
</x-hp.card>
