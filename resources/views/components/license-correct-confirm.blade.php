@props(['account'])

{{--
    Physician license correction + confirmation dialog (FR-AUTH-10, D-64).

    Same dialog UI as <x-college-reassign-confirm> and <x-logout-confirm>: an
    Alpine-driven modal teleported to <body>, dismissable with Esc, a backdrop
    click or Cancel. Here the trigger is a small "Correct" button next to the
    license the list shows; the new number is typed INSIDE the dialog, so the
    list itself never displays a value the database does not hold.

    The form lives inside the modal, like the college picker's, so the confirm
    button is a real submit button and nothing reaches across the teleport.
    The server re-checks everything (UpdateStaffLicenseRequest: 4–10 digits;
    the controller: physician targets only) — the input's pattern attribute is
    only a typing aid.
--}}

@php
    // Unique id per row: aria-labelledby must point at this dialog's own title.
    $titleId = 'license-correct-title-'.Str::random(6);
@endphp

<div
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
    class="mt-1 flex items-center gap-2"
>
    <span class="text-[12px] text-hp-slate/60">License No. {{ $account->license_number ?? '—' }}</span>
    <button type="button" @click="open = true; $nextTick(() => $refs.license.select())"
            class="text-[12px] font-semibold text-hp-orange hover:underline"
            aria-label="Correct the license number of {{ $account->name }}">
        Correct
    </button>

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
                @click="open = false"
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
                <h2 id="{{ $titleId }}" class="text-lg font-semibold text-hp-slate">Correct the license number?</h2>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    Records <strong class="font-semibold text-hp-slate">{{ $account->name }}</strong>
                    encodes from now on print the new number. Records already encoded keep the
                    number they were printed with.
                </p>

                <form method="POST" action="{{ route('director.staff.license', $account) }}" class="mt-4">
                    @csrf
                    @method('PATCH')
                    <x-hp.input label="License No." name="license_number" type="text"
                                :id="'license-'.$account->id"
                                inputmode="numeric" pattern="[0-9]{4,10}" maxlength="10" required
                                x-ref="license"
                                value="{{ $account->license_number }}" />

                    <div class="mt-6 flex justify-end gap-3">
                        <x-hp.button variant="muted" @click="open = false">Cancel</x-hp.button>
                        <x-hp.button type="submit" variant="primary" data-pending-label="Saving…">
                            Save license
                        </x-hp.button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>
