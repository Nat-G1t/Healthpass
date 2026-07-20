<x-layout.sidebar title="Change Password">

<div class="mx-auto max-w-md">

    <x-hp.card>
        <div class="mb-6 text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-6 w-6 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
            </div>
            <h2 class="text-base font-bold text-hp-slate">Change Password</h2>
            <p class="mt-1.5 text-[13px] leading-relaxed text-hp-slate/60">
                We'll email you a 6-digit code to confirm the change.<br>
                Your password won't change until you enter it.
            </p>
        </div>

        {{-- Success / failure flashes --}}
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

        {{--
            The button unlocks only when every field is filled AND the new +
            confirm values match (Alpine, UX only) — the server re-validates
            with the current_password + confirmed rules regardless.
        --}}
        <form
            method="POST"
            action="{{ route('password.change.store') }}"
            class="space-y-[13px]"
            x-data="{
                current: '',
                fresh: '',
                confirm: '',
                get ready() {
                    return this.current.length > 0
                        && this.fresh.length > 0
                        && this.fresh === this.confirm;
                }
            }"
        >
            @csrf

            <x-hp.input
                label="Current Password"
                id="current_password"
                :password="true"
                name="current_password"
                placeholder="Enter your current password"
                required
                autocomplete="current-password"
                x-model="current"
                :error="$errors->first('current_password')"
            />

            <x-hp.input
                label="New Password"
                id="password"
                :password="true"
                name="password"
                placeholder="Enter your new password"
                required
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
                The new passwords don't match yet.
            </p>

            <x-hp.button type="submit" variant="primary" size="lg" class="mt-0.5 w-full" x-bind:disabled="!ready" data-pending-label="Sending code…">
                Update Password
            </x-hp.button>
        </form>
    </x-hp.card>

</div>

</x-layout.sidebar>
