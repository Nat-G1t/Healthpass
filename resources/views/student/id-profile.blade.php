<x-layout.sidebar title="My ID & Profile">

@php
    // Year level "3" → "3rd Year" for read-only display.
    $ordinal = [
        '1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year',
        '4' => '4th Year', '5' => '5th Year',
    ][$profile->year_level] ?? $profile->year_level;

    $sexLabel  = ['M' => 'Male', 'F' => 'Female'][$profile->sex] ?? $profile->sex;
    $age       = $profile->date_of_birth ? $profile->date_of_birth->age : null;
    $fullName  = trim($profile->first_name.' '.($profile->middle_name ? $profile->middle_name.' ' : '').$profile->last_name);
    $isLinked  = $profile->isQrLinked();

    // Open the Edit modal automatically if a profile-field validation failed
    // (link-id errors use the 'id_number' key and must not trigger this).
    $editFields = ['first_name', 'middle_name', 'last_name', 'email', 'college_id', 'course',
                   'year_level', 'date_of_birth', 'place_of_birth', 'civil_status', 'address'];
    $reopenEdit = $errors->hasAny($editFields);
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">My ID &amp; Profile</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Your kiosk QR code and personal details</p>
</div>

{{-- Success flash (profile updated / ID linked / email confirmed) --}}
@if (session('status'))
    <div class="mb-6 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        {{ session('status') }}
    </div>
@endif

{{-- Failure flash (email change failed — OTP expired / wrong / taken) --}}
@if (session('error'))
    <div class="mb-6 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
        </svg>
        {{ session('error') }}
    </div>
@endif

{{-- Pending email change — verification still outstanding --}}
@if ($pendingEmail)
    <div class="mb-6 flex flex-col gap-3 rounded-lg border border-hp-orange/30 bg-hp-peach/30 px-4 py-3
                sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-2 text-sm text-hp-slate">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/>
            </svg>
            <span>
                Email change pending — confirm the code we sent to
                <strong class="break-all">{{ $pendingEmail }}</strong> to finish.
            </span>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <a href="{{ route('student.id-profile.verify-email') }}"
               class="text-sm font-semibold text-hp-orange hover:underline">Enter code</a>
            <form method="POST" action="{{ route('student.id-profile.verify-email.cancel') }}">
                @csrf
                <button type="submit" class="text-sm text-hp-slate/55 hover:text-hp-slate hover:underline">
                    Cancel
                </button>
            </form>
        </div>
    </div>
@endif

