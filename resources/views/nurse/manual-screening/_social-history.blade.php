{{-- ── Personal / Social History (D-68) — Medical Assessment only ──────────────
     Section I of the Medical Assessment Form's back page, as the kiosk asks
     it: Smoking, Alcohol and Illicit Drugs as Yes / No / Quit, Sexually Active
     as Yes / No. A Medical Clearance never renders this card, and the server
     drops the block for one anyway.

     Posts as socialHistory[smoking|alcohol|illicitDrugs|sexuallyActive] — the
     kiosk's own keys. Variables from the parent view: none.
──────────────────────────────────────────────────────────────────────────── --}}
@php
    // Each row: posted key => [label, [answer label => value]]. The habit rows
    // are the model's columns in camelCase, the kiosk's key for each.
    $rows = collect(\App\Models\ScreeningResponse::SOCIAL_HISTORY)
        ->mapWithKeys(fn (string $label, string $column) => [
            \Illuminate\Support\Str::camel($column) => [$label, ['Yes' => 'yes', 'No' => 'no', 'Quit' => 'quit']],
        ])
        ->put('sexuallyActive', [\App\Models\ScreeningResponse::SEXUALLY_ACTIVE_LABEL, ['Yes' => '1', 'No' => '0']]);
@endphp

<x-hp.card>
    <h3 class="text-sm font-bold text-hp-slate">Personal / Social History</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">Section I of the Medical Assessment Form. The student’s answers are seen only by the clinic.</p>

    @error('socialHistory')
        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
    @enderror

    <div class="mt-3 grid gap-x-8 sm:grid-cols-2">
        @foreach ($rows as $key => [$label, $answers])
            <div class="border-b border-hp-slate/10 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-hp-slate/80">{{ $label }}</span>
                    <div class="flex shrink-0 items-center gap-4">
                        @foreach ($answers as $answerLabel => $value)
                            <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm text-hp-slate/70">
                                <input type="radio" name="socialHistory[{{ $key }}]" value="{{ $value }}"
                                       class="border-hp-slate/40 text-hp-orange focus:ring-hp-orange" required
                                       @checked(old("socialHistory.{$key}") === $value)>
                                {{ $answerLabel }}
                            </label>
                        @endforeach
                    </div>
                </div>
                @error("socialHistory.{$key}")
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>
</x-hp.card>
