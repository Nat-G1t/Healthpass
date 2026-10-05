@props([
    'name',              {{-- the form field, e.g. "memo" --}}
    'noun' => 'file',    {{-- how messages name it: "The memo must be …" --}}
])

{{--
    One file, attached by photo or upload (D-109) — the New Batch signed memo.
    Two buttons:
      • Take photo  — capture="environment": the camera on phones and tablets,
                      a file picker on laptops (Q8: no live webcam window)
      • Upload file — a JPG, PNG or PDF from the device
    Then a preview (thumbnail for an image, a tile for a PDF), the file's name
    and size, and Remove. The Alpine logic is resources/js/shared/file-capture.js.

    Types and size come from config('healthpass.uploads'). The quick check in
    the browser only saves a wasted upload — the SERVER still decides, by
    reading the file's real content.

    A browser never refills a file input, so when the page comes back with an
    error elsewhere the file has to be chosen again — the note below says so.
--}}

@php
    $mimes = config('healthpass.uploads.mimes');
    $maxKb = config('healthpass.uploads.max_kb');
    $maxMb = (int) round($maxKb / 1024);
    // "JPG, PNG or PDF" — jpeg is the same type as jpg.
    $typeList = collect($mimes)->map(fn (string $ext): string => strtoupper($ext === 'jpeg' ? 'jpg' : $ext))->unique()->join(', ', ' or ');
    // The MIME type the browser reports for each extension (image/jpeg …).
    $mimeTypes = collect($mimes)
        ->map(fn (string $ext): ?string => Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? null)
        ->filter()->unique()->values()->all();
@endphp

<div x-data="fileCapture({{ Illuminate\Support\Js::from([
        'name' => $name,
        'types' => $mimeTypes,
        'maxKb' => $maxKb,
        'messages' => [
            'type' => "The {$noun} must be a {$typeList}.",
            'size' => "The {$noun} must be {$maxMb} MB or smaller.",
        ],
    ]) }})" data-file-capture="{{ $name }}">

    {{-- Only the input holding the chosen file carries the field's name, so
         exactly one file is posted. Hidden: the buttons open them. --}}
    <input type="file" x-ref="camera" class="hidden" data-capture-input
           accept="image/*" capture="environment"
           :name="source === 'camera' ? name : null" @change="pick($event, 'camera')">
    <input type="file" x-ref="upload" class="hidden" data-upload-input
           accept=".{{ implode(',.', $mimes) }}"
           :name="source === 'upload' ? name : null" @change="pick($event, 'upload')">

    <div class="flex flex-wrap gap-2">
        <x-hp.button variant="soft" size="sm" class="min-w-[8rem] flex-1" @click="$refs.camera.click()">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Take photo
        </x-hp.button>
        <x-hp.button variant="ghost" size="sm" class="min-w-[8rem] flex-1" @click="$refs.upload.click()">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Upload file
        </x-hp.button>
    </div>
    <p class="mt-2 text-xs text-hp-slate/50">{{ $typeList }}, up to {{ $maxMb }} MB. One file — choosing again replaces it.</p>

    {{-- The chosen file --}}
    <div x-show="file !== null" x-cloak class="mt-3 rounded-lg border border-hp-slate/15 p-3" data-file-preview>
        <template x-if="isImage">
            <img :src="previewUrl" alt="Preview of the chosen file" class="mx-auto mb-3 max-h-48 w-auto rounded object-contain">
        </template>
        <div class="flex items-center gap-3">
            <svg x-show="!isImage" class="h-8 w-8 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-hp-slate" x-text="file?.name"></p>
                <p class="text-xs text-hp-slate/50" x-text="sizeLabel"></p>
            </div>
            <button type="button" @click="remove()"
                    class="shrink-0 text-xs font-semibold text-red-500 transition-colors hover:text-red-600 hover:underline">
                Remove
            </button>
        </div>
    </div>

    {{-- The browser's quick check --}}
    <p x-show="error !== ''" x-cloak role="alert" class="mt-2 text-xs text-red-600" x-text="error"></p>

    {{-- After a failed submit: the server's own message for this file, or —
         when only other fields failed — a reminder to choose it again. Hidden
         once a new file is picked. --}}
    @if ($errors->has($name))
        <p x-show="file === null && error === ''" class="mt-2 text-xs text-red-600">{{ $errors->first($name) }}</p>
    @elseif ($errors->any())
        <p x-show="file === null && error === ''" class="mt-2 text-xs text-red-600">Please attach the {{ $noun }} again.</p>
    @endif
</div>
