@props(['answer' => null])

{{--
    One Yes / No / Quit answer as a pill, in the kiosk's colour language —
    orange = something reported, green = all clear, grey = anything else.
    NULL means the question was never answered and shows a dash, which is not
    the same as "No".

    Takes a boolean (the twelve Physical Signs rows, pregnancy) or the string
    a display helper already produced (ScreeningResponse::socialHistoryRows()).
--}}
@php
    $text = match (true) {
        $answer === null => null,
        $answer === true => 'Yes',
        $answer === false => 'No',
        default => (string) $answer,
    };

    $classes = match ($text) {
        'Yes' => 'bg-hp-orange/15 text-hp-orange',
        'No' => 'bg-emerald-50 text-emerald-600',
        default => 'bg-hp-slate/10 text-hp-slate',
    };
@endphp

@if ($text === null)
    <span {{ $attributes->merge(['class' => 'text-xs text-hp-slate/40']) }}>&mdash;</span>
@else
    <span {{ $attributes->merge([
        'class' => "inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold leading-tight {$classes}",
    ]) }}>{{ $text }}</span>
@endif
