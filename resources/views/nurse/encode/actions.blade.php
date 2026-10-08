{{-- The encode page's button bar (FR-NRS-04/05), one branch per mode
     (moved out of nurse/encode.blade.php by D-126 to keep that page readable).
     Variables from the parent view: $mode, $readOnly, $canPrint, $isAssessment,
     $visit.
       'view'      → Reprint / Print front & back + Save as PDF (D-125: greyed
                     out until the batch is released)
       'interview' → the nurse's Submit to physician (D-126)
       'encode'    → the physician's Preview & Print + Save & Close
--}}
{{-- The Medical Assessment Form is TWO Letter pages printed
     back-to-back on one sheet (D-71/D-83), and clinic printers rarely
     duplex — so it gets Print front and Print back instead of one
     button. `name="side"` on a submit BUTTON posts that button's
     own value, which is how the two reach the same endpoint and
     ask for different pages. --}}
<div class="flex flex-col gap-2.5 pt-1">
    @if ($readOnly && ! $canPrint)
        {{-- D-125 (O2): not released yet — the buttons, greyed out,
             and why. The print routes refuse them too. --}}
        @foreach ($isAssessment ? ['Print front', 'Print back'] : ['Reprint'] as $label)
            <x-hp.button variant="soft" class="w-full" disabled data-print-disabled>{{ $label }}</x-hp.button>
        @endforeach
        <x-hp.button variant="ghost" class="w-full" disabled data-print-disabled>Save as PDF</x-hp.button>
        <p class="text-xs text-hp-slate/60" data-print-disabled-hint>{{ App\Models\ClinicVisit::NOT_RELEASED_HINT }}</p>
    @elseif ($readOnly)
        @if ($isAssessment)
            @foreach (['front' => 'Print front', 'back' => 'Print back'] as $side => $label)
                <x-hp.button type="submit" variant="soft" class="w-full"
                             data-print-trigger data-print-side="{{ $side }}"
                             name="side" value="{{ $side }}"
                             formaction="{{ route('nurse.visits.print.reprint', $visit) }}"
                             formtarget="hp-print-frame">
                    {{ $label }}
                </x-hp.button>
            @endforeach
        @else
        {{-- Reprint (FR-NRS-05): formaction re-routes this submit to
             the reprint endpoint, which re-stamps printed_at and
             returns the official form INTO the hidden iframe below
             (formtarget); the script then prints the iframe. --}}
        <x-hp.button type="submit" variant="soft" class="w-full"
                     data-print-trigger
                     formaction="{{ route('nurse.visits.print.reprint', $visit) }}"
                     formtarget="hp-print-frame">
            Reprint
        </x-hp.button>
        @endif

        {{-- Save as PDF (FR-PRT-06, D-67): a plain GET link, not a
             submit — the response is the same document rendered by
             dompdf and sent as a download, so nothing on this page
             navigates and printed_at is NOT re-stamped. --}}
        <a href="{{ route('nurse.visits.pdf', $visit) }}"
           class="inline-flex w-full items-center justify-center gap-2 rounded-full
                  border-[1.5px] border-hp-slate/30 px-6 py-2.5 text-sm font-semibold
                  text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/8
                  focus-visible:outline-none focus-visible:ring-2
                  focus-visible:ring-hp-slate focus-visible:ring-offset-1">
            Save as PDF
        </a>
    @elseif ($mode === 'interview')
        {{-- D-126: the nurse's only action — no print buttons, no Preview &
             Print. It moves the student to the physician's queue
             (InterviewController); the physician decides Fit / Unfit.
             data-pending-label: spinner + disable while it navigates. --}}
        <x-hp.button type="submit" variant="primary" class="w-full"
                     data-pending-label="Submitting…">Submit to physician</x-hp.button>
        <p class="text-xs text-hp-slate/50">
            The student moves to the physician's queue with everything you entered here.
        </p>
    @else
        {{-- Flipped to 1 once Preview & Print fires, so Save & Close
             can stamp printed_at (FR-NRS-05) — the clearance row
             doesn't exist yet at pre-save print time. --}}
        <input type="hidden" name="printed" id="hp-printed-flag" value="{{ old('printed', '0') }}">

        {{-- Preview & Print (FR-NRS-05): posts the CURRENT (unsaved)
             assessment to the preview route, targeted at the hidden
             iframe — Chrome's print dialog is the preview. The
             required Result radio gates this submit too. An
             Assessment posts the same way, once per side (D-71). --}}
        @if ($isAssessment)
            @foreach (['front' => 'Print front', 'back' => 'Print back'] as $side => $label)
                <x-hp.button type="submit" variant="ghost" class="w-full"
                             data-print-trigger data-print-side="{{ $side }}"
                             name="side" value="{{ $side }}"
                             formaction="{{ route('nurse.visits.print.preview', $visit) }}"
                             formtarget="hp-print-frame">
                    {{ $label }}
                </x-hp.button>
            @endforeach
        @else
            <x-hp.button type="submit" variant="ghost" class="w-full"
                         data-print-trigger
                         formaction="{{ route('nurse.visits.print.preview', $visit) }}"
                         formtarget="hp-print-frame">
                Preview &amp; Print
            </x-hp.button>
        @endif
        {{-- data-pending-label: spinner + disable while the save
             navigates (§5.6) — belt-and-braces double-submit
             protection on top of the controller's one-shot guard.
             The print buttons above are exempt automatically:
             their formtarget posts into the iframe, and
             page-motion.js keys off the SUBMITTER's target. --}}
        <x-hp.button type="submit" variant="primary" class="w-full"
                     data-pending-label="Saving…">Save &amp; Close</x-hp.button>
    @endif

    {{-- Shown once a FRONT print has fired (D-71): the two pages
         go on one sheet, so the sheet has to be fed back in. Which
         way up depends on the printer — see docs/qa. --}}
    @if ($isAssessment)
        <p id="hp-flip-hint" hidden
           class="rounded-xl border border-hp-peach bg-hp-peach/30 px-3 py-2 text-xs text-hp-slate">
            Put the printed sheet back in the tray, then click <span class="font-semibold">Print back</span>.
        </p>
    @endif
</div>
