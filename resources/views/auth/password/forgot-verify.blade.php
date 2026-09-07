<x-auth.shell title="Enter Reset Code">

    <div class="mb-[6px] text-center text-[15px] font-bold text-hp-slate">Enter Your Code</div>
    <p class="mb-5 text-center text-[13px] leading-[1.6] text-hp-slate/60">
        If an account exists for<br>
        <strong class="text-hp-orange break-all">{{ $email }}</strong><br>
        we sent it a 6-digit code.
    </p>

    {{-- Sent / resent flash --}}
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

    <x-otp.boxes
        :action="route('password.reset.verify.submit')"
        button-label="Verify Code"
    />

    <x-otp.resend-button
        :action="route('password.reset.verify.resend')"
        :remaining="$resendRemaining"
    />

    {{-- Start over --}}
    <div class="mt-6 flex justify-center">
        <a href="{{ route('password.request') }}" class="text-[12px] text-hp-slate/50 hover:underline">
            ← Use a different email
        </a>
    </div>

    {{-- Dev: where to find the OTP --}}
    @if (app()->isLocal())
        <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
            <p class="mb-1 font-semibold">Dev — read the OTP from storage/logs/laravel.log</p>
            <p>The log mailer appends the rendered email to the log on each send. Open the file and
               scroll to the last message, or search for <code>Your verification code</code>.</p>
        </div>
    @endif

</x-auth.shell>
