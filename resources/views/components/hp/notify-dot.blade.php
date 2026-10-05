{{--
    D-120: the notification dot at the end of a Batch Tracking row — this
    batch has news the viewer has not opened yet. App\Support\BatchNotifications
    decides it; clicking the row's View clears it.

    `show` is the dot's on/off. A rejected request's reason popup clears it
    without leaving the page, so it can be bound to Alpine state with
    x-show on the caller's side as well.
--}}
@props(['show' => true])

@if ($show)
    <span {{ $attributes->merge(['class' => 'inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-hp-orange ring-2 ring-hp-peach']) }}
          data-notify-dot>
        <span class="sr-only">New update</span>
    </span>
@endif
