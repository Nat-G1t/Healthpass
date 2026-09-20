{{-- ── Past Medical History & Family History (D-69) ──────────────────────────
     The front page's eighteen-row table, Medical Assessment Form visits only.
     Two ☐ Present columns per row — the patient's own history and the lineal
     family's — and, on the rows the paper gives a blank line, one specify box
     that only becomes typable once either box on that row is ticked.

     Names post as medical_history[patient][], medical_history[family][] and
     medical_history[specify][<key>], which is exactly the JSON shape the
     column stores. Everything is optional (Nat, 2026-09-18).
     Variables come from the parent view: $readOnly, $conditionChecked,
     $specifyValue.
──────────────────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Past Medical History &amp; Family History</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">
        Tick every condition present. Rows with a blank line on the form take a short note.
    </p>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-hp-slate/15 text-left">
                    <th class="py-2 pr-3 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                        Medical Condition / Disease
                    </th>
                    @foreach (\App\Models\MedicalAssessment::HISTORY_COLUMNS as $column => $heading)
                        <th class="w-32 px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                            {{ $heading }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-hp-slate/10">
                @foreach (\App\Models\MedicalAssessment::CONDITIONS as $key => $condition)
                    {{-- One tiny Alpine scope per row: the specify box enables
                         as soon as either checkbox on the row is ticked, and
                         disables again when both are cleared. Display logic
                         only — the server drops a specify text whose row was
                         never ticked anyway. --}}
                    <tr x-data="{
                            patient: {{ $conditionChecked('patient', $key) ? 'true' : 'false' }},
                            family: {{ $conditionChecked('family', $key) ? 'true' : 'false' }},
                            get present() { return this.patient || this.family; },
                        }">
                        <td class="py-2 pr-3 align-middle">
                            <span class="text-hp-slate/80">{{ $condition['label'] }}</span>
                            @if ($condition['specify'])
                                <input type="text"
                                       name="medical_history[specify][{{ $key }}]"
                                       value="{{ $specifyValue($key) }}"
                                       placeholder="{{ $condition['specify'] }}"
                                       maxlength="{{ \App\Models\MedicalAssessment::SPECIFY_MAX_LENGTH }}"
                                       @disabled($readOnly)
                                       @unless ($readOnly) :disabled="! present" @endunless
                                       class="mt-1 w-full max-w-xs rounded-lg border-[1.5px] border-hp-slate/25 px-2.5 py-1.5
                                              text-sm text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                                              focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                                              disabled:bg-hp-slate/5 disabled:cursor-not-allowed
                                              @error('medical_history.specify.'.$key) border-red-400 hp-anim-shake @enderror">
                                @error('medical_history.specify.'.$key)
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            @endif
                        </td>
                        @foreach (array_keys(\App\Models\MedicalAssessment::HISTORY_COLUMNS) as $column)
                            <td class="px-2 py-2 text-center align-middle">
                                <label class="inline-flex items-center gap-1.5 text-xs text-hp-slate/60
                                              {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                                    <input type="checkbox"
                                           name="medical_history[{{ $column }}][]"
                                           value="{{ $key }}"
                                           class="h-4 w-4 rounded text-hp-orange focus:ring-hp-orange"
                                           x-model="{{ $column }}"
                                           @checked($conditionChecked($column, $key))
                                           @disabled($readOnly)>
                                    Present
                                </label>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-hp.card>
