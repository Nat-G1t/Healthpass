{{--
    Branded loading splash (§5.2 of the motion pass — the "slow internet" fix).

    Sits immediately after <body> in every user-facing shell. Everything is
    INLINE (style + script) on purpose: on a slow connection this must paint
    from the raw HTML before the Vite CSS/JS bundle ever arrives, so it cannot
    depend on app.css, Alpine, or the fonts.

    Behaviour:
    - Invisible for the first 200 ms (animation-delay) — fast loads never see
      a flash of splash.
    - Fades out on window `load` (or pageshow, for back/forward-cache
      restores), with a 10 s failsafe timeout and a <noscript> hide, so it can
      never permanently cover a rendered page.
    - Overlays the page (position: fixed) — zero layout shift when it lifts.

    The durations mirror the --hp-* motion tokens in app.css; they are inlined
    here (with the token names in comments) because this block must be
    self-contained.
--}}
<div id="hp-splash" role="status" aria-label="Loading">
    <style>
        #hp-splash {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 18px;
            background: #F6F2ED; /* --hp-bg */
            opacity: 0;
            visibility: hidden;
            /* Appear only when loading is actually slow: the 200 ms delay
               means fast page loads hide the splash before it ever shows. */
            animation: hp-splash-in 250ms cubic-bezier(0.25, 1, 0.5, 1) 200ms forwards; /* --hp-dur-base --hp-ease-out */
        }
        #hp-splash.is-done {
            /* Drop the filling enter animation first, or its `forwards` fill
               would keep opacity pinned at 1 and the fade-out could never win. */
            animation: none;
            opacity: 0;
            visibility: hidden;
            transition: opacity 400ms cubic-bezier(0.55, 0, 1, 0.45), visibility 0s 400ms; /* --hp-dur-slow --hp-ease-in */
        }
        @keyframes hp-splash-in {
            to { opacity: 1; visibility: visible; }
        }
        #hp-splash .hp-splash-word {
            font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif;
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
        }
        #hp-splash .hp-splash-dot {
            width: 8px;
            height: 8px;
            border-radius: 9999px;
            background: #FF8C2A; /* --hp-orange */
            animation: hp-splash-pulse 1.2s cubic-bezier(0.25, 1, 0.5, 1) infinite;
        }
        @keyframes hp-splash-pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(0.6); opacity: 0.4; }
        }
        @media (prefers-reduced-motion: reduce) {
            #hp-splash, #hp-splash * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>

    {{-- HealthPass mark — same inline SVG as components/hp/logo.blade.php,
         duplicated here (with the wordmark colours inlined) because this
         overlay must render before app.css exists. --}}
    <div style="display: flex; align-items: center; gap: 12px;">
        <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="0"  y="14" width="40" height="12" rx="6" fill="#FF8C2A"/>
            <rect x="14" y="0"  width="12" height="40" rx="6" fill="#FF8C2A"/>
        </svg>
        <span class="hp-splash-word">
            <span style="color: #4B5563;">Health</span><span style="color: #FF8C2A;">Pass</span>
        </span>
    </div>

    <div class="hp-splash-dot"></div>

    <script>
        (function () {
            var splash = document.getElementById('hp-splash');
            if (!splash) return;
            var hide = function () { splash.classList.add('is-done'); };
            // Normal loads hide on `load`; back/forward-cache restores re-show
            // the old page without a load event, so `pageshow` covers those.
            window.addEventListener('load', hide);
            window.addEventListener('pageshow', hide);
            // Hard failsafe: never cover a rendered page for more than 10 s
            // (e.g. one image hanging forever keeps `load` from firing).
            setTimeout(hide, 10000);
            if (document.readyState === 'complete') hide();
        })();
    </script>
    <noscript><style>#hp-splash { display: none !important; }</style></noscript>
</div>
