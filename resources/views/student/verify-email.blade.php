<x-layout.sidebar title="Verify New Email">

<div class="mx-auto max-w-md">

    <x-hp.card>
        <div class="mb-6 text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-6 w-6 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <h2 class="text-base font-bold text-hp-slate">Confirm Your New Email</h2>
            <p class="mt-1.5 text-[13px] leading-relaxed text-hp-slate/60">
                A 6-digit verification code was sent to<br>
                <strong class="text-hp-orange break-all">{{ $email }}</strong>
            </p>
            <p class="mt-1 text-xs text-hp-slate/45">
                Your email won't change until you enter the code below.
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

        {{-- OTP form with Alpine.js auto-advancing boxes (same UX as registration Step 3) --}}
        <form
            method="POST"
            action="{{ route('student.id-profile.verify-email.submit') }}"
            x-data="{
                digits: ['','','','','',''],
                get code() { return this.digits.join(''); },
                get ready() { return /^\d{6}$/.test(this.code); },
                onInput(i, e) {
                    const raw = e.target.value.replace(/\D/g, '');
                    if (raw.length > 1) {
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
            <input type="hidden" name="otp" :value="code">

            <div class="mb-5 flex justify-center gap-[10px]">
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
                        class="h-[58px] w-[46px] rounded-[10px] border-2 text-center text-[24px] font-bold
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

            <x-hp.button type="submit" variant="primary" size="lg" class="w-full" x-bind:disabled="!ready">
                Verify &amp; Update Email
            </x-hp.button>
        </form>

        {{-- Resend --}}
        <div class="mt-5 text-center text-[12px] text-hp-slate/50">
            Didn't receive a code?
            <form method="POST" action="{{ route('student.id-profile.verify-email.resend') }}" class="inline">
                @csrf
                <button type="submit" class="ml-1 font-semibold text-hp-orange underline-offset-2 hover:underline">
                    Resend
                </button>
            </form>
        </div>

        {{-- Cancel — abandon the change, keep current email --}}
        <div class="mt-6 border-t border-hp-slate/10 pt-4 text-center">
            <form method="POST" action="{{ route('student.id-profile.verify-email.cancel') }}">
                @csrf
                <button type="submit" class="text-[12px] text-hp-slate/50 hover:text-hp-slate hover:underline">
                    ← Cancel and keep my current email
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
