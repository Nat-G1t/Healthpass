<x-layout.sidebar title="Dashboard">

@php
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
    $firstName = explode(' ', auth()->user()->name)[0];
@endphp

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-7 flex items-start justify-between">
    <div>
        <h2 class="text-xl font-semibold text-hp-slate">Good {{ $greeting }}, {{ $firstName }}!</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50">{{ now()->format('l, F j, Y') }}</p>
    </div>
</div>

{{-- ── Stat cards ──────────────────────────────────────────────────────────── --}}
<div class="hp-stagger grid grid-cols-1 gap-5 md:grid-cols-3 mb-6">

    {{-- Card 1: Clearance Status --}}
    <x-hp.card class="flex flex-col">
        <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Clearance Status
        </p>

        @if ($latestClearance)
            <div class="mt-4 flex-1">
                <x-hp.badge :variant="$latestClearance->result === 'Fit' ? 'fit' : 'unfit'">
                    {{ $latestClearance->result }}
                </x-hp.badge>
                <p class="mt-2 text-xs text-hp-slate/50">
                    Last updated
                    {{ ($latestClearance->encoded_at ?? $latestClearance->created_at)->format('M j, Y') }}
                </p>
            </div>
        @else
            <div class="mt-4 flex-1 flex flex-col items-center py-4 text-center">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-5 w-5 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No clearance yet</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">Book an appointment to get started</p>
            </div>
        @endif

        <div class="mt-5">
            <a href="{{ route('student.appointments') }}"
               class="inline-flex w-full items-center justify-center gap-1.5 rounded-full
                      bg-hp-orange px-4 py-2 text-xs font-semibold text-white
                      transition-colors hover:bg-orange-500">
                Book New Appointment
            </a>
        </div>
    </x-hp.card>

    {{-- Card 2: Next Appointment — wrapped in Alpine for the cancel confirm modal. --}}
    <div x-data="{ cancelModal: false }">
    <x-hp.card class="h-full flex flex-col">
        <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Next Appointment
        </p>

        @if ($nextAppointment)
            <div class="mt-4 flex-1">
                <p class="text-3xl font-bold leading-none text-hp-slate">
                    {{ $nextAppointment->scheduled_date->format('j') }}
                </p>
                <p class="mt-0.5 text-sm text-hp-slate/60">
                    {{ $nextAppointment->scheduled_date->format('F Y') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <x-hp.badge variant="neutral">
                        {{ ucfirst($nextAppointment->service_type) }}
                    </x-hp.badge>
                    <x-hp.badge variant="pending">Scheduled</x-hp.badge>
                </div>
                <p class="mt-2 flex items-center gap-1 text-xs text-hp-slate/50">
                    <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ $clinicHoursLabel }}
                </p>
                <p class="mt-1 text-xs text-hp-slate/40">{{ $nextAppointment->reference_no }}</p>
            </div>

            @if ($nextAppointment->scheduled_date->gt(today()))
                <div class="mt-5">
                    <x-hp.button variant="danger" size="sm" class="w-full" @click="cancelModal = true">
                        Cancel appointment
                    </x-hp.button>
                </div>
            @endif
        @else
            <div class="mt-4 flex-1 flex flex-col items-center py-4 text-center">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-5 w-5 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No upcoming appointment</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">Your schedule is clear</p>
            </div>
            <div class="mt-5">
                <a href="{{ route('student.appointments') }}"
                   class="inline-flex w-full items-center justify-center gap-1.5 rounded-full
                          border border-hp-slate/25 px-4 py-2 text-xs font-semibold text-hp-slate
                          transition-colors hover:bg-hp-slate/5">
                    Book Appointment
                </a>
            </div>
        @endif
    </x-hp.card>

    {{-- Cancel confirm modal — only rendered when there is a cancellable future appointment. --}}
    @if ($nextAppointment && $nextAppointment->scheduled_date->gt(today()))
    <div x-show="cancelModal" x-cloak
         class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
         style="background-color: rgba(75,85,99,0.45);">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">

            <div class="mb-3 flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-50">
                    <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-hp-slate">Cancel appointment?</h3>
            </div>

            <p class="text-sm text-hp-slate/70">
                Cancel your
                <span class="font-semibold text-hp-slate">
                    {{ ucfirst($nextAppointment->service_type) }}
                </span>
                appointment on
                <span class="font-semibold text-hp-slate">
                    {{ $nextAppointment->scheduled_date->format('F j, Y') }}
                </span>?
                This frees the slot for another student.
            </p>

            <div class="mt-5 flex gap-3">
                <button type="button"
                        @click="cancelModal = false"
                        class="flex-1 rounded-full border border-hp-slate/25 py-2.5 text-sm
                               font-semibold text-hp-slate transition-colors hover:bg-hp-slate/5">
                    Keep appointment
                </button>
                <form method="POST"
                      action="{{ route('student.appointments.cancel', $nextAppointment) }}"
                      class="flex-1">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="w-full rounded-full bg-red-500 py-2.5 text-sm font-semibold
                                   text-white transition-colors hover:bg-red-600">
                        Yes, cancel
                    </button>
                </form>
            </div>

        </div>
    </div>
    @endif

    </div>{{-- /x-data --}}

    {{-- Card 3: Past Clearances --}}
    <x-hp.card class="flex flex-col">
        <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Past Clearances
        </p>

        <div class="mt-4 flex flex-1 flex-col items-center py-4 text-center">
            <p class="text-5xl font-bold text-hp-orange leading-none">{{ $pastClearancesCount }}</p>
            <p class="mt-2 text-sm text-hp-slate/50">
                {{ $pastClearancesCount === 1 ? 'clearance' : 'clearances' }} on record
            </p>
        </div>

        <div class="mt-5">
            <a href="{{ route('student.records') }}"
               class="inline-flex w-full items-center justify-center gap-1.5 rounded-full
                      border border-hp-slate/25 px-4 py-2 text-xs font-semibold text-hp-slate
                      transition-colors hover:bg-hp-slate/5">
                View all
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
    </x-hp.card>

