<x-register.wizard-shell :step="3">

    <h2 class="mb-[6px] text-center text-[15px] font-bold text-hp-slate">Step 3 — Verify Your Email</h2>

    @if ($alreadyVerified)
    {{-- Revisited after a successful verification (e.g. browser Back from
         Link ID): the account exists, so no code boxes and no Start over. --}}
    <div class="mt-[14px] mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        <strong>Your email is already verified.</strong> You've completed this step.
    </div>

    <div class="flex justify-center">
        <a href="{{ route('register.link-id') }}"
           class="inline-flex items-center justify-center gap-2 rounded-full bg-hp-orange
                  px-6 py-2.5 text-sm font-semibold text-white transition-colors
                  duration-hp-fast hover:bg-orange-500">
            Continue →
        </a>
    </div>
    @else
    <p class="mb-[20px] text-center text-[13px] leading-[1.6] text-hp-slate/60">
        A 6-digit verification code was sent to<br>
        <strong class="text-hp-orange">{{ $email }}</strong>
    </p>

    {{-- Resend success flash --}}
    @if (session('status'))
        <div data-hp-flash class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    {{-- OTP error --}}
    @error('otp')
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $message }}
        </div>
    @enderror

    {{-- OTP boxes (shared with every OTP screen) --}}
    <x-otp.boxes :action="route('register.verify.submit')" />

    {{-- Resend (60s cooldown, server-enforced; countdown survives refresh) --}}
    <x-otp.resend-button
        :action="route('register.verify.resend')"
        :remaining="$resendRemaining"
    />

    {{-- Dev: where to find the OTP --}}
    @if (app()->isLocal())
        <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
            <p class="mb-1 font-semibold">Dev — reading the OTP from storage/logs/laravel.log</p>
            <p>The log mailer appends the full rendered email at the bottom of the file on every send.
               Open the file and scroll to the last <code>Message-ID:</code> block, or search for
               <code>Subject: HealthPass</code>. The OTP appears in the HTML body as a 6-digit number
               next to "Your verification code".</p>
            <p class="mt-1">Quick command:</p>
            <code class="block rounded bg-slate-100 px-2 py-1 mt-1 select-all">
                php artisan tinker --execute="echo file_get_contents(storage_path('logs/laravel.log'));" | tail -80
            </code>
        </div>
    @endif

    {{-- Start over --}}
    <div class="mt-6 flex justify-center">
        <a href="{{ route('register') }}" class="text-[12px] text-hp-slate/50 hover:underline">
            ← Start over
        </a>
    </div>
    @endif

</x-register.wizard-shell>
