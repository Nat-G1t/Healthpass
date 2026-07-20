<x-layout.sidebar title="Booking Confirmed">

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">Booking Confirmed</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Your appointment has been scheduled.</p>
</div>

{{-- ── Success banner — the check draws itself in (§6.2 success moment) ────── --}}
<div class="hp-anim-fade-up mb-6 flex items-center gap-3 rounded-xl border border-hp-peach bg-hp-peach/20 px-4 py-3">
    <svg class="h-5 w-5 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
         stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" pathLength="48"
              class="hp-anim-check-draw"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-semibold text-hp-orange">
        Appointment booked successfully!
    </p>
</div>

{{-- ── Appointment detail card ──────────────────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Appointment Details
    </p>

    <dl class="space-y-4">

        {{-- Reference number --}}
        <div class="flex items-start justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Reference No.</dt>
            <dd class="text-right">
                {{-- Reference reveal: fades up a beat after the page lands. --}}
                <span class="hp-anim-fade-up font-mono text-sm font-semibold tracking-wider text-hp-orange"
                      style="animation-delay: 150ms">
                    {{ $appointment->reference_no }}
                </span>
            </dd>
        </div>

        <div class="border-t border-hp-slate/10"></div>

        {{-- Service --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Service</dt>
            <dd>
                <x-hp.badge variant="positive">
                    {{ $appointment->service_type === 'medical' ? 'Medical Clearance' : 'Dental Check' }}
                </x-hp.badge>
            </dd>
        </div>

        {{-- Date --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Date</dt>
            <dd class="text-sm font-semibold text-hp-slate">
                {{ $appointment->scheduled_date->format('l, F j, Y') }}
            </dd>
        </div>

        {{-- Clinic hours --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Clinic Hours</dt>
            <dd class="text-sm text-hp-slate">
                {{
                    \Carbon\Carbon::parse($clinicHours['open'])->format('g:i A')
                    .' – '.
                    \Carbon\Carbon::parse($clinicHours['close'])->format('g:i A')
                }}
            </dd>
        </div>

        {{-- Status --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Status</dt>
            <dd>
                <x-hp.badge variant="neutral">Scheduled</x-hp.badge>
            </dd>
        </div>

    </dl>
</x-hp.card>

{{-- ── Reminder --}}
<x-hp.card class="mb-6 bg-hp-bg">
    <div class="flex gap-3">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-sm font-semibold text-hp-slate">On your appointment day</p>
            <p class="mt-1 text-xs leading-relaxed text-hp-slate/60">
                Bring your Student ID. You will scan it at the kiosk on arrival to start
                the vitals and screening process.
            </p>
        </div>
    </div>
</x-hp.card>

{{-- ── Actions ──────────────────────────────────────────────────────────────── --}}
<div class="flex flex-col gap-3 sm:flex-row sm:justify-between">

    <a href="{{ route('student.appointments') }}"
       class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-hp-slate/20
              px-5 py-2.5 text-sm font-semibold text-hp-slate transition-colors
              hover:border-hp-orange/40 hover:text-hp-orange sm:w-auto">
        Book Another
    </a>

    {{-- FR-STU-06: cancel button (only shown for future dates) --}}
    @if ($appointment->scheduled_date->gt(today()))
        <div x-data="{ confirming: false }" class="w-full sm:w-auto">

            <button type="button" @click="confirming = true"
                x-show="!confirming"
                class="inline-flex w-full items-center justify-center gap-1.5 rounded-full border
                       border-red-200 px-5 py-2.5 text-sm font-semibold text-red-500
                       transition-colors hover:bg-red-50 sm:w-auto">
                Cancel Appointment
            </button>

            <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3">
                <p class="text-sm text-hp-slate/70">Cancel this appointment?</p>
                <form method="POST"
                      action="{{ route('student.appointments.cancel', $appointment) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="rounded-full bg-red-500 px-4 py-2 text-xs font-semibold
                                   text-white transition-colors hover:bg-red-600">
                        Yes, Cancel
                    </button>
                </form>
                <button type="button" @click="confirming = false"
                        class="text-xs font-semibold text-hp-slate/50 hover:text-hp-slate">
                    Keep it
                </button>
            </div>

        </div>
    @endif

</div>

{{-- ── First-booking tutorial prompt (FR-STU-11) ────────────────────────────── --}}
{{-- Shown only when this is the student's first-ever appointment (computed
     server-side in BookAppointmentController@confirmed). Dismissible; points
     to the Kiosk Tutorial page. --}}
@if ($isFirstBooking)
    <div x-data="{ open: true }" x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="tutorial-prompt-title">

        {{-- Backdrop — clicking it dismisses, same as "Maybe later" --}}
        <div class="absolute inset-0 bg-hp-slate/40" @click="open = false"></div>

        <div class="relative w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-xl">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-6 w-6 text-hp-orange" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h16a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                </svg>
            </div>

            <h3 id="tutorial-prompt-title" class="text-lg font-semibold text-hp-slate">
                First time at the kiosk?
            </h3>
            <p class="mt-1.5 text-sm leading-relaxed text-hp-slate/60">
                It looks like this is your first appointment. Take a quick tour of the
                self-service vitals kiosk so you know exactly what to do on the day.
            </p>

            <div class="mt-5 flex flex-col gap-2">
                <a href="{{ route('student.tutorial') }}"
                   class="inline-flex items-center justify-center gap-2 rounded-full bg-hp-orange
                          px-6 py-2.5 text-sm font-semibold text-white transition-colors
                          duration-150 hover:bg-orange-500">
                    View tutorial
                </a>
                <button type="button" @click="open = false"
                        class="rounded-full px-6 py-2 text-sm font-semibold text-hp-slate/50
                               transition-colors hover:text-hp-slate">
                    Maybe later
                </button>
            </div>
        </div>
    </div>
@endif

</x-layout.sidebar>
