<x-layout.sidebar title="Kiosk Devices">

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Kiosk Devices</h2>
    <p class="mt-1 text-sm text-hp-slate/60">
        Enroll a clinic terminal so it can open the kiosk without anyone signing in
        at the screen. Revoke a device to cut off its access immediately.
    </p>
</div>

{{-- Success / status flash (enroll, revoke). --}}
@if (session('status'))
    <div data-hp-flash class="mb-5 rounded-lg border border-hp-orange/30 bg-hp-peach/40 px-4 py-3 text-sm font-medium text-hp-slate">
        {{ session('status') }}
    </div>
@endif

{{-- ── One-time token reveal ────────────────────────────────────────────────────
     Shown ONLY on the request right after enrolling. This browser already has the
     cookie; the token below is for provisioning the Pi's incognito launcher, which
     can't keep a cookie — paste the URL into its KIOSK_URL. Shown once, like a
     personal access token. --}}
@if (session('new_device_token'))
    @php
        $token = session('new_device_token');
        $provisionUrl = route('kiosk.index').'?device_token='.$token;
    @endphp
    <x-hp.card class="mb-6 border-hp-orange/40 bg-white"
               x-data="{ copied: false }">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 shrink-0 text-hp-orange">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 9v2m0 4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"/>
                </svg>
            </div>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-hp-slate">Copy this now — it won't be shown again</h3>
                <p class="mt-1 text-[13px] text-hp-slate/70">
                    This browser is already enrolled (no copying needed here). Use the URL
                    below only to provision a Pi launcher that runs Chromium in incognito
                    (see <code class="rounded bg-hp-slate/10 px-1">docs/deployment-pi.md</code>).
                </p>

                <label class="mt-3 block text-[13px] font-semibold text-hp-slate">Provisioning URL (KIOSK_URL)</label>
                <div class="mt-1 flex items-center gap-2">
                    <input type="text" readonly
                           x-ref="url"
                           value="{{ $provisionUrl }}"
                           class="w-full rounded-lg border-[1.5px] border-hp-slate/25 bg-hp-slate/5 px-3 py-2 font-mono text-xs text-hp-slate">
                    <x-hp.button variant="ghost" size="sm"
                                 x-on:click="navigator.clipboard.writeText($refs.url.value); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </x-hp.button>
                </div>
            </div>
        </div>
    </x-hp.card>
@endif

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ── Enroll this browser ──────────────────────────────────────────────── --}}
    <x-hp.card class="lg:col-span-1 h-fit">
        <h3 class="text-sm font-semibold text-hp-slate">Enable Kiosk Mode</h3>
        <p class="mt-1 text-[13px] text-hp-slate/60">
            Enrolls the browser you're using right now. Do this on the actual clinic
            terminal.
        </p>

        <form method="POST" action="{{ route('nurse.kiosk-devices.store') }}" class="mt-4 space-y-4">
            @csrf
            <x-hp.input label="Device name" name="name" type="text"
                        placeholder="e.g. Clinic Pi (front desk)" maxlength="80" required
                        :error="$errors->first('name')" />
            <x-hp.button type="submit" variant="primary" size="md" class="w-full" data-pending-label="Enrolling…">
                Enable Kiosk Mode on this device
            </x-hp.button>
        </form>

        <a href="{{ route('kiosk.index') }}" target="_blank" rel="noopener"
           class="mt-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-hp-orange hover:underline">
            Open the kiosk
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <path d="M15 3h6v6M10 14L21 3"/>
            </svg>
        </a>
    </x-hp.card>

    {{-- ── Enrolled devices ─────────────────────────────────────────────────── --}}
    <x-hp.card class="lg:col-span-2">
        <h3 class="mb-4 text-sm font-semibold text-hp-slate">Enrolled devices</h3>

        @if ($devices->isEmpty())
            <p class="py-8 text-center text-sm text-hp-slate/50">
                No devices enrolled yet. Enable Kiosk Mode on a terminal to add one.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-[12px] font-semibold uppercase tracking-wide text-hp-slate/50">
                            <th class="pb-2 pr-4">Device</th>
                            <th class="pb-2 pr-4">Enrolled by</th>
                            <th class="pb-2 pr-4">Status</th>
                            <th class="pb-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-hp-slate">
                        @foreach ($devices as $device)
                            <tr class="border-t border-hp-slate/10">
                                <td class="py-3 pr-4">
                                    <div class="font-medium">{{ $device->name }}</div>
                                    <div class="text-[12px] text-hp-slate/50">
                                        {{ $device->created_at?->format('M j, Y') }}
                                    </div>
                                </td>
                                <td class="py-3 pr-4 text-hp-slate/70">
                                    {{ $device->creator?->name ?? '—' }}
                                </td>
                                <td class="py-3 pr-4">
                                    @if ($device->isRevoked())
                                        <x-hp.badge variant="rejected">Revoked</x-hp.badge>
                                    @else
                                        <x-hp.badge variant="approved">Active</x-hp.badge>
                                    @endif
                                </td>
                                <td class="py-3 text-right">
                                    @unless ($device->isRevoked())
                                        {{-- confirm() text is a static string: interpolating $device->name into
                                             the JS literal breaks on an apostrophe (e.g. "Nurse's Pi") and would
                                             silently skip the guard. The device name is already visible in the row. --}}
                                        <form method="POST"
                                              action="{{ route('nurse.kiosk-devices.destroy', $device) }}"
                                              onsubmit="return confirm('Revoke this device? That terminal will lose kiosk access immediately.')">
                                            @csrf
                                            @method('DELETE')
                                            <x-hp.button type="submit" variant="danger" size="sm" data-pending-label="Revoking…">Revoke</x-hp.button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-hp.card>
</div>

</x-layout.sidebar>
