{{--
    D-111: one batch's status as a badge. $status is a
    BatchRequest::resultsStatus() key; the model decides it, this partial only
    words and colours it. Shared by the Batch Results card and the results
    page header, so the two always read the same.
--}}
@php
    [$variant, $label] = match ($status) {
        'scheduled' => ['neutral', 'Scheduled'],
        'in_progress' => ['live', 'In progress'],
        'still_in_progress' => ['pending', 'Still in progress'],
        'completed' => ['approved', 'Completed'],
        'finished_incomplete' => ['danger', 'Finished incomplete'],
        'no_one_attended' => ['rejected', 'No one attended'],
    };
@endphp

<x-hp.badge :variant="$variant">{{ $label }}</x-hp.badge>
