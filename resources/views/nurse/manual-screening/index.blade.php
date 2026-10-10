<x-layout.sidebar title="Manual Screening">

{{--
    Manual Screening — the search (D-133). The nurse's only (role:nurse).

    When the kiosk is down, the nurse finds the student here, opens the form
    and types in what the kiosk would have collected. Only today's scheduled
    students who have NOT been screened yet are ever listed (Q12) — the one
    rule Appointment::scopeManuallyScreenable() — matched on name or student
    ID, at most twenty (ManualScreeningController::MAX_RESULTS).

    <x-hp.table> re-flows each row into a stacked card below `md`, so the page
    never slides sideways on a phone.
--}}
@php
    $schedule = app(\App\Services\ClinicScheduleService::class);
    $minLength = \App\Http\Controllers\Nurse\ManualScreeningController::MIN_SEARCH_LENGTH;
    $maxResults = \App\Http\Controllers\Nurse\ManualScreeningController::MAX_RESULTS;

    $screenLink = 'inline-flex items-center justify-center gap-2 rounded-full bg-hp-orange px-4 py-1.5
                   text-xs font-semibold text-white transition-colors duration-hp-fast hover:bg-orange-500
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange
                   focus-visible:ring-offset-1';
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <a href="{{ route('nurse.queue') }}"
       class="text-sm font-medium text-hp-slate/60 transition-colors hover:text-hp-orange">
        ← Back to Live Queue
    </a>
    <h2 class="mt-2 text-xl font-semibold text-hp-slate">Manual Screening</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        For when the kiosk is down. Find one of today’s scheduled students who hasn’t been screened yet,
        enter their readings and answers, and send them to the physician.
    </p>
</div>

{{-- A student who was screened at the kiosk meanwhile, or an appointment
     that is gone — ManualScreeningController / SubmitManualScreening. --}}
@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

<x-hp.card>
    <form method="GET" action="{{ route('nurse.manual.index') }}" role="search"
          class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <x-hp.input label="Name or student ID" id="manual-search" name="q" type="search"
                        value="{{ $search }}" minlength="{{ $minLength }}" maxlength="60"
                        placeholder="e.g. Dela Cruz or 2023-123456" autocomplete="off" autofocus />
        </div>
        <x-hp.button type="submit">Search</x-hp.button>
    </form>

    <div class="mt-5" data-manual-results>
        @if (! $hasSearched)
            <p class="py-6 text-center text-sm text-hp-slate/50">
                @if ($search !== '')
                    Type at least {{ $minLength }} characters.
                @else
                    Search by the student’s name or student ID.
                @endif
            </p>
        @elseif ($results->isEmpty())
            <p class="py-6 text-center text-sm text-hp-slate/50">
                No student matches “{{ $search }}”. Only students scheduled today who haven’t been screened yet are listed.
            </p>
        @else
            <x-hp.table :headers="['Name', 'Student ID', 'College', 'Batch ID', 'Form', 'Hour', '']">
                @foreach ($results as $appointment)
                    @php
                        $profile = $appointment->student?->studentProfile;
                        $formType = $appointment->formType();
                    @endphp
                    <x-hp.table-row data-appointment-id="{{ $appointment->id }}">
                        <x-hp.table-cell label="Name" class="font-semibold">{{ $appointment->student?->name }}</x-hp.table-cell>
                        <x-hp.table-cell label="Student ID" class="whitespace-nowrap font-mono text-xs">{{ $profile?->student_number ?? '—' }}</x-hp.table-cell>
                        <x-hp.table-cell label="College">{{ $profile?->college?->name ?? '—' }}</x-hp.table-cell>
                        <x-hp.table-cell label="Batch ID" class="whitespace-nowrap">{{ $appointment->batchRequest?->reference_no ?? '—' }}</x-hp.table-cell>
                        <x-hp.table-cell label="Form">
                            <x-hp.badge variant="neutral" data-form-type="{{ $formType }}">
                                {{ \App\Models\BatchRequest::FORM_TYPES[$formType] }}
                            </x-hp.badge>
                        </x-hp.table-cell>
                        <x-hp.table-cell label="Hour" class="whitespace-nowrap">
                            {{ $appointment->scheduled_time ? $schedule->label($appointment->scheduled_time) : '—' }}
                            @if ($appointment->arrivalTimeLabel())
                                <span class="block text-xs text-hp-slate/50">Arrival {{ $appointment->arrivalTimeLabel() }}</span>
                            @endif
                        </x-hp.table-cell>
                        <x-hp.table-cell label="" class="md:text-right">
                            <a href="{{ route('nurse.manual.show', $appointment) }}" class="{{ $screenLink }}">Screen</a>
                        </x-hp.table-cell>
                    </x-hp.table-row>
                @endforeach
            </x-hp.table>

            @if ($results->count() >= $maxResults)
                <p class="mt-3 text-xs text-hp-slate/50">
                    Showing the first {{ $maxResults }} — type more of the name or ID to narrow it down.
                </p>
            @endif
        @endif
    </div>
</x-hp.card>

</x-layout.sidebar>
