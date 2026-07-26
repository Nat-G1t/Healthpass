{{--
    Light/dark theme toggle (D-38 / FR-UI-04).

    Pairs with partials/theme-init.blade.php — that script has already picked
    the theme and applied it before first paint; this button only *changes* it.
    Alpine holds the reactive flag here exactly like the sidebar's `collapsed`
    state, and the choice is persisted to localStorage. There is no request and
    no `users` column: the theme is a per-device display preference.

    Include the theme-init partial in the same document or the toggle will
    start out of sync with what is on screen.
--}}
<button
    type="button"
    x-data="{
        {{-- Seeded from the pre-paint script so the icon never disagrees with
             the page it is sitting on. --}}
        dark: window.__hpTheme === 'dark',

        toggle() {
            this.dark = !this.dark;

            var root = document.documentElement;

            {{-- .hp-theming carries the colour cross-fade in app.css. It is
                 added only for the length of the transition, so ordinary page
                 loads and navigations are never animated. Kept slightly longer
                 than --hp-dur-base (250ms) so the fade finishes before the
                 transition rule is pulled out from under it. --}}
            root.classList.add('hp-theming');
            window.setTimeout(function () { root.classList.remove('hp-theming'); }, 320);

            root.classList.toggle('dark', this.dark);
            if (this.dark) {
                root.setAttribute('data-theme', 'dark');
            } else {
                root.removeAttribute('data-theme');
            }

            try { window.localStorage.setItem('hp-theme', this.dark ? 'dark' : 'light'); } catch (e) {}

            {{-- Anything painted to a <canvas> cannot follow a CSS variable —
                 Chart.js bakes its colours in at construction. The Director
                 analytics bundle listens for this and re-colours its charts. --}}
            window.dispatchEvent(new CustomEvent('hp:themechange', {
                detail: { theme: this.dark ? 'dark' : 'light' },
            }));
        }
    }"
    @click="toggle()"
    :aria-pressed="dark.toString()"
    :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
    :title="dark ? 'Switch to light theme' : 'Switch to dark theme'"
    {{ $attributes->merge([
        'class' => 'shrink-0 rounded-md p-1.5 text-hp-slate/60 hover:bg-hp-slate/10 hover:text-hp-slate
                    transition-colors duration-hp-fast ease-hp-out
                    focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange',
    ]) }}
>
    {{-- Sun — shown while DARK is active (click to go light). x-cloak keeps
         both icons hidden until Alpine decides, instead of flashing both. --}}
    <svg x-show="dark" x-cloak width="18" height="18" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"/>
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
    </svg>

    {{-- Moon — shown while LIGHT is active (click to go dark). --}}
    <svg x-show="!dark" x-cloak width="18" height="18" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
</button>
