{{-- ── I. Personal / Social History (D-68) ─────────────────────────────────────
     Your own answers to section I of the Medical Assessment Form's back page,
     given at the kiosk. A Medical Clearance visit never asks these, which is
     why the parent view only includes this partial for an Assessment.
──────────────────────────────────────────────────────────────────────────────── --}}

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">I. Personal / Social History</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">Your own answers at the kiosk.</p>

    <dl class="mt-3 grid gap-x-8 sm:grid-cols-2">
        @foreach ($visit->screeningResponse?->socialHistoryRows() ?? [] as $row)
            <div class="flex items-center justify-between gap-3 border-b border-hp-slate/10 py-2">
                <dt class="text-sm text-hp-slate/70">{{ $row['label'] }}</dt>
                <dd class="shrink-0"><x-hp.answer :answer="$row['answer']" /></dd>
            </div>
        @endforeach
    </dl>
</x-hp.card>
