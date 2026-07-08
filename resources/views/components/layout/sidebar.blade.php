@props(['title' => ''])

@php
    use Illuminate\Support\Facades\Auth;

    $user = Auth::user();

    // Nav items per role: [label, route-name, icon-path]
    $navItems = match ($user?->role) {
        'student' => [
            ['Dashboard',        'student.dashboard',    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            ['Book Appointment', 'student.appointments', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['My Records',       'student.records',      'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['My ID & Profile',  'student.id-profile',   'M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2'],
            ['Kiosk Tutorial',   'student.tutorial',     'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h16a2 2 0 012 2v10a2 2 0 01-2 2h-2'],
        ],
        'college_admin' => [
            ['Dashboard',          'admin.dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            ['New Batch Request',  'admin.dashboard', 'M12 4v16m8-8H4'],
            ['Batch Tracking',     'admin.dashboard', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ],
        'nurse' => [
            ['Live Queue',        'nurse.queue',  'M4 6h16M4 10h16M4 14h16M4 18h16'],
            ['Encode Result',     'nurse.queue',  'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
            ['Enable Kiosk Mode', 'nurse.queue',  'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h16a2 2 0 012 2v10a2 2 0 01-2 2h-2'],
        ],
        'director' => [
            ['Dashboard',          'director.dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            ['Batch Approvals',    'director.dashboard', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['Analytics',          'director.dashboard', 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['Flagged Anomalies',  'director.dashboard', 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
        ],
        default => [],
    };

    // Shared for every role: OTP-confirmed password change.
    if ($user) {
        $navItems[] = ['Change Password', 'password.change', 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'];
    }

    // Initials from user name
    $initials = collect(explode(' ', $user?->name ?? ''))
        ->map(fn($w) => strtoupper(substr($w, 0, 1)))
        ->take(2)
        ->implode('');

    $roleLabel = match ($user?->role) {
        'student'       => 'Student',
        'college_admin' => 'College Admin',
        'nurse'         => 'Nurse',
        'director'      => 'Clinic Director',
        default         => '',
    };
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title . ' — HealthPass' : 'HealthPass' }}</title>

    {{--
        Read the saved sidebar choice BEFORE the page paints. We add the
        `sidebar-collapsed` class to <html> here so the first frame already
        has the correct width — this is what prevents the width "flash" (FOUC)
        on reload. Alpine boots a moment later and just keeps this in sync.
    --}}
    <script>
        window.__hpSidebarCollapsed = localStorage.getItem('hp-sidebar-collapsed') === 'true';
        if (window.__hpSidebarCollapsed) {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    </script>

    <style>
        /* Sidebar width is a CSS variable so the rail and the main column
           always stay in step on desktop. */
        :root { --hp-sidebar-w: 220px; }

        /* ── Mobile-first: the sidebar is an off-canvas DRAWER ──────────────
           It floats above the page and slides in from the left. Content keeps
           the full screen width (padding-left: 0) so nothing gets squeezed. */
        .hp-sidebar {
            width: 256px;
            transform: translateX(-100%);
            transition: transform 200ms ease, width 180ms ease;
            z-index: 50;
        }
        .hp-sidebar.is-open { transform: translateX(0); }

        .hp-main-col { padding-left: 0; transition: padding-left 180ms ease; }

        .hp-user-meta { transition: opacity 150ms ease; }

        /* ── Desktop (lg+): the sidebar PUSHES content (collapsible rail) ───
           This is the original behaviour. The rail "collapsed" look only
           exists here — phones always get the full-width drawer above. */
        @media (min-width: 1024px) {
            .hp-sidebar  { width: var(--hp-sidebar-w); transform: translateX(0); }
            .hp-main-col { padding-left: var(--hp-sidebar-w); }

            html.sidebar-collapsed { --hp-sidebar-w: 72px; }

            /* Center the rail toggle when collapsed. */
            html.sidebar-collapsed .hp-logo-row { justify-content: center; }

            /* Hide the name/role block in the footer. */
            html.sidebar-collapsed .hp-user-meta { display: none; }

            /* Collapsed nav item: icon STACKED ABOVE a tiny label.
               The label font is small and wraps inside the 72px rail so long
               labels (e.g. "Book Appointment") never spill over the pill. */
            html.sidebar-collapsed .hp-nav-link {
                flex-direction: column;
                gap: 4px;
                padding: 8px 6px;
                text-align: center;
            }
            html.sidebar-collapsed .hp-nav-label {
                font-size: 9px;
                line-height: 1.15;
                width: 100%;
                overflow-wrap: anywhere;
                word-break: break-word;
            }

            /* Collapsed footer: initials circle above the logout icon. */
            html.sidebar-collapsed .hp-user-row {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">

{{--
    Alpine holds the open/closed flag (`collapsed`). toggle() flips it,
    writes the choice to localStorage so it survives reloads + navigation,
    and toggles the <html> class that the CSS above reacts to.
--}}
<div
    class="flex h-full"
    x-data="{
        collapsed: window.__hpSidebarCollapsed,
        mobileOpen: false,
        toggle() {
            this.collapsed = !this.collapsed;
            localStorage.setItem('hp-sidebar-collapsed', this.collapsed);
            document.documentElement.classList.toggle('sidebar-collapsed', this.collapsed);
        }
    }"
    @keydown.escape.window="mobileOpen = false"
>

    {{-- Dim backdrop behind the mobile drawer (phones only). --}}
    <div x-show="mobileOpen" x-cloak
         x-transition.opacity
         @click="mobileOpen = false"
         class="fixed inset-0 z-40 bg-hp-slate/40 lg:hidden"
         aria-hidden="true"></div>

    {{-- ── Sidebar ──────────────────────────────────────────────────────── --}}
    <aside class="hp-sidebar fixed inset-y-0 left-0 flex flex-col bg-white border-r border-hp-slate/10"
           :class="{ 'is-open': mobileOpen }">

        {{-- Top strip — keeps the 56px alignment line with the header.
             Desktop shows the rail toggle; phones show a close button. --}}
        <div class="hp-logo-row flex h-14 shrink-0 items-center px-4 border-b border-hp-slate/10">

            {{-- Desktop: collapse / expand the rail --}}
            <button
                type="button"
                @click="toggle()"
                aria-label="Toggle sidebar"
                :aria-expanded="(!collapsed).toString()"
                class="hidden lg:flex shrink-0 rounded-md p-1.5 text-hp-slate/60 hover:bg-hp-slate/8 hover:text-hp-slate transition-colors"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="3" y1="6"  x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>

            {{-- Phones: close the drawer --}}
            <button
                type="button"
                @click="mobileOpen = false"
                aria-label="Close menu"
                class="lg:hidden ml-auto shrink-0 rounded-md p-1.5 text-hp-slate/60 hover:bg-hp-slate/8 hover:text-hp-slate transition-colors"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5">
            @foreach ($navItems as [$label, $routeName, $iconPath])
                @php
                    $isActive = request()->routeIs($routeName);
                @endphp
                <a
                    href="{{ route($routeName) }}"
                    class="hp-nav-link flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors duration-100
                           {{ $isActive
                               ? 'bg-hp-peach text-hp-orange font-semibold'
                               : 'text-hp-slate/70 hover:bg-hp-slate/8 hover:text-hp-slate font-medium' }}"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round"
                         class="shrink-0">
                        <path d="{{ $iconPath }}"/>
                    </svg>
                    <span class="hp-nav-label">{{ $label }}</span>
                </a>
            @endforeach
        </nav>

        {{-- User footer --}}
        <div class="shrink-0 border-t border-hp-slate/10 p-3">
            <div class="hp-user-row flex items-center gap-3 rounded-lg px-2 py-2">
                {{-- Initials circle --}}
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-hp-peach text-xs font-bold text-hp-orange">
                    {{ $initials }}
                </div>
                <div class="hp-user-meta min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-hp-slate leading-tight">{{ $user?->name }}</p>
                    <p class="text-[11px] text-hp-slate/50 leading-tight">{{ $roleLabel }}</p>
                </div>
                {{-- Logout — opens a confirmation modal before submitting --}}
                <x-logout-confirm>
                    <x-slot:trigger>
                        <button type="button" title="Log out"
                                @click="open = true"
                                class="text-hp-slate/40 hover:text-hp-slate transition-colors">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                                <polyline points="16 17 21 12 16 7"/>
                                <line x1="21" y1="12" x2="9" y2="12"/>
                            </svg>
                        </button>
                    </x-slot:trigger>
                </x-logout-confirm>
            </div>
        </div>
    </aside>

    {{-- ── Right column ─────────────────────────────────────────────────── --}}
    <div class="hp-main-col flex flex-1 flex-col min-h-full">

        {{-- Header bar — burger (phones only), HealthPass logo, then page title --}}
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 bg-white border-b border-hp-slate/10 px-4 sm:px-6">

            {{-- Phones: open the drawer --}}
            <button
                type="button"
                @click="mobileOpen = true"
                aria-label="Open menu"
                :aria-expanded="mobileOpen.toString()"
                class="lg:hidden shrink-0 rounded-md p-1.5 text-hp-slate/60 hover:bg-hp-slate/8 hover:text-hp-slate transition-colors"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="3" y1="6"  x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>

            {{-- HealthPass logo — now lives beside the page title, outside the sidebar --}}
            <div class="shrink-0">
                <x-hp.logo size="sm" />
            </div>

            <span class="h-5 w-px shrink-0 bg-hp-slate/15"></span>

            <h1 class="truncate text-sm font-semibold text-hp-slate">{{ $title }}</h1>
        </header>

        {{-- Main content --}}
        <main class="flex-1 overflow-y-auto bg-hp-bg p-4 sm:p-6 lg:p-7">
            {{ $slot }}
        </main>

    </div>

</div>

</body>
</html>
