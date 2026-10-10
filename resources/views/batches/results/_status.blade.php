{{--
    D-111: one batch's status as a badge. $status is a
    BatchRequest::resultsStatus() key; the model decides it, this partial only
    colours it. The words are BatchRequest::RESULTS_STATUSES, which the
    Director's status filter (D-130) also lists. Shared by the Batch Results
    card and the results page header, so the two always read the same.
--}}
@php
    $variant = match ($status) {
        'scheduled' => 'neutral',
        'in_progress' => 'live',
        'still_in_progress' => 'pending',
        'completed' => 'approved',
        'finished_incomplete' => 'danger', // D-121: label only, the key stays
        'no_one_attended' => 'rejected',
    };
@endphp

<x-hp.badge :variant="$variant">{{ \App\Models\BatchRequest::RESULTS_STATUSES[$status] }}</x-hp.badge>
