<x-layout.sidebar :title="$page">

    <div class="flex flex-col items-center justify-center py-20 text-center">
        <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-hp-peach">
            <svg class="h-7 w-7 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h2 class="text-base font-semibold text-hp-slate">Coming soon</h2>
        <p class="mt-1 max-w-xs text-sm text-hp-slate/60">
            {{ $page }} will be available in an upcoming update.
        </p>
        <a href="{{ route('student.dashboard') }}"
           class="mt-6 text-sm font-semibold text-hp-orange hover:underline">
            ← Back to Dashboard
        </a>
    </div>

</x-layout.sidebar>
