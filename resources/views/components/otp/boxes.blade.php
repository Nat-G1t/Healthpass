{{--
    Six auto-advancing OTP boxes + submit button, posting `otp` to $action.
    The one copy of this UX — registration Step 3, student email verify,
    Forgot Password and Change Password all use it.

    Each box holds exactly one character; typing into a filled box replaces
    it. A paste or the phone's one-time-code autofill (which lands the whole
    code in box 0) spreads the digits across all six. A letter is allowed IN
    a box so the student can see it, but submit refuses it with
    $lettersMessage (UX only — the server re-validates the format and value
    on every submit, with the same words, before counting an attempt).

    Box 0 keeps maxlength 6: iOS/Android autofill respects maxlength and would
    cut the code to one digit. Boxes 1–5 are maxlength 1, and every box
    selects its content on focus so a new keystroke replaces the old one.
--}}
@props([
    'action',
    'buttonLabel' => 'Verify & Continue →',
    'lettersMessage' => "Letters aren't allowed — the code is 6 numbers.",
])

<form
    method="POST"
    action="{{ $action }}"
    x-data="{
        digits: ['','','','','',''],
        lettersError: false,
        shake: {{ $errors->has('otp') ? 'true' : 'false' }},
        get code() { return this.digits.join(''); },
        get ready() { return this.digits.every(d => d.length === 1); },
        onInput(i, e) {
            this.lettersError = false;
            const value = e.target.value;
            const isKeypress = typeof e.data === 'string' && e.data.length === 1;
            if (value.length > 1 && !isKeypress) {
                this.spread(i, value);
                return;
            }
            /* One typed key (or a delete): the box keeps only that character. */
            this.digits[i] = (isKeypress ? e.data : value.slice(-1)).trim();
            e.target.value = this.digits[i];
            if (this.digits[i] && i < 5) this.$refs['d' + (i + 1)].focus();
        },
        onKeydown(i, e) {
            if (e.key === 'Backspace' && !this.digits[i] && i > 0) {
                this.digits[i - 1] = '';
                this.$refs['d' + (i - 1)].focus();
            }
        },
        onPaste(i, e) {
            e.preventDefault();
            this.lettersError = false;
            this.spread(i, (e.clipboardData || window.clipboardData).getData('text'));
        },
        /* A paste or autofill: digits only, from box i onward. */
        spread(i, text) {
            const chars = text.replace(/\D/g, '').slice(0, 6 - i);
            for (let j = 0; j < chars.length; j++) this.digits[i + j] = chars[j];
            /* Write the model back so no box shows more than its one character. */
            for (let k = 0; k < 6; k++) this.$refs['d' + k].value = this.digits[k];
            this.$refs['d' + Math.min(i + Math.max(chars.length, 1) - 1, 5)].focus();
        },
        onSubmit(e) {
            if (/^\d{6}$/.test(this.code)) return;
            e.preventDefault();
            this.lettersError = true;
            this.shake = true;
        }
    }"
    @submit="onSubmit($event)"
>
    @csrf
    {{-- Hidden field carries the joined 6-character string to the server --}}
    <input type="hidden" name="otp" :value="code">

    {{-- Same red box as the pages' @error('otp') block, shown by the browser. --}}
    <div x-show="lettersError" x-cloak
         class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $lettersMessage }}
    </div>

    {{-- A rejected code shakes the whole box row once (§5.7). --}}
    <div class="mb-5 flex justify-center gap-[10px]"
         :class="shake && 'hp-anim-shake'"
         @animationend.self="shake = false">
        @for ($i = 0; $i < 6; $i++)
            <input
                type="text"
                inputmode="numeric"
                maxlength="{{ $i === 0 ? 6 : 1 }}"
                autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                x-ref="d{{ $i }}"
                :value="digits[{{ $i }}]"
                @input="onInput({{ $i }}, $event)"
                @keydown="onKeydown({{ $i }}, $event)"
                @paste="onPaste({{ $i }}, $event)"
                @focus="$event.target.select()"
                @click="$event.target.select()"
                class="h-[58px] w-[46px] rounded-[10px] border-2 text-center text-[24px] font-bold
                       transition-all duration-hp-fast focus:outline-none focus:ring-2 focus:ring-hp-orange/30
                       [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none
                       [&::-webkit-outer-spin-button]:appearance-none"
                {{-- hp-anim-pop (§5.7): each box pops once as its character lands. --}}
                :class="digits[{{ $i }}]
                    ? 'border-hp-orange bg-hp-peach text-hp-orange hp-anim-pop'
                    : 'border-hp-slate/[22%] bg-hp-white text-hp-slate'"
                {{ $i === 0 ? 'autofocus' : '' }}
            />
        @endfor
    </div>

    <x-hp.button type="submit" variant="primary" size="lg" class="w-full" x-bind:disabled="!ready"
                 data-pending-label="Verifying…">
        {{ $buttonLabel }}
    </x-hp.button>
</form>
