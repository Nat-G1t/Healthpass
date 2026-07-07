{{--
    Six auto-advancing OTP boxes + submit button, posting `otp` to $action.
    Same Alpine UX as registration Step 3 / student email verify. The submit
    button stays disabled until all 6 digits are present (UX only — the server
    re-validates the code format and value on every submit).
--}}
@props(['action', 'buttonLabel' => 'Verify & Continue →'])

<form
    method="POST"
    action="{{ $action }}"
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
        {{ $buttonLabel }}
    </x-hp.button>
</form>
