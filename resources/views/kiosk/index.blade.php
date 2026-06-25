<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- Fixed canvas: we scale it ourselves, so disable browser zoom/scroll. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>HealthPass — Clinic Kiosk</title>

    @vite(['resources/css/app.css', 'resources/js/kiosk/kiosk.js'])

    <style>
        /* ── Responsive fill: the panel fills the whole display — no letterbox.
              On the 800×480 target it maps 1:1; on other screens the flexbox
              content reflows to fit (no bars, no distortion). ──────────────── */
        :root {
            /* Global readability zoom for the 7" screen. Every rem-based size
               (fonts, spacing, the QR target) scales with this, so tune the
               whole UI bigger/smaller from this one place. */
            --k-zoom: 1.3;
            /* The vitals screen runs 30% larger for legibility of the readings
               on the 7″ panel — applied ONLY while that screen is active (class
               toggled by Alpine), so every other screen keeps the 1.3 baseline.
               The 800×480 panel is pixel-based, so only rem content scales. */
            --k-zoom-vitals: 1.69;
        }
        html {
            font-size: calc(100% * var(--k-zoom));
        }
        html.k-zoom-vitals {
            font-size: calc(100% * var(--k-zoom-vitals));
        }
        html, body {
            margin: 0;
            height: 100%;
            background: #1c1917; /* only visible for a frame before first paint */
            overflow: hidden;
        }

        .kiosk-stage {
            position: fixed;
            inset: 0;
        }

        /* Fills the viewport edge-to-edge — no fixed canvas, no transform. */
        .kiosk-panel {
            position: absolute;
            inset: 0;
            background: var(--hp-bg);
            overflow: hidden;
        }

        /* Each screen fills the panel; only one is shown at a time via x-show. */
        .kiosk-screen {
            position: absolute;
            inset: 0;
            display: flex;
        }

        /* Invisible but focusable — catches USB QR-scanner keyboard-wedge input. */
        .kiosk-wedge {
            position: absolute;
            top: 0;
            left: 0;
            width: 1px;
            height: 1px;
            opacity: 0;
            border: 0;
            padding: 0;
        }

        /* Per-tap key-press pop for the on-screen keyboard (FR-KSK-02).
           Starts in the "pressed" look (shrunk + orange flash) and animates
           back to rest, so a full animation plays on EVERY touch even if the
           tap is instant — `:active` alone is too brief to notice. */
        @keyframes k-key-press {
            0%   { transform: scale(0.88); background-color: #FF8C2A; color: #FFFFFF; }
            100% { transform: scale(1); }
        }
        .k-key-press {
            animation: k-key-press 180ms ease-out;
        }

        /* Pulsing QR target ring (FR-KSK-01). */
        @keyframes k-pulse {
            0%   { transform: scale(1);    opacity: 0.55; }
            70%  { transform: scale(1.18); opacity: 0;    }
            100% { transform: scale(1.18); opacity: 0;    }
        }
        .k-pulse-ring {
            animation: k-pulse 2s ease-out infinite;
        }

        /* Dev-only screen jumper — lives outside the scaled panel. */
        .kiosk-devbar {
            position: fixed;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            padding: 6px 8px;
            background: rgba(0, 0, 0, 0.6);
            border-radius: 8px;
            z-index: 50;
        }
        .kiosk-devbar button {
            font: 500 11px/1 'Poppins', sans-serif;
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
            border: 0;
            border-radius: 5px;
            padding: 5px 8px;
            cursor: pointer;
        }
        .kiosk-devbar button:hover { background: var(--hp-orange); }
    </style>
</head>
<body class="font-sans antialiased">
    <div
        x-data="kiosk()"
        x-ref="root"
        {{-- Scale up only while the vitals screen is showing (see --k-zoom-vitals). --}}
        x-effect="document.documentElement.classList.toggle('k-zoom-vitals', state.screen === 'vitals')"
        data-scan-url="{{ route('kiosk.scan') }}"
        data-login-url="{{ route('kiosk.login') }}"
        data-submit-url="{{ route('kiosk.submit') }}"
        data-csrf="{{ csrf_token() }}"
        {{-- Plausibility ranges (FR-KSK-08) + flag thresholds (BR-13) straight
             from config/healthpass.php, so client badges/checks match the server. --}}
        data-config="{{ json_encode([
            'validation' => config('healthpass.validation'),
            'bmiObese' => config('healthpass.thresholds.bmi_obese'),
            'thresholds' => [
                'tempMax' => config('healthpass.thresholds.temperature_max'),
                'bpSystolic' => config('healthpass.thresholds.bp_systolic'),
                'bpDiastolic' => config('healthpass.thresholds.bp_diastolic'),
            ],
            'kiosk' => [
                'completeResetSeconds' => config('healthpass.kiosk.complete_reset_seconds'),
                'idleTimeoutSeconds' => config('healthpass.kiosk.idle_timeout_seconds'),
            ],
        ]) }}"
        {{-- Any touch/keypress mid-flow restarts the 90s idle countdown (FR-KSK-15). --}}
        @pointerdown="bumpIdle()"
        @keydown="bumpIdle()"
    >
        <div class="kiosk-stage">
            {{-- Tapping anywhere on the panel returns focus to the scanner input. --}}
            <div class="kiosk-panel" @click="focusWedge()">
                <input
                    type="text"
                    class="kiosk-wedge"
                    x-ref="wedge"
                    @keydown.enter.prevent="onWedgeEnter($event)"
                    @blur="focusWedge()"
                    autocomplete="off"
                    aria-hidden="true"
                    tabindex="-1"
                >

                @include('kiosk.screens.welcome')
                @include('kiosk.screens.email-login')
                @include('kiosk.screens.identity')
                @include('kiosk.screens.consent')
                @include('kiosk.screens.vitals')
                @include('kiosk.screens.questionnaire')
                @include('kiosk.screens.review')
                @include('kiosk.screens.complete')
            </div>
        </div>

        @includeWhen(app()->isLocal(), 'kiosk.partials.dev-jumper')
    </div>
</body>
</html>
