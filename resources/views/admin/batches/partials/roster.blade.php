{{--
    The students in one batch — one page of them (FR-UI-06) — shared by the
    Batch Roster page (FR-ADM-07) and, since D-88, the Batch Request Submitted
    page. Expects `$batch` and `$rows` (BatchRequestController::rosterPage()).

    Appointments only exist once the Director has approved (BR-08), so the
    Appointment / Time columns are meaningless on a pending, rejected or
    cancelled batch — the roster there is just "who was submitted".
--}}
@php
    $isApproved = $batch->status === 'approved';

    $headers = $isApproved
        ? ['Student', 'Student No.', 'Appointment', 'Time']
        : ['Student', 'Student No.', 'Course & Year'];
@endphp

@if ($rows->isEmpty())
    <p class="py-6 text-center text-sm text-hp-slate/50">
        This batch has no students on it.
    </p>
@else
    <x-hp.table :headers="$headers">
        @foreach ($rows as $row)
            @php
                $profile = $row->student?->studentProfile;
                $appointment = $row->appointment;
            @endphp
            <x-hp.table-row>
                <x-hp.table-cell label="Student" class="font-medium">
                    {{ $row->student?->name ?? 'Unknown student' }}
                </x-hp.table-cell>

                <x-hp.table-cell label="Student No." class="text-hp-slate/60">
                    {{ $profile?->student_number ?? '—' }}
                </x-hp.table-cell>

                @if ($isApproved)
                    <x-hp.table-cell label="Appointment" class="font-mono text-xs">
                        {{ $appointment?->reference_no ?? '—' }}
                    </x-hp.table-cell>

                    <x-hp.table-cell label="Time">
                        {{-- "—" on pre-D-37 appointments, which sit in no slot --}}
                        {{ $appointment?->timeRangeLabel() ?? '—' }}
                    </x-hp.table-cell>
                @else
                    <x-hp.table-cell label="Course & Year" class="text-hp-slate/60">
                        {{ $profile?->course ?? '—' }}
                        @if ($profile?->year_level)
                            · Year {{ $profile->year_level }}
                        @endif
                    </x-hp.table-cell>
                @endif
            </x-hp.table-row>
        @endforeach
    </x-hp.table>

    <x-hp.pager :paginator="$rows" />
@endif
