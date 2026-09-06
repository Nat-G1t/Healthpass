<x-layout.sidebar title="My Appointments">

{{--
    FR-STU-14 (D-51) — every upcoming appointment, each with its own cancel
    control. The cancel RULE is unchanged (Appointment::isSelfCancellable());
    before this page the only cancel button in the app hung off the dashboard's
    single Next Appointment row, so a student holding three appointments could
    reach exactly one of them.
--}}

{{-- ── Flash message ────────────────────────────────────────────────────────
     cancel() flashes the KEY 'appointment-cancelled', not a sentence, so the
     wording lives here rather than in the controller. --}}
@if (session('status') === 'appointment-cancelled')
    <div data-hp-flash class="mb-6 rounded-lg border border-green-200 dark:border-green-500/30 bg-green-50 dark:bg-green-500/10 px-4 py-3 text-sm text-green-700 dark:text-green-300">
        Your appointment has been cancelled. The slot is now free for another student.
    </div>
@endif

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">My Appointments</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50 dark:text-hp-slate/60">
        Your upcoming clinic appointments
    </p>
</div>

{{--
    Alpine (the house JS framework — a small library that lets markup hold its
    own state): ONE modal is shared by every row instead of one modal per
    appointment. `cancelId` remembers which row was clicked; the confirm button
    submits that row's hidden form by id. `x-data` declares the state object.
--}}
<div x-data="{ cancelOpen: false, cancelId: null, cancelText: '' }">

