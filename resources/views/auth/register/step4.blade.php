<x-register.wizard-shell :step="4">

    {{-- Icon --}}
    <div class="mb-5 flex justify-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-hp-peach">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"
                 fill="none" stroke="#FF8C2A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <path d="M3 9h18"/>
                <path d="M9 21V9"/>
            </svg>
        </div>
    </div>

    <h2 class="mb-2 text-center text-lg font-bold text-hp-slate">Link Your Student ID</h2>
    <p class="mb-6 text-center text-sm text-hp-slate/60">
        Scan your physical student ID's QR code to bind it to your account.
        You can also skip this and link it later from My ID &amp; Profile.
    </p>

    {{-- Placeholder notice --}}
    <div class="mb-6 rounded-lg border border-dashed border-hp-peach bg-hp-peach/20 px-5 py-4 text-sm text-hp-slate/70">
        <p class="mb-1 font-semibold text-hp-orange">Step 4 — Coming next sprint</p>
        <p>QR-linked student ID (FR-REG-06) will be implemented here.
           The USB keyboard-wedge scanner inputs the QR token into a focused field.</p>
    </div>

    {{-- Skip for now --}}
    <a href="{{ route('student.dashboard') }}"
       class="block w-full rounded-lg bg-hp-orange py-2.5 text-center text-sm
              font-semibold text-white hover:bg-hp-orange/90 transition-opacity
              focus:outline-none focus:ring-2 focus:ring-hp-orange/50">
        Skip for now — Go to Dashboard →
    </a>

    <p class="mt-3 text-center text-xs text-hp-slate/50">
        Your account is ready. You can link your ID at any time from your profile.
    </p>

</x-register.wizard-shell>
