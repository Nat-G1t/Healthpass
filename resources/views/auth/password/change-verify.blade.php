<x-layout.sidebar title="Confirm Password Change">

<div class="mx-auto max-w-md">

    <x-hp.card>
        <div class="mb-6 text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-6 w-6 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <h2 class="text-base font-bold text-hp-slate">Confirm Your Password Change</h2>
            <p class="mt-1.5 text-[13px] leading-relaxed text-hp-slate/60">
                A 6-digit verification code was sent to<br>
                <strong class="text-hp-orange break-all">{{ $email }}</strong>
            </p>
            <p class="mt-1 text-xs text-hp-slate/45">
                Your password won't change until you enter the code below.
            </p>
        </div>

        {{-- Resend success flash --}}
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        {{-- OTP error --}}
        @error('otp')
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $message }}
            </div>
        @enderror

        <x-otp.boxes
            :action="route('password.change.verify.submit')"
            button-label="Verify &amp; Update Password"
        />

        <x-otp.resend-button
            :action="route('password.change.verify.resend')"
            :remaining="$resendRemaining"
        />

        {{-- Cancel — abandon the change, keep current password --}}
        <div class="mt-6 border-t border-hp-slate/10 pt-4 text-center">
            <form method="POST" action="{{ route('password.change.cancel') }}">
                @csrf
                <button type="submit" class="text-[12px] text-hp-slate/50 hover:text-hp-slate hover:underline">
                    ← Cancel and keep my current password
                </button>
            </form>
        </div>

        {{-- Dev: where to find the OTP --}}
        @if (app()->isLocal())
            <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                <p class="mb-1 font-semibold">Dev — read the OTP from storage/logs/laravel.log</p>
                <p>The log mailer appends the rendered email to the log on each send. Open the file and
                   scroll to the last message, or search for <code>Your verification code</code>.</p>
            </div>
        @endif
    </x-hp.card>

</div>

</x-layout.sidebar>
