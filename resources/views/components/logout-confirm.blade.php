{{--
    Logout confirmation dialog.

    A Blade component is a reusable chunk of markup — here it bundles a trigger
    (supplied by the caller via the `trigger` slot) with an Alpine-driven
    confirmation modal, so every "Log out" control across the app can open the
    same dialog without repeating the modal markup.

    Usage:
        <x-logout-confirm>
            <x-slot:trigger>
                <button type="button" @click="open = true">Log out</button>
            </x-slot:trigger>
        </x-logout-confirm>

    The trigger's @click flips `open` (Alpine's reactive state on the wrapper).
    Dismissable with Esc or a click outside; the "Log out" button submits the
    existing POST /logout form. NOTE: the kiosk staff-exit flow is separate and
    intentionally does NOT use this component.
--}}
@php
    // Unique id so multiple instances on one page (e.g. the desktop dropdown
    // and the mobile drawer in navigation.blade.php) don't collide on the
    // dialog title that aria-labelledby points at.
    $titleId = 'logout-confirm-title-' . Str::random(6);
@endphp

<div x-data="{ open: false }">
    {{ $trigger }}

    {{-- Teleported to <body> so a parent's stacking context / overflow never
         clips the full-screen backdrop (the sidebar footer is one such parent). --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            @keydown.escape.window="open = false"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $titleId }}"
        >
            {{-- Backdrop — click outside to cancel --}}
            <div
                x-show="open"
                @click="open = false"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            {{-- Panel --}}
            <div
                x-show="open"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl"
            >
                <h2 id="{{ $titleId }}" class="text-lg font-semibold text-hp-slate">Log out?</h2>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    You'll be signed out and need to log in again to continue.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-hp.button variant="muted" @click="open = false">Cancel</x-hp.button>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-hp.button type="submit" variant="primary">Log out</x-hp.button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
