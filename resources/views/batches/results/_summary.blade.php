{{--
    D-111: the three summary cards above the student table, styled like the
    dashboard stat cards — side by side from `sm`, stacked on a phone.

    $summary is BatchRequest::resultsSummary(): total, completed, fit and
    rescheduled. "Rescheduled" counts every student who was EVER given a 2nd
    chance (D-110), including those who have since completed.
--}}
@php
    $total = $summary['total'];
    $completed = $summary['completed'];

    $cards = [
        [
            'label' => 'Completed',
            'value' => $completed,
            'caption' => 'out of '.$total.' '.Str::plural('student', $total).' completed',
        ],
        [
            'label' => 'Fit',
            'value' => $summary['fit'],
            'caption' => 'out of '.$completed.' completed '.Str::plural('student', $completed)
                .' '.($summary['fit'] === 1 ? 'is' : 'are').' fit',
        ],
        [
            'label' => 'Rescheduled',
            'value' => $summary['rescheduled'],
            'caption' => 'out of '.$total.' '.Str::plural('student', $total)
                .' '.($summary['rescheduled'] === 1 ? 'was' : 'were').' rescheduled',
        ],
    ];
@endphp

{{-- hp-stagger + data-hp-countup: the dashboard's motion — cards fade up in
     turn and each number counts up from 0. --}}
<div class="hp-stagger mb-6 grid grid-cols-1 gap-5 sm:grid-cols-3" data-results-summary>
    @foreach ($cards as $card)
        <x-hp.card>
            <p class="hp-card-title">{{ $card['label'] }}</p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $card['value'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">{{ $card['caption'] }}</p>
        </x-hp.card>
    @endforeach
</div>
