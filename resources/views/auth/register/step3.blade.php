<x-register.wizard-shell :step="3">

    {{-- Icon --}}
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
    <p class="mb-1 text-center text-sm text-hp-slate/70">We sent a 6-digit verification code to</p>
    <p class="mb-6 text-center text-sm font-semibold text-hp-orange">{{ $email }}</p>

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

    {{-- OTP form with Alpine.js auto-advancing boxes --}}
    <form
        method="POST"
        action="{{ route('register.verify.submit') }}"
        x-data="{
            digits: ['','','','','',''],
            get code() { return this.digits.join(''); },
            get ready() { return /^\d{6}$/.test(this.code); },
            onInput(i, e) {
                const raw = e.target.value.replace(/\D/g, '');
                if (raw.length > 1) {
                    /* browser auto-fill or multi-char paste */
                    const chars = raw.slice(0, 6 - i);
                    for (let j = 0; j < chars.length; j++) {
                        if (i + j < 6) this.digits[i + j] = chars[j];
                    }
                    this.$refs['d' + Math.min(i + chars.length - 1, 5)].focus();
                } else {
                    this.digits[i] = raw;
                    if (raw && i < 5) this.$refs['d' + (i + 1)].focus();
                }
            },
            onKeydown(i, e) {
                if (e.key === 'Backspace' && !this.digits[i] && i > 0) {
                    this.digits[i - 1] = '';
                    this.$refs['d' + (i - 1)].focus();
                }
            },
            onPaste(e) {
                e.preventDefault();
                const t = (e.clipboardData || window.clipboardData)
                    .getData('text').replace(/\D/g,'').slice(0, 6);
                for (let i = 0; i < 6; i++) this.digits[i] = t[i] || '';
                this.$refs['d' + Math.min(t.length, 5)].focus();
            }
        }"
    >
        @csrf
        {{-- Hidden field carries the joined 6-digit string to the server --}}
        <input type="hidden" name="otp" :value="code">

        {{-- Six digit boxes --}}
        <div class="mb-6 flex justify-center gap-3">
            @for ($i = 0; $i < 6; $i++)
                <input
                    type="text"
                    inputmode="numeric"
                    pattern="\d*"
                    maxlength="6"
                    autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                    x-ref="d{{ $i }}"
                    :value="digits[{{ $i }}]"
                    @input="onInput({{ $i }}, $event)"
                    @keydown="onKeydown({{ $i }}, $event)"
                    @paste="onPaste($event)"
                    class="h-14 w-12 rounded-lg border border-hp-slate/25 text-center text-xl font-bold
                           text-hp-slate transition-colors duration-150 focus:outline-none
                           focus:border-hp-orange focus:ring-2 focus:ring-hp-orange/30
                           [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none
                           [&::-webkit-outer-spin-button]:appearance-none"
                    {{ $i === 0 ? 'autofocus' : '' }}
                />
            @endfor
        </div>

        <button
            type="submit"
            :disabled="!ready"
            class="w-full rounded-lg bg-hp-orange py-2.5 text-sm font-semibold text-white
                   transition-opacity hover:bg-hp-orange/90 focus:outline-none focus:ring-2
                   focus:ring-hp-orange/50 disabled:cursor-not-allowed disabled:opacity-40"
        >
            Verify &amp; Continue
        </button>
    </form>

    {{-- Resend --}}
    <div class="mt-4 text-center text-sm">
        <span class="text-hp-slate/60">Didn't receive it?</span>
        <form method="POST" action="{{ route('register.verify.resend') }}" class="inline">
            @csrf
            <button type="submit"
                    class="ml-1 font-semibold text-hp-orange underline-offset-2 hover:underline">
                Resend code
            </button>
        </form>
    </div>

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
        <a href="{{ route('register') }}" class="text-sm text-hp-slate/60 hover:underline">
            ← Start over
        </a>
    </div>

</x-register.wizard-shell>
