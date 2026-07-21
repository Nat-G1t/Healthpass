<x-auth.shell title="Forgot Password">

    <div class="mb-[18px] text-[17px] font-bold text-hp-slate">Forgot Password</div>

    <p class="mb-4 text-[13px] leading-relaxed text-hp-slate/60">
        Enter your account's email address and we'll send a 6-digit code
        to reset your password.
    </p>

    @if (session('status'))
        <div data-hp-flash class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div data-hp-flash data-flash-sticky class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-[13px]">
        @csrf

        <x-hp.input
            label="Email Address"
            id="email"
            type="email"
            name="email"
            :value="old('email')"
            placeholder="you@psu.edu.ph"
            required
            autofocus
            autocomplete="username"
            :error="$errors->first('email')"
        />

        <x-hp.button type="submit" variant="primary" size="lg" class="mt-0.5 w-full" data-pending-label="Sending code…">
            Send Code
        </x-hp.button>
    </form>

    <div class="mt-[14px] text-center text-[12px]">
        <a href="{{ route('login') }}" class="text-hp-slate/[55%] hover:underline">
            ← Back to sign in
        </a>
    </div>

</x-auth.shell>
