{{--
    "Resend" link with the 60-second cooldown countdown (Part C).

    $remaining is SERVER-computed (seconds until resend_available_at) so a page
    refresh picks the countdown up mid-way instead of resetting it, and no
    client clock is trusted. The button disables itself and ticks down via
    Alpine; when it hits zero it becomes clickable. The server rejects early
    resends regardless — this is presentation only.
--}}
@props(['action', 'remaining' => 0])

<div
    class="mt-5 text-center text-[12px] text-hp-slate/50"
    x-data="{
        remaining: {{ (int) $remaining }},
        init() {
            const timer = setInterval(() => {
                if (this.remaining > 0) this.remaining--;
                else clearInterval(timer);
            }, 1000);
        }
    }"
>
    Didn't receive a code?
    <form method="POST" action="{{ $action }}" class="inline">
        @csrf
        <button
            type="submit"
            :disabled="remaining > 0"
            class="ml-1 font-semibold text-hp-orange underline-offset-2 hover:underline
                   disabled:cursor-not-allowed disabled:text-hp-slate/40 disabled:no-underline"
            x-text="remaining > 0 ? `Resend in ${remaining}s` : 'Resend'"
        >Resend</button>
    </form>
</div>
