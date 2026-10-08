{{-- D-126 — who interviewed this Medical Assessment student, and when: shown
     to the physician reviewing the nurse's entries, and on the read-only
     encoded page. Not on the nurse's own interview (there is none yet), nor
     on a Medical Clearance or a record from before D-126 (no interviewer).
     Variables from the parent view: $visit, $mode. --}}
@if ($mode !== 'interview' && $visit->interviewer)
    <p class="mt-1 text-sm text-hp-slate/70" data-interviewed-by>
        Interviewed by <span class="font-semibold">{{ $visit->interviewer->name }}</span>
        · {{ $visit->interviewed_at?->format('M j, Y g:i A') ?? '—' }}
    </p>
@endif
