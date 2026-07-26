@props(['class' => ''])

{{--
    dark:border-hp-slate/20 — in dark the card sits on a background only 1.12:1
    away from it, so the border is what actually separates the two surfaces.
    At /15 that hairline measures 1.50:1 against the card and reads as flat;
    /20 lifts it to ~1.8:1 without turning into a drawn box. (D-38)
--}}
<div {{ $attributes->merge([
    'class' => "bg-hp-white rounded-xl border border-hp-slate/15 dark:border-hp-slate/20 p-6 {$class}",
]) }}>
    {{ $slot }}
</div>
