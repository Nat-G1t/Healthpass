<x-auth.shell title="Set New Password">

    <div class="mb-[18px] text-[17px] font-bold text-hp-slate">Set a New Password</div>

    <p class="mb-4 text-[13px] leading-relaxed text-hp-slate/60">
        Code verified. Choose your new password — it only changes when you
        press the button below.
    </p>

    {{--
        Button unlocks when both fields are filled and match (Alpine, UX only);
        the server re-validates with the confirmed rule.
    --}}
    <form
        method="POST"
        action="{{ route('password.reset.update') }}"
        class="space-y-[13px]"
        x-data="{
            fresh: '',
            confirm: '',
            get ready() { return this.fresh.length > 0 && this.fresh === this.confirm; }
        }"
    >
        @csrf

        <x-hp.input
            label="New Password"
            id="password"
            :password="true"
            name="password"
            placeholder="Enter your new password"
            required
            autofocus
            autocomplete="new-password"
            x-model="fresh"
            :error="$errors->first('password')"
        />

        <x-hp.input
            label="Confirm New Password"
            id="password_confirmation"
            :password="true"
            name="password_confirmation"
            placeholder="Re-enter your new password"
            required
            autocomplete="new-password"
            x-model="confirm"
        />

        <p class="text-xs text-hp-slate/45" x-show="confirm.length > 0 && fresh !== confirm" x-cloak>
            The passwords don't match yet.
        </p>

        <x-hp.button type="submit" variant="primary" size="lg" class="mt-0.5 w-full" x-bind:disabled="!ready">
            Change Password
        </x-hp.button>
    </form>

</x-auth.shell>
