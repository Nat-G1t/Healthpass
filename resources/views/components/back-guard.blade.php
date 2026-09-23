{{--
    Back guard for dashboards (FR-UI-07).

    On a dashboard the browser's Back button opens the "Log out?" dialog
    instead of leaving the page. How: we add one extra history entry for the
    same URL. Pressing Back pops that entry (the page doesn't change, but the
    browser fires `popstate`); we then put the entry back and ask the sidebar's
    <x-logout-confirm> to open through a window event. Cancel simply closes
    the dialog, and because the entry is back in place the next Back asks again.

    Chrome skips history entries a page added without a user gesture, so the
    entry is pushed on load AND once more on the first click or key press.
    If Chrome still skips it, the guest pages are sent `no-store`, so the user
    is redirected to their dashboard rather than shown an expired form.

    Usage: <x-back-guard /> once, inside a dashboard view.
--}}
<div data-back-guard hidden></div>

<script>
    (() => {
        const pushGuard = () => history.pushState({ hpBackGuard: true }, '', location.href);

        // A reload keeps the current entry's state — don't stack a second guard.
        if (!history.state?.hpBackGuard) {
            pushGuard();
        }

        const pushOnGesture = () => {
            pushGuard();
            window.removeEventListener('pointerdown', pushOnGesture);
            window.removeEventListener('keydown', pushOnGesture);
        };
        window.addEventListener('pointerdown', pushOnGesture);
        window.addEventListener('keydown', pushOnGesture);

        window.addEventListener('popstate', () => {
            pushGuard();
            window.dispatchEvent(new CustomEvent('open-logout-confirm'));
        });
    })();
</script>
