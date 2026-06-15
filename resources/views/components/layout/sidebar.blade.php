@props(['title' => ''])

@php
    use Illuminate\Support\Facades\Auth;

    $user = Auth::user();

    // Nav items per role: [label, route-name, icon-path]
    $navItems = match ($user?->role) {
        'student' => [
            ['Dashboard',        'student.dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            ['Book Appointment', 'student.dashboard', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['My Records',       'student.dashboard', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['My ID',            'student.dashboard', 'M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2'],
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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">

<div class="flex h-full">

    {{-- ── Sidebar ──────────────────────────────────────────────────────── --}}
    <aside class="fixed inset-y-0 left-0 z-30 flex w-[220px] flex-col bg-white border-r border-hp-slate/10">

        {{-- Logo --}}
        <div class="flex h-14 shrink-0 items-center px-5 border-b border-hp-slate/10">
            <x-hp.logo size="md" />
        </div>

        {{-- Nav --}}
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5">
            @foreach ($navItems as [$label, $routeName, $iconPath])
                @php
                    $isActive = request()->routeIs($routeName);
                @endphp
                <a
                    href="{{ route($routeName) }}"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors duration-100
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
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        {{-- User footer --}}
        <div class="shrink-0 border-t border-hp-slate/10 p-3">
            <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                {{-- Initials circle --}}
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-hp-peach text-xs font-bold text-hp-orange">
                    {{ $initials }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-hp-slate leading-tight">{{ $user?->name }}</p>
                    <p class="text-[11px] text-hp-slate/50 leading-tight">{{ $roleLabel }}</p>
                </div>
                {{-- Logout --}}
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Log out"
                            class="text-hp-slate/40 hover:text-hp-slate transition-colors">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ── Right column ─────────────────────────────────────────────────── --}}
    <div class="flex flex-1 flex-col pl-[220px] min-h-full">

        {{-- Header bar --}}
        <header class="sticky top-0 z-20 flex h-14 items-center bg-white border-b border-hp-slate/10 px-6">
            <h1 class="text-sm font-semibold text-hp-slate">{{ $title }}</h1>
        </header>

        {{-- Main content --}}
        <main class="flex-1 overflow-y-auto bg-hp-bg p-7">
            {{ $slot }}
        </main>

    </div>

</div>

</body>
</html>
