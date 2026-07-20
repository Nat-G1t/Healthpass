<x-register.wizard-shell :step="4">

    {{--
        html5-qrcode's scanFile() needs a DOM element even when showImage=false.
        This hidden div is that anchor — it never displays anything visible.
    --}}
    <div id="qr-file-reader" class="hidden" aria-hidden="true"></div>

    <div
        x-data="qrLinkId('{{ $studentNumberDigits }}')"
        x-cloak
    >
        <h2 class="mb-[6px] text-center text-[15px] font-bold text-hp-slate">Step 4 — Link Your Student ID</h2>
        <p class="mb-[18px] text-center text-[13px] leading-[1.6] text-hp-slate/[55%]">
            Scan your physical student ID's QR code to bind it to your account.
            You can skip this now and link later from <strong>My ID &amp; Profile</strong>.
        </p>

        {{-- Server-side error (back() after failed POST) --}}
        @error('id_number')
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $message }}
            </div>
        @enderror

        {{-- Client-side error state (mismatch or unreadable QR) --}}
        <div
            x-show="mode === 'error'"
            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <p class="mb-2 font-medium" x-text="errorMsg"></p>
            <button type="button" @click="reset()"
                    class="font-semibold underline underline-offset-2 hover:text-red-900">
                ← Try again
            </button>
        </div>

        {{-- ── Idle: choose scan method ────────────────────────────────────── --}}
        <div x-show="mode === 'idle'" class="mb-6 grid grid-cols-2 gap-3">

            <button
                type="button"
                @click="startCamera()"
                class="flex flex-col items-center gap-3 rounded-xl border-2 border-hp-peach
                       bg-white px-4 py-5 text-sm font-semibold text-hp-slate transition-colors
                       hover:border-hp-orange hover:bg-hp-peach/20
                       focus:outline-none focus:ring-2 focus:ring-hp-orange/40"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" class="text-hp-orange">
                    <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/>
                    <circle cx="12" cy="13" r="3"/>
                </svg>
                Use Camera
            </button>

            <button
                type="button"
                @click="$refs.fileInput.click()"
                class="flex flex-col items-center gap-3 rounded-xl border-2 border-hp-peach
                       bg-white px-4 py-5 text-sm font-semibold text-hp-slate transition-colors
                       hover:border-hp-orange hover:bg-hp-peach/20
                       focus:outline-none focus:ring-2 focus:ring-hp-orange/40"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" class="text-hp-orange">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                Upload ID Photo
            </button>

        </div>

        {{-- Hidden file input — triggered by the "Upload ID Photo" button --}}
        <input
            type="file"
            accept="image/*"
            class="hidden"
            x-ref="fileInput"
            @change="scanFile($event)"
        >

        {{-- ── Camera scanning — html5-qrcode renders into #qr-reader ──────── --}}
        <div x-show="mode === 'scanning'" class="mb-4">
            {{-- html5-qrcode injects its own UI (video feed, start/stop, etc.) here --}}
            <div id="qr-reader" class="mb-3 overflow-hidden rounded-xl"></div>
            <button
                type="button"
                @click="stopCamera()"
                class="w-full rounded-lg border border-hp-slate/25 py-2 text-sm text-hp-slate
                       hover:bg-hp-slate/5 transition-colors
                       focus:outline-none focus:ring-2 focus:ring-hp-slate/20"
            >
                Cancel
            </button>
        </div>

        {{-- ── Matched: confirm and POST ───────────────────────────────────── --}}
        <div x-show="mode === 'matched'" class="mb-6">
            <div class="mb-4 flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                     fill="none" stroke="#16a34a" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round" class="shrink-0">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="9 12 11 14 15 10"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold text-green-800">QR code matched</p>
                    <p class="text-xs text-green-700">IDNo: <span x-text="matchedId"></span></p>
                </div>
            </div>

            <form method="POST" action="{{ route('register.link-id.store') }}">
                @csrf
                {{-- The hidden field carries the extracted IDNo to the server --}}
                <input type="hidden" name="id_number" :value="matchedId">
                <button
                    type="submit"
                    data-pending-label="Linking…"
                    class="w-full rounded-lg bg-hp-orange py-2.5 text-sm font-semibold text-white
                           hover:bg-hp-orange/90 transition-opacity
                           focus:outline-none focus:ring-2 focus:ring-hp-orange/50"
                >
                    Link ID &amp; Continue →
                </button>
            </form>

            <div class="mt-2 text-center">
                <button type="button" @click="reset()"
                        class="text-sm text-hp-slate/60 hover:underline">
                    ← Try a different ID
                </button>
            </div>
        </div>

        {{-- ── Dev panel — visible only when APP_ENV=local ────────────────── --}}
        @if (app()->isLocal())
            <div class="mb-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3">
                <p class="mb-2 text-xs font-semibold text-slate-500">Dev: paste IDNo (no camera needed)</p>
                <div class="flex gap-2">
                    <input
                        type="text"
                        x-model="devInput"
                        placeholder="e.g. 2023-12345 or 202312345"
                        @keydown.enter.prevent="submitDev()"
                        class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-hp-slate
                               focus:outline-none focus:border-hp-orange focus:ring-2 focus:ring-hp-orange/30"
                    >
                    <button
                        type="button"
                        @click="submitDev()"
                        class="shrink-0 rounded-lg bg-slate-600 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-slate-700 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-400/40"
                    >
                        Submit
                    </button>
                </div>
            </div>
        @endif

        {{-- ── Skip for now ─────────────────────────────────────────────────── --}}
        <form method="POST" action="{{ route('register.link-id.skip') }}">
            @csrf
            <x-hp.button type="submit" variant="muted" size="lg" class="w-full" data-pending-label="Please wait…">
                Skip for now — link it later from My ID &amp; Profile
            </x-hp.button>
        </form>

        <p class="mt-3 text-center text-xs text-hp-slate/50">
            Your account is ready. You can link your ID at any time.
        </p>
    </div>

</x-register.wizard-shell>
