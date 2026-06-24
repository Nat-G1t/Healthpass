<x-layout.sidebar title="Booking Confirmed">

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">Booking Confirmed</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Your appointment has been scheduled.</p>
</div>

{{-- ── Success banner ───────────────────────────────────────────────────────── --}}
<div class="mb-6 flex items-center gap-3 rounded-xl border border-hp-peach bg-hp-peach/20 px-4 py-3">
    <svg class="h-5 w-5 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
         stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
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
                <span class="font-mono text-sm font-semibold tracking-wider text-hp-orange">
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

</x-layout.sidebar>
