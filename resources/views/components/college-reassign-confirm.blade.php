@props(['account', 'colleges'])

{{--
    College reassignment picker + confirmation dialog (FR-AUTH-10, D-47a).

    Same dialog UI as <x-logout-confirm>: an Alpine-driven modal teleported to
    <body>, dismissable with Esc, a backdrop click or Cancel. The difference is
    what opens it — a <select> changing, not a button being clicked — and that
    matters in one specific way:

    A CANCELLED DIALOG MUST PUT THE DROPDOWN BACK. The browser has already
    changed the visible selection by the time `change` fires, so every dismissal
    path (Cancel, Esc, backdrop) goes through cancel(), which restores the value
    the admin actually has. Without that, backing out would leave the control
    displaying a college the database does not agree with — the exact confusion
    that removing the old Save button was meant to end.

    The form lives INSIDE the modal rather than around the select, mirroring how
    the logout dialog holds its own POST form: the confirm button is then a real
    submit button, and nothing has to reach across the teleport to submit.

    The college NAME shown in the dialog is read at runtime from the chosen
    option's data-name, never interpolated into a JS string — an apostrophe in
    a name breaks the literal and silently skips the guard (the trap documented
    on the kiosk-devices Revoke button). The admin's name is plain Blade text in
    the markup, which Blade escapes, so it is safe there.
--}}

@php
    // Unique id per row: this component renders once per account, and
    // aria-labelledby must point at this dialog's own title.
    $titleId = 'college-reassign-title-'.Str::random(6);
    $currentCollege = $account->managedCollege?->name;
@endphp

<div
    x-data="{
        open: false,
        previous: @js((string) $account->managed_college_id),
        targetId: '',
        targetName: '',
        ask(event) {
            const option = event.target.selectedOptions[0];
            this.targetId = option.value;
            this.targetName = option.dataset.name;
            this.open = true;
        },
        cancel() {
            this.open = false;
            this.$refs.picker.value = this.previous;
        },
    }"
    @keydown.escape.window="open && cancel()"
    class="flex justify-end md:justify-start"
>
    <select
        x-ref="picker"
        x-on:change="ask($event)"
        aria-label="College managed by {{ $account->name }}"
        class="rounded-lg border border-hp-slate/25 bg-hp-white py-1 pl-2 pr-7 text-[12px] text-hp-slate
               focus:border-hp-orange focus:outline-none focus:ring-1 focus:ring-hp-orange"
    >
        @foreach ($colleges as $college)
            <option value="{{ $college->id }}"
                    data-name="{{ $college->name }}"
                    @selected($account->managed_college_id === $college->id)>
                {{ $college->code }}
            </option>
        @endforeach
    </select>

    {{-- Teleported to <body> so the table's stacking context and horizontal
         overflow never clip the full-screen backdrop. --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $titleId }}"
        >
            {{-- Backdrop — click outside to cancel --}}
            <div
                x-show="open"
                @click="cancel()"
                x-transition:enter="ease-hp-out duration-hp-base"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-hp-in duration-hp-fast"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            {{-- Panel --}}
            <div
                x-show="open"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full max-w-sm rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="{{ $titleId }}" class="text-lg font-semibold text-hp-slate">Move to another college?</h2>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    <strong class="font-semibold text-hp-slate">{{ $account->name }}</strong>
                    will manage <strong class="font-semibold text-hp-slate" x-text="targetName"></strong>
                    from now on.
                    @if ($currentCollege)
                        They lose access to {{ $currentCollege }} immediately, including its
                        batch requests and analytics.
                    @endif
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-hp.button variant="muted" @click="cancel()">Cancel</x-hp.button>

                    <form method="POST" action="{{ route('director.staff.college', $account) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="managed_college_id" :value="targetId">
                        <x-hp.button type="submit" variant="primary" data-pending-label="Moving…">
                            Move admin
                        </x-hp.button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
