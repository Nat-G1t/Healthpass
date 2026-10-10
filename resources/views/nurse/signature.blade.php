<x-layout.sidebar title="My Signature">

{{-- ── My Signature (FR-NRS-15, D-134) ──────────────────────────────────────────
     A nurse or physician uploads a PNG of their signature; the official forms
     they sign print it above their name (App\Support\Signature). Everything on
     this page is about the SIGNED-IN user's own signature — the preview image
     comes from nurse.signature.image, which only ever serves the owner's file.

     x-data: Replace opens the file picker
     and Remove asks once before it posts. --}}
<div x-data="{ confirmingRemove: false }">

<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">My Signature</h2>
    <p class="mt-1 text-sm text-hp-slate/60">
        Your signature prints above your name on the forms you sign — the physician's on both
        forms, the nurse's under "Interviewed/Assessed by" on the Medical Assessment Form.
    </p>
</div>

@if (session('status'))
    <div data-hp-flash class="mb-5 rounded-lg border border-hp-orange/30 bg-hp-peach/40 px-4 py-3 text-sm font-medium text-hp-slate">
        {{ session('status') }}
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-2">

    {{-- ── The current signature ────────────────────────────────────────────── --}}
    <x-hp.card class="h-fit">
        <h3 class="hp-card-title">Your signature</h3>

        @if ($hasSignature)
            {{-- The checkerboard shows through a transparent PNG; a signature
                 photographed on paper covers it with a solid box, which is
                 exactly how it would print over the form's lines. --}}
            <div class="mt-4 flex h-44 items-center justify-center rounded-xl border border-hp-slate/15 p-4"
                 style="background: repeating-conic-gradient(rgb(var(--hp-slate) / 0.12) 0% 25%, rgb(var(--hp-white)) 0% 50%) 50% / 16px 16px;"
                 data-signature-preview>
                {{-- ?v= is the upload time, so a replaced signature is never shown from the browser cache. --}}
                <img src="{{ route('nurse.signature.image', ['v' => $uploadedOn->timestamp]) }}"
                     alt="Your uploaded signature" class="max-h-full max-w-full object-contain">
            </div>
            <p class="mt-2 text-[13px] text-hp-slate/60">Uploaded {{ $uploadedOn->format('M j, Y · g:i A') }}</p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-hp.button variant="ghost" size="sm" x-show="! confirmingRemove"
                             @click="$refs.file.click()" data-signature-replace>
                    Replace
                </x-hp.button>

                {{-- Two steps: the first press only asks; Yes posts. --}}
                <form method="POST" action="{{ route('nurse.signature.destroy') }}"
                      class="flex flex-wrap items-center gap-2" data-signature-remove-form>
                    @csrf
                    @method('DELETE')
                    <x-hp.button variant="danger" size="sm" x-show="! confirmingRemove"
                                 @click="confirmingRemove = true">
                        Remove
                    </x-hp.button>
                    <span x-show="confirmingRemove" x-cloak class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-hp-slate">Remove your signature? Forms will print a blank line instead.</span>
                        <x-hp.button type="submit" variant="danger" size="sm" data-pending-label="Removing…"
                                     data-signature-remove-confirm>
                            Yes, remove
                        </x-hp.button>
                        <x-hp.button variant="ghost" size="sm" @click="confirmingRemove = false">Cancel</x-hp.button>
                    </span>
                </form>
            </div>
        @else
            <div class="mt-4 flex h-44 items-center justify-center rounded-xl border-[1.5px] border-dashed border-hp-slate/25 px-6 text-center text-sm text-hp-slate/60"
                 data-signature-empty>
                No signature yet — forms print a blank line for you to sign by hand.
            </div>
        @endif
    </x-hp.card>

    {{-- ── Upload / replace ─────────────────────────────────────────────────── --}}
    <x-hp.card class="h-fit">
        <h3 class="hp-card-title">{{ $hasSignature ? 'Replace your signature' : 'Upload your signature' }}</h3>

        {{-- The guidance, highlighted: a non-transparent signature prints as a box. --}}
        <div class="mt-4 rounded-xl border border-hp-orange/40 bg-hp-peach/40 px-4 py-3 text-[13px] text-hp-slate"
             data-signature-guidance>
            <p class="font-bold text-hp-slate">Use a clear, transparent background.</p>
            <p class="mt-1 text-hp-slate/80">
                A signature on white or grey paper prints as a box over the form.
            </p>
            <ol class="mt-2 list-decimal space-y-0.5 pl-5 text-hp-slate/80">
                <li>Sign on plain white paper.</li>
                <li>Take a clear photo of it.</li>
                <li>
                    Remove the background at
                    <a href="https://www.remove.bg/" target="_blank" rel="noopener noreferrer"
                       class="font-semibold text-hp-orange hover:underline">remove.bg</a>.
                </li>
                <li>Download the result as <b>PNG</b>.</li>
                <li>Upload it here.</li>
            </ol>
        </div>

        {{-- A plain file input, not <x-hp.file-capture>: its Take-photo path
             makes JPEGs, and a photo needs remove.bg first anyway. --}}
        <form method="POST" action="{{ route('nurse.signature.store') }}" enctype="multipart/form-data"
              class="mt-4 space-y-3" data-signature-upload-form>
            @csrf
            <label for="signature" class="block text-[13px] font-semibold text-hp-slate">Signature image (PNG)</label>
            <input id="signature" name="signature" type="file" accept="image/png" required x-ref="file"
                   class="block w-full text-sm text-hp-slate file:mr-3 file:rounded-lg file:border-0 file:bg-hp-peach file:px-4 file:py-2 file:text-sm file:font-semibold file:text-hp-orange hover:file:bg-orange-100">
            <p class="text-[12px] text-hp-slate/50">
                PNG only · up to {{ (int) round(config('healthpass.signatures.max_kb') / 1024) }} MB ·
                at least {{ config('healthpass.signatures.min_width') }}×{{ config('healthpass.signatures.min_height') }} pixels
            </p>
            @error('signature')
                <p class="text-[13px] font-medium text-red-500">{{ $message }}</p>
            @enderror
            <x-hp.button type="submit" variant="primary" size="md" class="w-full" data-pending-label="Uploading…">
                {{ $hasSignature ? 'Replace signature' : 'Upload signature' }}
            </x-hp.button>
        </form>
    </x-hp.card>
</div>

</div>

</x-layout.sidebar>