</div>

{{-- ── Recent Activity ─────────────────────────────────────────────────────── --}}
<x-hp.card>
    <h3 class="mb-5 text-sm font-semibold text-hp-slate">Recent Activity</h3>

    @if ($recentActivity->isEmpty())
        <div class="flex flex-col items-center justify-center py-10 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">No activity yet</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                Your recent bookings and visits will appear here
            </p>
        </div>
    @else
        <ol class="space-y-0">
            @foreach ($recentActivity as $event)
                <li class="flex gap-4 {{ !$loop->last ? 'pb-5' : '' }}">

                    {{-- Timeline dot + vertical connector --}}
                    <div class="relative flex flex-col items-center">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full
                                    {{ $event['icon'] === 'result' ? 'bg-hp-peach' : 'bg-hp-bg' }}">
                            @if ($event['icon'] === 'calendar')
                                <svg class="h-3.5 w-3.5 text-hp-orange" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            @elseif ($event['icon'] === 'x')
                                <svg class="h-3.5 w-3.5 text-hp-slate/40" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            @elseif ($event['icon'] === 'checkin')
                                <svg class="h-3.5 w-3.5 text-hp-slate/40" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                                </svg>
                            @elseif ($event['icon'] === 'result')
                                <svg class="h-3.5 w-3.5 text-hp-orange" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            @else {{-- registered --}}
                                <svg class="h-3.5 w-3.5 text-hp-slate/40" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            @endif
                        </div>

                        @unless ($loop->last)
                            <div class="mt-1 w-px flex-1 bg-hp-slate/10"></div>
                        @endunless
                    </div>

                    {{-- Event text --}}
                    <div class="pb-1 pt-0.5 min-w-0">
                        <p class="text-sm font-medium text-hp-slate leading-snug">
                            {{ $event['label'] }}
                        </p>
                        <p class="mt-0.5 text-xs text-hp-slate/50">{{ $event['detail'] }}</p>
                        <p class="mt-0.5 text-[11px] text-hp-slate/40">
                            {{ \Carbon\Carbon::parse($event['at'])->format('M j, Y · g:i A') }}
                        </p>
                    </div>

                </li>
            @endforeach
        </ol>
    @endif
</x-hp.card>

</x-layout.sidebar>
