{{--
    Dark-mode bootstrap (D-38 / FR-UI-04) — MUST be included in <head>, and
    must stay INLINE and un-bundled.

    Why inline: this runs before the browser paints the first frame. If the
    theme class were applied by Alpine (or anything in the Vite bundle) the
    page would paint LIGHT first and then snap to dark — a visible white flash
    on every reload. Same FOUC-prevention pattern as `window.__hpSidebarCollapsed`
    in components/layout/sidebar.blade.php.

    Resolution order:
      1. an explicit choice the user made before (localStorage 'hp-theme')
      2. otherwise the OS setting, `prefers-color-scheme: dark`
    Once the user toggles manually, step 1 wins forever — we never silently
    re-follow the OS after that.

    Two markers are written on <html> on purpose:
      .dark              — what Tailwind's `dark:` variant matches
      data-theme="dark"  — a stable hook for hand-written CSS in app.css
    `window.__hpTheme` is what the Alpine toggle seeds its reactive flag from.

    NOT included by resources/views/kiosk/** — the kiosk is a fixed clinic
    appliance and stays permanently light (D-26 + D-38).
--}}
<script>
    (function () {
        var stored = null;
        // localStorage throws in private-mode / cookie-blocked browsers.
        // A theme preference is never worth breaking the page over.
        try { stored = window.localStorage.getItem('hp-theme'); } catch (e) {}

        var isDark = stored === 'dark' || stored === 'light'
            ? stored === 'dark'
            : window.matchMedia('(prefers-color-scheme: dark)').matches;

        window.__hpTheme = isDark ? 'dark' : 'light';

        if (isDark) {
            document.documentElement.classList.add('dark');
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
</script>