<x-hp.card>

    <h3 class="mb-5 text-sm font-semibold text-hp-slate">
        Upcoming Appointments
        @if ($appointments->isNotEmpty())
            <span class="ml-1 font-normal text-hp-slate/40 dark:text-hp-slate/55">({{ $appointments->count() }})</span>
        @endif
    </h3>

    @if ($appointments->isEmpty())

        <div class="flex flex-col items-center justify-center py-10 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30 dark:text-hp-slate/55" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">No upcoming appointments</p>
            <p class="mt-0.5 text-xs text-hp-slate/50 dark:text-hp-slate/60">
                Appointments scheduled for today appear on your dashboard
            </p>
            <a href="{{ route('student.appointments') }}"
               class="mt-4 inline-flex items-center justify-center gap-1.5 rounded-full
                      border border-hp-slate/25 px-4 py-2 text-xs font-semibold text-hp-slate
                      transition-colors hover:bg-hp-slate/5">
                Book Appointment
            </a>
        </div>

    @else

        {{-- hp-stagger: rows fade up in sequence on first paint (§6.2). --}}
        <div class="hp-stagger space-y-3">
            @foreach ($appointments as $appointment)
            @php
                // Built once here so the row, the modal text and the hidden form
                // all read the same values.
                $isCancellable = $appointment->isSelfCancellable();
                $service       = ucfirst($appointment->service_type);
                $cancelText    = $service.' appointment on '.$appointment->scheduled_date->format('F j, Y');
            @endphp

            <div class="rounded-xl border border-hp-slate/10 p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                    {{-- Left: when, what, who booked it --}}
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-hp-slate">
                            {{ $appointment->scheduled_date->format('l, F j, Y') }}
                        </p>
                        <p class="mt-0.5 text-xs text-hp-slate/60 dark:text-hp-slate/70">
                            {{-- "—" on pre-D-37 rows that belong to no slot --}}
                            {{ $appointment->timeRangeLabel() }}
                        </p>

                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <x-hp.badge variant="neutral">{{ $service }}</x-hp.badge>
                            {{-- FR-STU-14: who put this in the diary. Always shown,
                                 for both sources — it is what explains the missing
                                 cancel button on a batch row. --}}
                            <x-hp.badge variant="neutral">{{ $appointment->scheduledByLabel() }}</x-hp.badge>
                        </div>

                        <p class="mt-2 font-mono text-xs text-hp-slate/35 dark:text-hp-slate/55">
                            {{ $appointment->reference_no }}
                        </p>
                    </div>

                    {{-- Right: the action, or the reason there is none --}}
                    <div class="shrink-0 sm:text-right">
                        @if ($isCancellable)
                            {{-- The label is read at runtime from data-cancel-label,
                                 NEVER interpolated into the JS expression: Blade does
                                 not compile directives inside a component tag's
                                 attribute (an @js() here renders literally and breaks
                                 the handler), and an apostrophe in the text would break
                                 a hand-written string literal anyway. Same rule as the
                                 college-reassign dialog's data-name. --}}
                            <x-hp.button variant="danger" size="sm"
                                         data-cancel-label="{{ $cancelText }}"
                                         @click="cancelId = {{ $appointment->id }}; cancelText = $el.dataset.cancelLabel; cancelOpen = true">
                                Cancel
                            </x-hp.button>

                            {{-- Submitted by the shared modal via getElementById.
                                 `from=list` is read by cancel() against a two-value
                                 allow-list so the redirect comes back here. --}}
                            <form method="POST" id="cancel-appt-{{ $appointment->id }}"
                                  action="{{ route('student.appointments.cancel', $appointment) }}"
                                  class="hidden">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="from" value="list">
                            </form>
                        @elseif ($appointment->source === 'batch')
                            {{-- D-39: only the College Admin who booked the cohort
                                 may withdraw a batch appointment. --}}
                            <p class="max-w-xs text-xs leading-relaxed text-hp-slate/50 dark:text-hp-slate/60">
                                Booked by your college. Contact your college
                                administrator if you need this cancelled.
                            </p>
                        @endif
                    </div>

                </div>
            </div>
            @endforeach
        </div>

    @endif

</x-hp.card>

{{-- ── Shared cancel confirm modal ────────────────────────────────────────────
     Teleported to <body> so no parent's stacking context, transform or overflow
     can clip the full-screen backdrop — the page shell and the animated card are
     both such parents. Same reason x-logout-confirm teleports. Alpine keeps the
     surrounding x-data scope across the teleport, so cancelId still resolves. --}}
<template x-teleport="body">
<div x-show="cancelOpen" x-cloak
     @keydown.escape.window="cancelOpen = false"
     {{-- Centred at EVERY breakpoint, like x-logout-confirm. The old
          `items-end sm:items-center` pinned the panel to the bottom edge on
          phones, which read as the dialog falling off the screen. --}}
     class="fixed inset-0 z-[60] flex items-center justify-center p-4"
     role="dialog" aria-modal="true">

    {{-- Backdrop — click outside to dismiss. Its own element so a click on the
         panel does not bubble up and close the dialog. --}}
    <div @click="cancelOpen = false"
         class="absolute inset-0"
         style="background-color: rgba(75,85,99,0.45);"
         aria-hidden="true"></div>

    {{-- max-h/overflow so the panel can never be taller than a short phone
         viewport and get clipped (same guard as the Records modal). --}}
    <div class="relative max-h-[90vh] w-full max-w-sm overflow-y-auto rounded-2xl bg-hp-white p-6 shadow-xl">

        <div class="mb-3 flex items-center gap-3">
            {{-- A real button, not a decorative glyph: it reads as an X, so
                 clicking it must dismiss the dialog and change nothing. --}}
            <button type="button"
                    @click="cancelOpen = false"
                    aria-label="Close"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                           bg-red-50 transition-colors hover:bg-red-100 focus-visible:outline-none
                           focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-1
                           dark:bg-red-500/10 dark:hover:bg-red-500/20">
                <svg class="h-4 w-4 text-red-500 dark:text-red-400" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            <h3 class="text-base font-semibold text-hp-slate">Cancel appointment?</h3>
        </div>

        <p class="text-sm text-hp-slate/70">
            Cancel your
            <span class="font-semibold text-hp-slate" x-text="cancelText"></span>?
            This frees the slot for another student.
        </p>

        <div class="mt-5 flex gap-3">
            <button type="button"
                    @click="cancelOpen = false"
                    class="flex-1 rounded-full border border-hp-slate/25 py-2.5 text-sm
                           font-semibold text-hp-slate transition-colors hover:bg-hp-slate/5">
                Keep appointment
            </button>
            <button type="button"
                    @click="document.getElementById('cancel-appt-' + cancelId).submit()"
                    class="flex-1 rounded-full bg-red-500 py-2.5 text-sm font-semibold
                           text-white transition-colors hover:bg-red-600">
                Yes, cancel
            </button>
        </div>

    </div>
</div>
</template>

</div>{{-- /x-data --}}

</x-layout.sidebar>
