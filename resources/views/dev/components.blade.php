<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Component Showcase</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-hp-bg p-8 space-y-12">

<div class="max-w-4xl mx-auto space-y-12">

    {{-- ── Logo ──────────────────────────────────────────────────────────── --}}
    <section>
        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Logo</h2>
        <div class="flex flex-wrap items-end gap-8">
            <x-hp.logo size="sm" />
            <x-hp.logo size="md" />
            <x-hp.logo size="lg" />
        </div>
    </section>

    {{-- ── Buttons ─────────────────────────────────────────────────────────── --}}
    <section>
        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Buttons — variants</h2>
        <div class="flex flex-wrap gap-3 mb-6">
            <x-hp.button variant="primary">Primary</x-hp.button>
            <x-hp.button variant="ghost">Ghost</x-hp.button>
            <x-hp.button variant="soft">Soft</x-hp.button>
            <x-hp.button variant="muted">Muted</x-hp.button>
            <x-hp.button variant="primary" disabled>Disabled</x-hp.button>
        </div>

        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Buttons — sizes</h2>
        <div class="flex flex-wrap items-center gap-3">
            <x-hp.button size="sm">Small</x-hp.button>
            <x-hp.button size="md">Medium</x-hp.button>
            <x-hp.button size="lg">Large</x-hp.button>
            <x-hp.button size="xl">Extra Large</x-hp.button>
        </div>
    </section>

    {{-- ── Badges ──────────────────────────────────────────────────────────── --}}
    <section>
        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Badges</h2>
        <div class="flex flex-wrap gap-2">
            <x-hp.badge variant="positive">Positive</x-hp.badge>
            <x-hp.badge variant="approved">Approved</x-hp.badge>
            <x-hp.badge variant="fit">Fit</x-hp.badge>
            <x-hp.badge variant="cleared">Cleared</x-hp.badge>
            <x-hp.badge variant="flagged">Flagged</x-hp.badge>
            <x-hp.badge variant="live">● Live</x-hp.badge>
            <x-hp.badge variant="neutral">Neutral</x-hp.badge>
            <x-hp.badge variant="pending">Pending</x-hp.badge>
            <x-hp.badge variant="rejected">Rejected</x-hp.badge>
            <x-hp.badge variant="unfit">Unfit</x-hp.badge>
        </div>
    </section>

    {{-- ── Card ────────────────────────────────────────────────────────────── --}}
    <section>
        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Card</h2>
        <x-hp.card class="max-w-sm">
            <p class="text-sm text-hp-slate">This is an <strong>HPCard</strong>. White background, 12px radius, subtle border, 24px padding.</p>
            <div class="mt-4 flex gap-2">
                <x-hp.badge variant="approved">Approved</x-hp.badge>
                <x-hp.badge variant="pending">Pending</x-hp.badge>
            </div>
        </x-hp.card>
    </section>

    {{-- ── Inputs ──────────────────────────────────────────────────────────── --}}
    <section>
        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Inputs</h2>
        <div class="grid grid-cols-1 gap-5 max-w-md">
            <x-hp.input label="Email Address" type="email" placeholder="you@psu.palawan.edu.ph" />
            <x-hp.input label="Password" :password="true" placeholder="••••••••" />
            <x-hp.input label="With Error" type="text" placeholder="Enter text" error="This field is required." />
            <x-hp.input type="text" placeholder="No label, just input" />

            <x-hp.select label="College">
                <option value="">— Select college —</option>
                <option value="CCS">CCS — College of Computing Studies</option>
                <option value="CEA">CEA — College of Architecture and Engineering</option>
                <option value="CBS">CBS — College of Business Studies</option>
            </x-hp.select>

            <x-hp.textarea label="Notes" placeholder="Enter clinical notes here…" />
        </div>
    </section>

    {{-- ── Colour swatches ─────────────────────────────────────────────────── --}}
    <section>
        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/50">Design tokens</h2>
        <div class="flex gap-3">
            @foreach ([
                ['hp-white',  '#FFFFFF', 'White'],
                ['hp-bg',     '#F6F2ED', 'Background'],
                ['hp-peach',  '#FFCAA0', 'Peach'],
                ['hp-orange', '#FF8C2A', 'Orange'],
                ['hp-slate',  '#4B5563', 'Slate'],
            ] as [$cls, $hex, $name])
                <div class="text-center">
                    <div class="h-12 w-12 rounded-lg border border-hp-slate/15" style="background:{{ $hex }}"></div>
                    <p class="mt-1 text-[10px] font-semibold text-hp-slate">{{ $name }}</p>
                    <p class="text-[10px] text-hp-slate/50">{{ $hex }}</p>
                </div>
            @endforeach
        </div>
    </section>

</div>

</body>
</html>
