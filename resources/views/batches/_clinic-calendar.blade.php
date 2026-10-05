{{--
    D-119 — the read-only Clinic Calendar card. Shared by the Director's Batch
    Tracking page and the clinic's Upcoming Batches page; each passes its own
    role's feed routes:

      $monthUrl — GET ?year=&month= → the month's days (ClinicCalendarController::month)
      $dayUrl   — GET ?date=        → one day's hours  (ClinicCalendarController::day)

    The left half is the same calendar the Director schedules with on the
    Review page (D-108): each day's number inside a ring that fills clockwise
    with how many students that day holds (booked ÷ daily capacity). Here it
    only LOOKS — picking a day (today onward) lists that day's clinic hours on
    the right: booked out of the hourly capacity, and the batches holding them.
    The page opens on today. Everything comes from the server; "today" is the
    server's date (BR-20).
--}}
@php
    $calendarConfig = [
        'today' => today()->toDateString(),
        'year' => today()->year,
        'month' => today()->month,
        'monthUrl' => $monthUrl,
        'dayUrl' => $dayUrl,
    ];
@endphp

<x-hp.card class="mb-6" data-clinic-calendar>
    <div x-data="clinicCalendar({{ Illuminate\Support\Js::from($calendarConfig) }})">
        <p class="hp-card-title">Clinic Calendar</p>
        <p class="mb-5 mt-1 text-xs text-hp-slate/50">How full each clinic day is. Pick a day to see its hours and batches.</p>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">

            {{-- ── Calendar ───────────────────────────────────────────────── --}}
            <div class="mx-auto w-full max-w-[27rem]">
                <div class="mb-3 flex items-center justify-between">
                    <button type="button" @click="prevMonth()" :disabled="!canGoBack" aria-label="Previous month"
                            class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors"
                            :class="canGoBack ? 'text-hp-slate hover:bg-hp-bg hover:text-hp-orange' : 'cursor-not-allowed text-hp-slate/20'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>

                    <span class="text-sm font-semibold text-hp-slate" x-text="monthLabel"></span>

                    <button type="button" @click="nextMonth()" :disabled="!canGoForward" aria-label="Next month"
                            class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors"
                            :class="canGoForward ? 'text-hp-slate hover:bg-hp-bg hover:text-hp-orange' : 'cursor-not-allowed text-hp-slate/20'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <div class="relative">
                    <div x-show="calLoading" x-cloak
                         class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-hp-white/70 backdrop-blur-[1px]">
                        <svg class="h-5 w-5 animate-spin text-hp-orange" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                    </div>

                    <div class="grid grid-cols-7 gap-1">
                        @foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $weekday)
                            <div class="flex h-7 items-center justify-center text-[11px] font-semibold uppercase tracking-wider text-hp-slate/35">
                                {{ $weekday }}
                            </div>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-7 gap-1">
                        <template x-for="cell in cells" :key="cell.key">
                            <div class="aspect-square">
                                <button x-show="!cell.blank" type="button" data-calendar-day
                                        :data-date="cell.date"
                                        @click="pickDay(cell)"
                                        :disabled="!cell.open"
                                        :title="cell.blank ? '' : cellTitle(cell)"
                                        :aria-label="cell.blank ? '' : `${cell.date} — ${cellTitle(cell)}`"
                                        :aria-pressed="selectedDate === cell.date"
                                        class="relative flex h-full w-full items-center justify-center rounded-full
                                               transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange focus-visible:ring-offset-1"
                                        :class="{
                                            'bg-hp-orange text-white shadow-sm hp-anim-pop': selectedDate === cell.date,
                                            'text-hp-slate hover:bg-hp-peach/40 cursor-pointer': cell.open && selectedDate !== cell.date,
                                            'text-hp-slate/25 cursor-not-allowed': !cell.open,
                                        }">
                                    {{-- The ring, exactly as on the Review page: pathLength=100
                                         makes the dash the percentage; rotate(-90) starts it at
                                         12 o'clock. A selected day draws it in white. --}}
                                    <svg x-show="cell.booked > 0" viewBox="0 0 40 40" aria-hidden="true"
                                         class="pointer-events-none absolute inset-0 h-full w-full"
                                         :class="!cell.open && selectedDate !== cell.date ? 'opacity-40' : ''">
                                        <circle cx="20" cy="20" r="17" fill="none" stroke-width="3" pathLength="100"
                                                :stroke="selectedDate === cell.date ? 'rgb(255 255 255 / 0.35)' : 'rgb(var(--hp-peach))'"/>
                                        <circle cx="20" cy="20" r="17" fill="none" stroke-width="3" pathLength="100"
                                                stroke-linecap="round" transform="rotate(-90 20 20)"
                                                :stroke="selectedDate === cell.date ? 'rgb(255 255 255)' : 'rgb(var(--hp-orange))'"
                                                :stroke-dasharray="`${cell.fill * 100} 100`"/>
                                    </svg>
                                    <span class="relative text-base font-semibold leading-none sm:text-xl" x-text="cell.number"></span>
                                    <span x-show="cell.isToday" aria-hidden="true"
                                          class="absolute bottom-[18%] h-1 w-1 rounded-full"
                                          :class="selectedDate === cell.date ? 'bg-white' : 'bg-hp-orange'"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <p class="mt-3 text-center text-xs text-hp-slate/50">
                    Ring = how full the day is (out of {{ (int) config('healthpass.daily_capacity') }} students)
                </p>
            </div>

            {{-- ── The picked day's hours ─────────────────────────────────── --}}
            <div data-calendar-day-detail>
                <p class="text-sm text-hp-slate/70" x-show="selectedDate === ''">
                    Pick a day on the calendar to see its clinic hours.
                </p>

                <div x-show="selectedDate !== ''" x-cloak>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-semibold text-hp-slate" x-text="dateLabel"></p>
                        <p x-show="day !== null && !dayLoading" class="text-xs text-hp-slate/60">
                            <span class="font-semibold text-hp-slate" x-text="day?.booked"></span>
                            / <span x-text="day?.capacity"></span> booked
                        </p>
                    </div>

                    <p x-show="dayLoading" class="mt-4 text-sm text-hp-slate/50">Loading hours…</p>
                    <p x-show="dayFailed && !dayLoading" class="mt-4 text-sm text-red-600">
                        The hours could not be loaded. Pick the day again.
                    </p>

                    <ul x-show="day !== null && !dayLoading" class="mt-3 divide-y divide-hp-slate/10" data-calendar-hours>
                        <template x-for="hour in day?.hours ?? []" :key="hour.slot">
                            <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-2">
                                {{-- On a phone the batches drop to their own line under the hour. --}}
                                <span class="flex-1 text-sm text-hp-slate sm:w-36 sm:flex-none" x-text="hour.label"></span>
                                <span class="w-14 shrink-0 text-right text-sm font-semibold"
                                      :class="hour.booked >= hour.capacity ? 'text-hp-orange' : (hour.booked > 0 ? 'text-hp-slate' : 'text-hp-slate/40')"
                                      x-text="`${hour.booked} / ${hour.capacity}`"></span>
                                <span class="min-w-0 basis-full text-xs text-hp-slate/70 sm:flex-1 sm:basis-auto sm:text-right">
                                    <template x-for="batch in hour.batches" :key="batch.ref">
                                        <span class="block whitespace-nowrap" x-text="batchText(batch)"></span>
                                    </template>
                                    <span x-show="hour.batches.length === 0" class="text-hp-slate/40">Open</span>
                                </span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-hp.card>
