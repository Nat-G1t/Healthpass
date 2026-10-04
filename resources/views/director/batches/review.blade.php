<x-layout.sidebar title="Review {{ $batch->reference_no }}">

@php
    // One source of truth for the reason bounds: the Form Request that
    // actually enforces them (D-36).
    $reasonMin = App\Http\Requests\Director\RejectBatchRequest::REASON_MIN;
    $reasonMax = App\Http\Requests\Director\RejectBatchRequest::REASON_MAX;
    $studentCount = $students->count();
@endphp

{{--
    Director Review page (FR-DIRA-07/08, D-108): one pending batch request.

    Cards, stacked in this order (single column on phones):
      1. Batch details      — what the college sent
      2. Students           — ten rows tall, then it scrolls inside the card
      3. (D-109, prompt 07: the attached signed memo goes here)
      4. Schedule           — the calendar, the hour picker, Approve / Reject

    The Schedule card's Alpine component (`batchSchedule`, in
    resources/js/director/batch-schedule.js) owns the calendar, the hours, and
    both decision modals. It only helps: approve() re-checks every rule on the
    server under a row lock.
--}}
<div x-data="batchSchedule({{ Illuminate\Support\Js::from($scheduleData + [
        'ref' => $batch->reference_no,
        // After a refused Approve (or a failed reject), come back to what was picked.
        'oldDate' => old('scheduled_date'),
        'oldStart' => old('start_time'),
        'rejectOpen' => $errors->has('rejection_reason'),
        'oldReason' => old('rejection_reason'),
        'reasonMin' => $reasonMin,
    ]) }})">

    {{-- ── Page header ─────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <a href="{{ route('director.batches.index') }}"
           class="inline-flex items-center gap-1.5 text-xs font-semibold text-hp-slate/50 transition-colors hover:text-hp-orange">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to Batch Approvals
        </a>
        <h2 class="mt-3 text-xl font-semibold text-hp-slate">Review {{ $batch->reference_no }}</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50">
            Pick the clinic date and start hour for this batch, then approve it — or reject it with a reason.
        </p>
    </div>

    {{-- Refusals from approve() — the hour or the day to change. --}}
    @if (session('error'))
        <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->hasAny(['scheduled_date', 'start_time', 'rejection_reason']))
        <div class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            @foreach (['scheduled_date', 'start_time', 'rejection_reason'] as $field)
                @error($field)
                    <p>{{ $message }}</p>
                @enderror
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- ── Card 1: batch details (the admin's Request Submitted look) ── --}}
        <x-hp.card>
            <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Batch Details</p>

            <dl class="space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Batch ID</dt>
                    <dd class="font-mono text-sm font-semibold tracking-wider text-hp-orange">{{ $batch->reference_no }}</dd>
                </div>

                <div class="border-t border-hp-slate/10"></div>

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">College</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">
                        {{ $batch->college->name }} <span class="font-normal text-hp-slate/50">({{ $batch->college->code }})</span>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Form</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->formTypeLabel() }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Purpose</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->reasonText() }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Students</dt>
                    <dd class="text-sm font-semibold text-hp-slate">{{ $studentCount }}</dd>
                </div>
                {{-- D-37: hourly_capacity per hour, contiguous — "2 hours — 40 students, 20 per hour". --}}
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Hours needed</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">
                        {{ $blocks }} {{ Str::plural('hour', $blocks) }}
                        <span class="font-normal text-hp-slate/50">— {{ $studentCount }} {{ Str::plural('student', $studentCount) }}, {{ $hourlyCapacity }} per hour</span>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Submitted</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->created_at->format('M j, Y · g:i A') }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Submitted by</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->requester?->name ?? '—' }}</dd>
                </div>
            </dl>

            {{-- Legacy rows only: what the college asked for before D-108.
                 Information, not a rule — the Director picks the schedule. --}}
            @if ($batch->requestedLabel() !== null)
                <p class="mt-5 rounded-lg bg-hp-bg px-3 py-2 text-xs text-hp-slate/70" data-originally-requested>
                    Originally requested: <span class="font-semibold text-hp-slate">{{ $batch->requestedLabel() }}</span> (before D-108)
                </p>
            @endif
        </x-hp.card>

        {{-- ── Card 2: students — ten rows tall, then scrolls (Q13) ──────── --}}
        <x-hp.card>
            <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Students in this batch <span class="ml-1 normal-case tracking-normal text-hp-slate/50">({{ $studentCount }})</span>
            </p>

            {{-- 2.75rem rows: the header plus ten rows is 30.25rem. --}}
            <div class="max-h-[30.25rem] overflow-auto rounded-lg border border-hp-slate/10">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-hp-white">
                        <tr class="h-11 border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40">
                            <th class="whitespace-nowrap px-4 font-semibold">Name</th>
                            <th class="whitespace-nowrap pr-4 font-semibold">Student No.</th>
                            <th class="whitespace-nowrap pr-4 font-semibold">Program</th>
                            <th class="whitespace-nowrap pr-4 font-semibold">Year</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hp-slate/10">
                        @foreach ($students as $row)
                            <tr class="h-11 text-hp-slate">
                                <td class="whitespace-nowrap px-4 font-medium">{{ $row->student?->name ?? 'Unknown student' }}</td>
                                <td class="whitespace-nowrap pr-4 text-hp-slate/70">{{ $row->student?->studentProfile?->student_number ?? '—' }}</td>
                                <td class="whitespace-nowrap pr-4 text-hp-slate/70">{{ $row->student?->studentProfile?->course ?? '—' }}</td>
                                <td class="whitespace-nowrap pr-4 text-hp-slate/70">{{ $row->student?->studentProfile?->year_level ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-hp.card>
    </div>

    {{-- Card 3 (D-109, prompt 07): the attached signed memo goes here. --}}

    {{-- ── Card 4: schedule ─────────────────────────────────────────────── --}}
    <x-hp.card class="mt-6">
        <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Schedule</p>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">

            {{-- Calendar: each day's number sits inside a ring that fills
                 clockwise from 12 o'clock with how many students that day
                 already holds (booked ÷ daily capacity). --}}
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
                                        :disabled="!cell.fits"
                                        :title="cell.blank ? '' : cellTitle(cell)"
                                        :aria-label="cell.blank ? '' : `${cell.date} — ${cellTitle(cell)}`"
                                        :aria-pressed="selectedDate === cell.date"
                                        class="relative flex h-full w-full items-center justify-center rounded-full
                                               transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange focus-visible:ring-offset-1"
                                        :class="{
                                            'bg-hp-orange text-white shadow-sm hp-anim-pop': selectedDate === cell.date,
                                            'text-hp-slate hover:bg-hp-peach/40 cursor-pointer': cell.fits && selectedDate !== cell.date,
                                            'text-hp-slate/25 cursor-not-allowed': !cell.fits,
                                        }">
                                    {{-- The ring: pathLength=100, so the dash IS the
                                         percentage; rotate(-90) starts it at 12 o'clock and
                                         SVG draws clockwise. A selected day draws it in white
                                         over a lighter track, so it stays visible. --}}
                                    <svg x-show="cell.booked > 0" viewBox="0 0 40 40" aria-hidden="true"
                                         class="pointer-events-none absolute inset-0 h-full w-full"
                                         :class="!cell.fits && selectedDate !== cell.date ? 'opacity-40' : ''">
                                        <circle cx="20" cy="20" r="17" fill="none" stroke-width="3" pathLength="100"
                                                :stroke="selectedDate === cell.date ? 'rgb(255 255 255 / 0.35)' : 'rgb(var(--hp-peach))'"/>
                                        <circle cx="20" cy="20" r="17" fill="none" stroke-width="3" pathLength="100"
                                                stroke-linecap="round" transform="rotate(-90 20 20)"
                                                :stroke="selectedDate === cell.date ? 'rgb(255 255 255)' : 'rgb(var(--hp-orange))'"
                                                :stroke-dasharray="`${cell.fill * 100} 100`"/>
                                    </svg>
                                    <span class="relative text-base font-semibold leading-none sm:text-xl" x-text="cell.number"></span>
                                    {{-- Today: a small dot under the number. --}}
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

            {{-- Hour picker + decision buttons --}}
            <div>
                <p class="text-sm text-hp-slate/70" x-show="selectedDate === ''">
                    Pick a day on the calendar to see its clinic hours.
                </p>

                <div x-show="selectedDate !== ''" x-cloak>
                    <p class="text-sm text-hp-slate/70">
                        Clinic date: <span class="font-semibold text-hp-slate" x-text="dateLabel"></span>
                    </p>

                    {{-- A native select: hours this batch can't start at are
                         disabled options, greyed like any disabled choice, and
                         every hour says who is already in it. --}}
                    <label for="start-time" class="mt-4 block text-sm font-semibold text-hp-slate">Start hour</label>
                    <select id="start-time" x-model="startTime" :disabled="hoursLoading" data-start-time
                            class="mt-1.5 w-full rounded-lg border-hp-slate/20 bg-hp-white py-2.5 pl-3 pr-8 text-sm text-hp-slate
                                   focus:border-hp-orange focus:ring-hp-orange disabled:opacity-60">
                        <option value="" x-text="hoursLoading ? 'Loading hours…' : '— Pick a start hour —'"></option>
                        <template x-for="hour in hours" :key="hour.slot">
                            <option :value="hour.slot" :disabled="!hour.selectable" x-text="optionText(hour)"></option>
                        </template>
                    </select>

                    <p x-show="spanSummary !== ''" x-cloak data-span-summary
                       class="mt-3 rounded-lg bg-hp-peach/40 px-3 py-2 text-sm font-semibold text-hp-orange"
                       x-text="spanSummary"></p>

                    {{-- BR-25: students already scheduled that day. Approve stays
                         disabled; the server refuses it too. --}}
                    <p x-show="clash.count > 0" x-cloak data-clash-note
                       class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">
                        <span x-text="clash.count"></span>
                        <span x-text="clash.count === 1 ? 'student already has' : 'students already have'"></span>
                        a schedule that day: <span x-text="clashNames"></span>. Pick another day.
                    </p>

                    <p x-show="!hoursLoading && hours.length > 0 && !hours.some((h) => h.selectable)" x-cloak
                       class="mt-3 text-sm text-hp-slate/60">
                        No start hour can take this batch on that day — pick another day.
                    </p>
                </div>

                <div class="mt-8 flex flex-wrap gap-3 border-t border-hp-slate/10 pt-5">
                    <x-hp.button type="button" @click="openApprove()" x-bind:disabled="!canApprove" data-approve>
                        Approve
                    </x-hp.button>
                    <x-hp.button type="button" variant="danger" @click="openReject()">
                        Reject
                    </x-hp.button>
                </div>
                <p class="mt-2 text-xs text-hp-slate/50" x-show="!canApprove">
                    Pick a day and a start hour to approve.
                </p>
            </div>
        </div>
    </x-hp.card>

    {{-- ── Approve confirmation ─────────────────────────────────────────── --}}
    {{-- Teleported to <body> so no ancestor's overflow/stacking clips the
         full-screen backdrop (same pattern as <x-logout-confirm>). --}}
    <template x-teleport="body">
        <div x-show="approveOpen" x-cloak @keydown.escape.window="approveOpen = false"
             class="fixed inset-0 z-[60] flex items-center justify-center px-4"
             role="dialog" aria-modal="true" aria-labelledby="approve-batch-title">
            <div x-show="approveOpen" @click="approveOpen = false"
                 x-transition:enter="ease-hp-out duration-hp-base" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-hp-in duration-hp-fast" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-hp-slate/50" aria-hidden="true"></div>

            <div x-show="approveOpen"
                 x-transition:enter="ease-hp-spring duration-hp-slow" x-transition:enter-start="opacity-0 translate-y-6" x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="ease-hp-in duration-hp-base" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-6"
                 class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl">
                <h2 id="approve-batch-title" class="text-lg font-semibold text-hp-slate">
                    Approve <span x-text="ref"></span> for <span x-text="dateLabel"></span>, <span x-text="spanRange"></span>?
                </h2>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    One appointment is created for each of the {{ $studentCount }} students,
                    {{ $hourlyCapacity }} per hour, and each student is emailed their schedule and arrival time.
                </p>
                <p class="mt-1.5 text-sm text-hp-slate/70">Form: <strong>{{ $batch->formTypeLabel() }}</strong></p>

                {{-- @submit sets `submitting` (without preventing the POST) so a
                     double-click can't fire twice; the server's locked re-check
                     is the real guard. --}}
                <form method="POST" action="{{ route('director.batches.approve', $batch) }}" @submit="submitting = true" class="mt-6">
                    @csrf
                    <input type="hidden" name="scheduled_date" :value="selectedDate">
                    <input type="hidden" name="start_time" :value="startTime">

                    <div class="flex flex-wrap justify-end gap-3">
                        <x-hp.button variant="muted" @click="approveOpen = false">Cancel</x-hp.button>
                        <x-hp.button type="submit" x-bind:disabled="submitting">
                            <span x-text="submitting ? 'Approving…' : 'Confirm & Approve'"></span>
                        </x-hp.button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    {{-- ── Reject (FR-DIRA-04, D-36 — reason required) ──────────────────── --}}
    <template x-teleport="body">
        <div x-show="rejectOpen" x-cloak @keydown.escape.window="rejectOpen = false"
             class="fixed inset-0 z-[60] flex items-center justify-center px-4"
             role="dialog" aria-modal="true" aria-labelledby="reject-batch-title">
            <div x-show="rejectOpen" @click="rejectOpen = false"
                 x-transition:enter="ease-hp-out duration-hp-base" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-hp-in duration-hp-fast" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-hp-slate/50" aria-hidden="true"></div>

            <div x-show="rejectOpen"
                 x-transition:enter="ease-hp-spring duration-hp-slow" x-transition:enter-start="opacity-0 translate-y-6" x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="ease-hp-in duration-hp-base" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-6"
                 class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl">
                <h2 id="reject-batch-title" class="text-lg font-semibold text-hp-slate">Reject {{ $batch->reference_no }}?</h2>
                <p class="mt-1 text-sm text-hp-slate/70">Form: <strong>{{ $batch->formTypeLabel() }}</strong></p>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    No appointments are created. The college admin sees this reason on
                    Batch Tracking, so write what they need to do — e.g.
                    &ldquo;the roster includes students from another program, please resubmit&rdquo;.
                </p>

                <form method="POST" action="{{ route('director.batches.reject', $batch) }}" @submit="rejecting = true" class="mt-5">
                    @csrf

                    <label for="rejection-reason" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                        Reason for rejection
                    </label>
                    <textarea id="rejection-reason" name="rejection_reason" x-model="reason" rows="4" required
                              minlength="{{ $reasonMin }}" maxlength="{{ $reasonMax }}"
                              class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate focus:border-hp-orange focus:ring-hp-orange"></textarea>

                    {{-- Live counter. Convenience only — RejectBatchRequest is the real gate. --}}
                    <p class="mt-1.5 text-xs" :class="reasonIsValid ? 'text-hp-slate/50' : 'text-red-500'">
                        <span x-text="reason.trim().length"></span>/{{ $reasonMax }}
                        <span x-show="! reasonIsValid" x-cloak>— at least {{ $reasonMin }} characters</span>
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-hp.button variant="muted" @click="rejectOpen = false">Cancel</x-hp.button>
                        <x-hp.button type="submit" variant="danger" x-bind:disabled="! reasonIsValid || rejecting">
                            <span x-text="rejecting ? 'Rejecting…' : 'Reject Batch'"></span>
                        </x-hp.button>
                    </div>
                </form>
            </div>
        </div>
    </template>

</div>

</x-layout.sidebar>