<div x-data="{ editOpen: {{ $reopenEdit ? 'true' : 'false' }} }"
     class="grid grid-cols-1 gap-6 lg:grid-cols-[320px_1fr]">

    {{-- ══ LEFT: Kiosk ID card ════════════════════════════════════════════════ --}}
    <x-hp.card class="h-fit">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-hp-slate">Kiosk ID</h3>
            @if ($isLinked)
                <x-hp.badge variant="positive">● Active</x-hp.badge>
            @else
                <x-hp.badge variant="pending">Not linked</x-hp.badge>
            @endif
        </div>

        @if ($isLinked)
            {{-- ── Linked: show the scannable QR ──────────────────────────────── --}}
            <div class="flex flex-col items-center text-center">
                {{-- Cap the QR box and let the SVG scale to it, so it stays a
                     sensible size on phones instead of filling the whole card. --}}
                <div class="w-full max-w-[200px] rounded-xl border border-hp-slate/15 bg-white p-4
                            [&>svg]:h-auto [&>svg]:w-full">
                    {!! $qrSvg !!}
                </div>
                <p class="mt-4 text-sm font-semibold text-hp-slate">{{ $fullName }}</p>
                <p class="font-mono text-xs text-hp-slate/45">{{ $profile->student_number }}</p>
                <p class="mt-3 text-xs leading-relaxed text-hp-slate/50">
                    Show this code at the clinic kiosk to start your vitals check-in.
                </p>
            </div>
        @else
            {{-- ── Not linked: in-browser capture flow (same as Step 4) ───────── --}}
            {{--
                html5-qrcode's scanFile() needs a DOM anchor even when no image
                is shown. This hidden div is that anchor — never displays anything.
            --}}
            <div id="qr-file-reader" class="hidden" aria-hidden="true"></div>

            <div x-data="qrLinkId('{{ $studentNumberDigits }}')" x-cloak>
                <p class="mb-4 text-xs leading-relaxed text-hp-slate/55">
                    You skipped linking your student ID at registration. Scan it now to
                    enable kiosk check-in.
                </p>

                {{-- Server-side error (back() after a failed link POST) --}}
                @error('id_number')
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ $message }}
                    </div>
                @enderror

                {{-- Client-side error (mismatch / unreadable QR) --}}
                <div x-show="mode === 'error'"
                     class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    <p class="mb-1 font-medium" x-text="errorMsg"></p>
                    <button type="button" @click="reset()"
                            class="font-semibold underline underline-offset-2 hover:text-red-900">
                        ← Try again
                    </button>
                </div>

                {{-- Idle: choose scan method --}}
                <div x-show="mode === 'idle'" class="grid grid-cols-2 gap-3">
                    <button type="button" @click="startCamera()"
                            class="flex flex-col items-center gap-2 rounded-xl border-2 border-hp-peach
                                   bg-white px-3 py-4 text-xs font-semibold text-hp-slate transition-colors
                                   hover:border-hp-orange hover:bg-hp-peach/20
                                   focus:outline-none focus:ring-2 focus:ring-hp-orange/40">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" class="text-hp-orange">
                            <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/>
                            <circle cx="12" cy="13" r="3"/>
                        </svg>
                        Use Camera
                    </button>

                    <button type="button" @click="$refs.fileInput.click()"
                            class="flex flex-col items-center gap-2 rounded-xl border-2 border-hp-peach
                                   bg-white px-3 py-4 text-xs font-semibold text-hp-slate transition-colors
                                   hover:border-hp-orange hover:bg-hp-peach/20
                                   focus:outline-none focus:ring-2 focus:ring-hp-orange/40">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" class="text-hp-orange">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <circle cx="8.5" cy="8.5" r="1.5"/>
                            <polyline points="21 15 16 10 5 21"/>
                        </svg>
                        Upload ID Photo
                    </button>
                </div>

                {{-- Hidden file input — triggered by "Upload ID Photo" --}}
                <input type="file" accept="image/*" class="hidden"
                       x-ref="fileInput" @change="scanFile($event)">

                {{-- Camera scanning — html5-qrcode renders into #qr-reader --}}
                <div x-show="mode === 'scanning'">
                    <div id="qr-reader" class="mb-3 overflow-hidden rounded-xl"></div>
                    <button type="button" @click="stopCamera()"
                            class="w-full rounded-lg border border-hp-slate/25 py-2 text-sm text-hp-slate
                                   transition-colors hover:bg-hp-slate/5
                                   focus:outline-none focus:ring-2 focus:ring-hp-slate/20">
                        Cancel
                    </button>
                </div>

                {{-- Matched: confirm and POST to the student link route --}}
                <div x-show="mode === 'matched'">
                    <div class="mb-3 flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-3 py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                             fill="none" stroke="#16a34a" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round" class="shrink-0">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="9 12 11 14 15 10"/>
                        </svg>
                        <div>
                            <p class="text-xs font-semibold text-green-800">QR code matched</p>
                            <p class="text-[11px] text-green-700">IDNo: <span x-text="matchedId"></span></p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('student.id-profile.link-id') }}">
                        @csrf
                        <input type="hidden" name="id_number" :value="matchedId">
                        <button type="submit"
                                class="w-full rounded-lg bg-hp-orange py-2.5 text-sm font-semibold text-white
                                       transition-opacity hover:bg-hp-orange/90
                                       focus:outline-none focus:ring-2 focus:ring-hp-orange/50">
                            Link ID
                        </button>
                    </form>

                    <div class="mt-2 text-center">
                        <button type="button" @click="reset()"
                                class="text-xs text-hp-slate/60 hover:underline">
                            ← Try a different ID
                        </button>
                    </div>
                </div>

                {{-- Dev panel — local only (paste IDNo without a camera) --}}
                @if (app()->isLocal())
                    <div class="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2">
                        <p class="mb-2 text-[11px] font-semibold text-slate-500">Dev: paste IDNo (no camera)</p>
                        <div class="flex gap-2">
                            <input type="text" x-model="devInput"
                                   placeholder="e.g. {{ $profile->student_number }}"
                                   @keydown.enter.prevent="submitDev()"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-hp-slate
                                          focus:border-hp-orange focus:outline-none focus:ring-2 focus:ring-hp-orange/30">
                            <button type="button" @click="submitDev()"
                                    class="rounded-lg bg-slate-600 px-3 py-1.5 text-xs font-semibold text-white
                                           transition-colors hover:bg-slate-700
                                           focus:outline-none focus:ring-2 focus:ring-slate-400/40">
                                Submit
                            </button>
                        </div>
                    </div>
                @endif

                <p class="mt-4 border-t border-hp-slate/10 pt-3 text-[11px] leading-relaxed text-hp-slate/45">
                    You can keep using HealthPass without linking — you'll just log in at the
                    kiosk by email instead. Skip again and link any time from this page.
                </p>
            </div>
        @endif
    </x-hp.card>

    {{-- ══ RIGHT: Profile details ═════════════════════════════════════════════ --}}
    <x-hp.card class="h-fit">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-hp-slate">Profile</h3>
            <x-hp.button variant="soft" size="sm" @click="editOpen = true">
                Edit Profile
            </x-hp.button>
        </div>

        <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Full Name</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $fullName }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Email</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate break-all">{{ $profile->user->email }}</dd>
            </div>

            {{-- Locked fields — display only --}}
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    Student Number <span class="font-normal normal-case text-hp-slate/30">· locked</span>
                </dt>
                <dd class="mt-1 font-mono text-sm font-medium text-hp-slate">{{ $profile->student_number }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">College</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $profile->college->name }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    Sex <span class="font-normal normal-case text-hp-slate/30">· locked</span>
                </dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $sexLabel }}</dd>
            </div>

            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Course &amp; Year</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $profile->course }} · {{ $ordinal }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Date of Birth</dt>
                <dd class="mt-1 flex items-center gap-2 text-sm font-medium text-hp-slate">
                    {{ $profile->date_of_birth?->format('F j, Y') }}
                    @if (! is_null($age))
                        <span class="rounded-full bg-hp-peach px-2 py-0.5 text-[11px] font-semibold text-hp-orange">
                            {{ $age }} yrs
                        </span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Place of Birth</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $profile->place_of_birth }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Civil Status</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $profile->civil_status }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Address</dt>
                <dd class="mt-1 text-sm font-medium text-hp-slate">{{ $profile->address }}</dd>
            </div>
        </dl>
    </x-hp.card>

    {{-- ══ Edit Profile modal ═════════════════════════════════════════════════ --}}
    <div x-show="editOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background-color: rgba(75,85,99,0.45);">

        <div @click.outside="editOpen = false"
             class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-xl">

            {{-- Modal header --}}
            <div class="sticky top-0 flex items-start justify-between gap-4 rounded-t-2xl
                        border-b border-hp-slate/10 bg-white px-6 py-4">
                <div>
                    <h3 class="text-sm font-semibold text-hp-slate">Edit Profile</h3>
                    <p class="mt-0.5 text-xs text-hp-slate/50">
                        Student number and sex can't be changed here.
                    </p>
                </div>
                <button type="button" @click="editOpen = false"
                        class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full
                               text-hp-slate/40 transition-colors hover:bg-hp-slate/10 hover:text-hp-slate
                               focus:outline-none">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('student.id-profile.update') }}" class="px-6 py-5">
                @csrf
                @method('PATCH')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-hp.input label="First Name" name="first_name"
                                :value="old('first_name', $profile->first_name)"
                                :error="$errors->first('first_name')" required />
                    <x-hp.input label="Middle Name" name="middle_name"
                                :value="old('middle_name', $profile->middle_name)"
                                :error="$errors->first('middle_name')" />
                    <x-hp.input label="Last Name" name="last_name"
                                :value="old('last_name', $profile->last_name)"
                                :error="$errors->first('last_name')" required />
                    <x-hp.input label="Email" name="email" type="email"
                                :value="old('email', $profile->user->email)"
                                :error="$errors->first('email')" required />
                    <x-hp.input label="Course" name="course"
                                :value="old('course', $profile->course)"
                                :error="$errors->first('course')" required />

                    {{-- College — editable on transfer (FR-STU-09). Past visits keep
                         their capture-time snapshot, so analytics history stays put. --}}
                    <x-hp.select label="College" name="college_id" :error="$errors->first('college_id')">
                        @foreach ($colleges as $college)
                            <option value="{{ $college->id }}"
                                @selected((int) old('college_id', $profile->college_id) === $college->id)>
                                {{ $college->code }} — {{ $college->name }}
                            </option>
                        @endforeach
                    </x-hp.select>

                    <x-hp.select label="Year Level" name="year_level" :error="$errors->first('year_level')">
                        @foreach (['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year', '5' => '5th Year'] as $val => $text)
                            <option value="{{ $val }}" @selected(old('year_level', $profile->year_level) === $val)>{{ $text }}</option>
                        @endforeach
                    </x-hp.select>

                    <x-hp.input label="Date of Birth" name="date_of_birth" type="date"
                                :value="old('date_of_birth', $profile->date_of_birth?->format('Y-m-d'))"
                                :error="$errors->first('date_of_birth')" required />
                    <x-hp.input label="Place of Birth" name="place_of_birth"
                                :value="old('place_of_birth', $profile->place_of_birth)"
                                :error="$errors->first('place_of_birth')" required />

                    <x-hp.select label="Civil Status" name="civil_status" :error="$errors->first('civil_status')">
                        @foreach (['Single', 'Married', 'Widowed', 'Separated'] as $opt)
                            <option value="{{ $opt }}" @selected(old('civil_status', $profile->civil_status) === $opt)>{{ $opt }}</option>
                        @endforeach
                    </x-hp.select>

                    <div class="sm:col-span-2">
                        <x-hp.textarea label="Address" name="address" rows="2"
                                       :error="$errors->first('address')" required>{{ old('address', $profile->address) }}</x-hp.textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-hp.button type="button" variant="ghost" size="md" @click="editOpen = false">
                        Cancel
                    </x-hp.button>
                    <x-hp.button type="submit" variant="primary" size="md">
                        Save Changes
                    </x-hp.button>
                </div>
            </form>
        </div>
    </div>

</div>{{-- /x-data --}}

</x-layout.sidebar>
