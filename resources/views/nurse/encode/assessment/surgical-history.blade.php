{{-- ── Past Surgical History / Procedures (D-69) ─────────────────────────────
     The back page's last pair of lines, open to EVERY student regardless of
     sex, exactly as the paper is. "Date Done" is free text on purpose:
     patients recall "2019" or "Grade 5", not a calendar date.

     Posts as surgical_history[procedures] and surgical_history[date_done].
     Variables from the parent view: $readOnly, $surgical.
──────────────────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Past Surgical History / Procedures</h3>

    <div class="mt-3 space-y-3">
        <div>
            <label for="surgical-procedures"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Procedures</label>
            <input type="text" id="surgical-procedures" name="surgical_history[procedures]"
                   value="{{ $surgical('procedures') }}"
                   placeholder="e.g. Appendectomy"
                   maxlength="{{ \App\Models\MedicalAssessment::PROCEDURES_MAX_LENGTH }}"
                   @disabled($readOnly)
                   class="mt-1 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2 text-sm
                          text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                          focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                          disabled:bg-hp-slate/5 disabled:cursor-not-allowed
                          @error('surgical_history.procedures') border-red-400 hp-anim-shake @enderror">
            @error('surgical_history.procedures')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label for="surgical-date-done"
                   class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Date Done</label>
            <input type="text" id="surgical-date-done" name="surgical_history[date_done]"
                   value="{{ $surgical('date_done') }}"
                   placeholder="e.g. September, 23, 2019"
                   maxlength="{{ \App\Models\MedicalAssessment::DATE_DONE_MAX_LENGTH }}"
                   @disabled($readOnly)
                   class="mt-1 w-full max-w-xs rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2 text-sm
                          text-hp-slate placeholder-hp-slate/40 transition-colors duration-hp-fast
                          focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                          disabled:bg-hp-slate/5 disabled:cursor-not-allowed
                          @error('surgical_history.date_done') border-red-400 hp-anim-shake @enderror">
            <p class="mt-1 text-[11px] text-hp-slate/45">Free text — whatever the student recalls.</p>
            @error('surgical_history.date_done')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-hp.card>
