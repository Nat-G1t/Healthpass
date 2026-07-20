{{--
    Skeleton shimmer block (§5.4) — a placeholder for content still being
    fetched IN-PAGE (e.g. the Book Appointment availability fetch). Size it
    from the caller so it reserves the REAL dimensions of what will land —
    that keeps layout shift at zero when the content arrives.

    Do NOT use this over server-rendered content: the splash already covers
    full-page loads, and a skeleton that flashes for 50 ms is worse than
    nothing.

    Usage: <x-hp.skeleton class="h-10 w-full" />
--}}
<div {{ $attributes->merge(['class' => 'hp-skeleton']) }} aria-hidden="true"></div>
