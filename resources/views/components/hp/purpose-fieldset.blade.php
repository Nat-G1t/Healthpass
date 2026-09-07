@props([
    // First (empty) option label. Encode leaves purpose optional; student
    // booking requires it, so the copy differs — the server is the real gate.
    'placeholderOption' => '— Optional —',
    // Disable both controls (encode read-only mode).
    'disabled' => false,
    // Specify-field hint.
    'specifyPlaceholder' => 'Specify the event, e.g. Regional quiz bee at PSU Lubao',
])

{{--
    Shared "Purpose of Medical Clearance" fieldset — the four locked
    ClearanceRecord::PURPOSES plus the official form's "Others, Specify" line
    with a free-text field. Used by BOTH the nurse encode screen and the
    student booking page (D-24/D-25 dropdown, D-28 carried to booking).

    CONTRACT: the ENCLOSING Alpine scope must expose two reactive properties —
      • `purpose`      (bound to the select via x-model)
      • `purposeOther` (bound to the specify input via x-model)
    Both call sites seed these from old()/saved values in their own x-data, so
    x-model — not @selected/value= — drives the initial state here. The specify
    input only shows (and only submits meaningfully) when Others is picked; each
    call site's server validation drops stray specify text otherwise.
--}}
<div class="space-y-2">
    <x-hp.select label="Purpose" name="purpose" x-model="purpose" :disabled="$disabled">
        <option value="">{{ $placeholderOption }}</option>
        @foreach (\App\Models\ClearanceRecord::PURPOSES as $purposeOption)
            <option value="{{ $purposeOption }}">{{ $purposeOption }}</option>
        @endforeach
        <option value="{{ \App\Models\ClearanceRecord::PURPOSE_OTHERS }}">
            Others, Specify…
        </option>
    </x-hp.select>

    <div x-show="purpose === @js(\App\Models\ClearanceRecord::PURPOSE_OTHERS)" x-cloak>
        <x-hp.input name="purpose_other" x-model="purposeOther" maxlength="120"
                    :disabled="$disabled" placeholder="{{ $specifyPlaceholder }}" />
        @error('purpose_other')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @error('purpose')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
