<x-register.wizard-shell :step="3">

    <h2 class="mb-[6px] text-center text-[15px] font-bold text-hp-slate">Step 3 — Verify Your Email</h2>
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

        {{-- Six OTP boxes — sized and coloured to match the prototype --}}
        <div class="mb-[20px] flex justify-center gap-[10px]">
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
                    class="h-[60px] w-[50px] rounded-[10px] border-2 text-center text-[26px] font-bold
                           transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-hp-orange/30
                           [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none
                           [&::-webkit-outer-spin-button]:appearance-none"
                    :class="digits[{{ $i }}]
                        ? 'border-hp-orange bg-hp-peach text-hp-orange'
                        : 'border-hp-slate/[22%] bg-white text-hp-slate'"
                    {{ $i === 0 ? 'autofocus' : '' }}
                />
            @endfor
        </div>

        <x-hp.button
            type="submit"
            variant="primary"
            size="lg"
            class="w-full"
            x-bind:disabled="!ready"
            data-pending-label="Verifying…"
        >
            Verify &amp; Continue →
        </x-hp.button>

    </form>

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

</x-register.wizard-shell>
