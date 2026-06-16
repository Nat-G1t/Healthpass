<x-register.wizard-shell :step="3">

    {{-- Centred icon --}}
    <div class="mb-5 flex justify-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-hp-peach">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"
                 fill="none" stroke="#FF8C2A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="16" rx="2"/>
                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
            </svg>
        </div>
    </div>

    <h2 class="mb-2 text-center text-lg font-bold text-hp-slate">Check Your Email</h2>

    <p class="mb-1 text-center text-sm text-hp-slate/70">
        We sent a 6-digit verification code to
    </p>
    <p class="mb-6 text-center text-sm font-semibold text-hp-orange">
        {{ $email }}
    </p>

    {{-- Placeholder notice --}}
    <div class="mb-6 rounded-lg border border-dashed border-hp-peach bg-hp-peach/20 px-5 py-4 text-sm text-hp-slate/70">
        <p class="mb-1 font-semibold text-hp-orange">Step 3 — Coming next sprint</p>
        <p>
            Email OTP verification (FR-REG-04) will be implemented here. The six-digit
            auto-advancing input, Resend link, and rate-limited verification logic will
            complete this step.
        </p>
    </div>

    {{-- What comes next --}}
    <div class="mb-6 space-y-2 text-sm text-hp-slate/70">
        <div class="flex items-start gap-2">
            <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-hp-peach text-[10px] font-bold text-hp-orange">3</span>
            <span>Enter the 6-digit code — verifies your email ownership</span>
        </div>
        <div class="flex items-start gap-2">
            <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-hp-slate/10 text-[10px] font-bold text-hp-slate/40">4</span>
            <span class="text-hp-slate/40">Link your physical student ID (or skip for now)</span>
        </div>
    </div>

    {{-- Dev info box (only in local) --}}
    @if (app()->isLocal())
        <div class="mb-6 rounded-lg bg-slate-50 border border-slate-200 px-4 py-3 text-xs text-slate-500">
            <span class="font-semibold">Dev note:</span>
            Staged session data is at <code>session('reg.info')</code>.
            Check <code>storage/logs/laravel.log</code> for the OTP once mail is wired up.
        </div>
    @endif

    {{-- Action --}}
    <div class="flex justify-center">
        <a href="{{ route('register') }}"
           class="text-sm text-hp-slate/60 hover:underline">
            ← Start over
        </a>
    </div>

</x-register.wizard-shell>
