<x-register.wizard-shell :step="2">

    <h2 class="mb-[18px] text-[15px] font-bold text-hp-slate">Step 2 — Personal Information</h2>

    {{-- Client-side gate: keep "Continue" disabled until every required field is
         filled, so an incomplete submit can't bounce the whole page back. The
         password confirmation is intentionally excluded — the form never inspects
         it as you type, so there's no impression the site is scanning your
         password. The server (StoreRegistrationInfoRequest) still validates
         everything, including the confirmation, on submit. --}}
    <form
        method="POST"
        action="{{ route('register.info.store') }}"
        novalidate
        x-data="{
            ready: false,
            // The whole program catalog, keyed by college id (D-42). `catalog`
            // never changes; `collegeId` does, and the two getters below re-read
            // the catalog whenever it does — that is the entire cascade.
            catalog: @js($programCatalog),
            collegeId: '{{ old('college_id') }}',
            course: '{{ old('course') }}',
            yearLevel: '{{ old('year_level') }}',
            get programs() { return this.catalog[this.collegeId]?.programs ?? []; },
            get yearLevels() { return this.catalog[this.collegeId]?.year_levels ?? {}; },
            validate() {
                for (const el of this.$el.querySelectorAll('[required]')) {
                    if (el.name === 'password_confirmation') continue; // never inspect the confirm field
                    if (! el.value.trim()) { this.ready = false; return; }
                }
                // Sex is required but rendered as a radio group (no `required` attribute).
                if (! this.$el.querySelector('input[name=sex]:checked')) { this.ready = false; return; }
                this.ready = true;
            },
        }"
        {{-- Switching college clears the program and year level: the old choice
             belongs to the old college and would fail server validation. --}}
        x-init="validate(); $watch('collegeId', () => { course = ''; yearLevel = ''; }); $nextTick(() => validate())"
        @input="validate()"
        @change="validate()"
    >
        @csrf

        {{-- ── Personal Details ────────────────────────────────────────────── --}}
        <p class="mb-3 text-xs font-bold uppercase tracking-widest text-hp-slate/40">
            Personal Details
        </p>

        {{-- First Name / Middle Name / Last Name --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-hp.input
                label="First Name"
                id="first_name"
                name="first_name"
                type="text"
                :value="old('first_name')"
                placeholder="e.g. Juan"
                required
                autocomplete="given-name"
                :error="$errors->first('first_name')"
            />
            <x-hp.input
                label="Middle Name (optional)"
                id="middle_name"
                name="middle_name"
                type="text"
                :value="old('middle_name')"
                placeholder="e.g. Santos"
                autocomplete="additional-name"
                :error="$errors->first('middle_name')"
            />
            <x-hp.input
                label="Last Name"
                id="last_name"
                name="last_name"
                type="text"
                :value="old('last_name')"
                placeholder="e.g. Dela Cruz"
                required
                autocomplete="family-name"
                :error="$errors->first('last_name')"
            />
        </div>

        {{-- Student Number / College --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-hp.input
                label="Student Number"
                id="student_number"
                name="student_number"
                type="text"
                :value="old('student_number')"
                placeholder="e.g. 2022300123"
                required
                autocomplete="off"
                :error="$errors->first('student_number')"
            />
            <x-hp.select
                label="College"
                id="college_id"
                name="college_id"
                required
                x-model="collegeId"
                :error="$errors->first('college_id')"
                {{-- Half width beside Student Number: ellipsis, not a hard clip,
                     on long names (same fix as Program below). --}}
                class="truncate"
            >
                <option value="">— Select your college —</option>
                @foreach ($colleges as $college)
                    <option
                        value="{{ $college->id }}"
                        {{ old('college_id') == $college->id ? 'selected' : '' }}
                    >
                        {{ $college->code }} — {{ $college->name }}
                    </option>
                @endforeach
            </x-hp.select>
        </div>

        {{-- Sex --}}
        <div class="mb-4">
            <p class="mb-1.5 text-sm font-semibold text-hp-slate">Sex</p>
            <div class="flex gap-6">
                <label class="flex cursor-pointer items-center gap-2">
                    <input
                        type="radio"
                        name="sex"
                        value="M"
                        {{ old('sex') === 'M' ? 'checked' : '' }}
                        class="h-4 w-4 border-hp-slate/30 text-hp-orange focus:ring-hp-orange"
                    >
                    <span class="text-sm font-medium text-hp-slate">Male</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2">
                    <input
                        type="radio"
                        name="sex"
                        value="F"
                        {{ old('sex') === 'F' ? 'checked' : '' }}
                        class="h-4 w-4 border-hp-slate/30 text-hp-orange focus:ring-hp-orange"
                    >
                    <span class="text-sm font-medium text-hp-slate">Female</span>
                </label>
            </div>
            @error('sex')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Program / Year Level — both come from the chosen college's entry in
             config/programs.php (D-42) and stay disabled until it is chosen.
             The server re-checks both against the SUBMITTED college, so this
             cascade is convenience, not security. --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-hp.select
                    label="Program"
                    id="course"
                    name="course"
                    required
                    x-model="course"
                    x-bind:disabled="! collegeId"
                    :error="$errors->first('course')"
                    {{-- Program names run long ("…Industrial Technology major in
                         Instrumentation and Control"). The browser paints the
                         dropdown arrow inside the box's right padding, so the
                         default px-3 leaves the text running underneath it —
                         pr-9 stops the text short of the arrow and truncate
                         ends it with an ellipsis instead of a hard clip. --}}
                    class="pr-9 truncate"
                >
                    <option value="" x-text="collegeId ? '— Select your program —' : '— Select your college first —'">
                        — Select your college first —
                    </option>
                    {{-- :selected as well as x-model — these <option>s are created
                         by x-for AFTER the <select> initialises, so the binding on
                         the option itself is what restores a previous choice. --}}
                    <template x-for="program in programs" :key="program">
                        <option :value="program" :selected="program === course" x-text="program"></option>
                    </template>
                </x-hp.select>
            </div>
            <div>
                <x-hp.select
                    label="Year Level"
                    id="year_level"
                    name="year_level"
                    required
                    x-model="yearLevel"
                    x-bind:disabled="! collegeId"
                    :error="$errors->first('year_level')"
                >
                    <option value="">—</option>
                    <template x-for="[value, label] in Object.entries(yearLevels)" :key="value">
                        <option :value="value" :selected="value === yearLevel" x-text="label"></option>
                    </template>
                </x-hp.select>
            </div>
        </div>

        {{-- Date of Birth + Age badge --}}
        <div class="mb-4">
            <p class="mb-1.5 text-sm font-semibold text-hp-slate">Date of Birth</p>
            <div
                class="flex items-center gap-3"
                x-data="{
                    dob: '{{ old('date_of_birth', '') }}',
                    get age() {
                        if (!this.dob) return null;
                        const diff = Date.now() - new Date(this.dob).getTime();
                        return Math.floor(diff / (365.25 * 24 * 3600 * 1000));
                    }
                }"
            >
                <input
                    type="date"
                    id="date_of_birth"
                    name="date_of_birth"
                    x-model="dob"
                    value="{{ old('date_of_birth') }}"
                    max="{{ now()->subDay()->toDateString() }}"
                    required
                    class="rounded-lg border border-hp-slate/25 px-3 py-2 text-sm text-hp-slate
                           transition-colors duration-hp-fast focus:border-hp-orange focus:ring-1
                           focus:ring-hp-orange focus:outline-none"
                >
                {{-- Age badge (shows once a date is picked) --}}
                <span
                    x-show="age !== null"
                    x-cloak
                    class="rounded-lg bg-hp-peach px-3.5 py-[10px] text-[13px] font-semibold text-hp-orange whitespace-nowrap"
                >
                    Age: <span x-text="age"></span>
                </span>
            </div>
            @error('date_of_birth')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Place of Birth / Civil Status --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-hp.input
                label="Place of Birth"
                id="place_of_birth"
                name="place_of_birth"
                type="text"
                :value="old('place_of_birth')"
                placeholder="e.g. San Fernando, Pampanga"
                required
                :error="$errors->first('place_of_birth')"
            />
            <x-hp.select
                label="Civil Status"
                id="civil_status"
                name="civil_status"
                required
                :error="$errors->first('civil_status')"
            >
                <option value="">— Select status —</option>
                @foreach (['Single', 'Married', 'Widowed', 'Separated'] as $status)
                    <option
                        value="{{ $status }}"
                        {{ old('civil_status') === $status ? 'selected' : '' }}
                    >
                        {{ $status }}
                    </option>
                @endforeach
            </x-hp.select>
        </div>

        {{-- Address --}}
        <div class="mb-6">
            <label for="address" class="mb-1 block text-sm font-semibold text-hp-slate">
                Address
            </label>
            <textarea
                id="address"
                name="address"
                rows="2"
                placeholder="House/Unit No., Street, Barangay, City/Municipality, Province"
                required
                class="w-full rounded-lg border border-hp-slate/25 px-3 py-2 text-sm text-hp-slate
                       placeholder-hp-slate/40 transition-colors duration-hp-fast resize-none
                       focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none"
            >{{ old('address') }}</textarea>
            @error('address')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Account Credentials ──────────────────────────────────────────── --}}
        <div class="mb-3 border-t border-hp-slate/10 pt-5">
            <p class="mb-3 text-xs font-bold uppercase tracking-widest text-hp-slate/40">
                Account Credentials
            </p>
        </div>

        {{-- Email --}}
        <div class="mb-4">
            <x-hp.input
                label="Email Address"
                id="email"
                name="email"
                type="email"
                :value="old('email')"
                placeholder="you@example.com"
                required
                autocomplete="username"
                :error="$errors->first('email')"
            />
        </div>

        {{-- Password / Confirm Password --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-hp.input
                label="Password"
                id="password"
                name="password"
                :password="true"
                placeholder="Min. 8 characters"
                required
                autocomplete="new-password"
                :error="$errors->first('password')"
            />
            <x-hp.input
                label="Confirm Password"
                id="password_confirmation"
                name="password_confirmation"
                :password="true"
                placeholder="Repeat password"
                required
                autocomplete="new-password"
            />
        </div>

        {{-- Actions — side by side like desktop; scales down to fit narrow phones,
             back to full lg size at sm+ (media-query classes override the base size). --}}
        <div class="flex items-center justify-between gap-2 sm:gap-[10px]">
            <a href="{{ route('register') }}"
               class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-full
                      px-4 py-1.5 text-xs sm:px-8 sm:py-[13px] sm:text-[15px]
                      font-semibold bg-transparent text-hp-slate border-[1.5px] border-hp-slate/30
                      transition-colors hover:bg-hp-slate/5">
                ← Back
            </a>
            <x-hp.button type="submit" variant="primary" size="sm" x-bind:disabled="!ready" data-pending-label="Please wait…" class="shrink-0 whitespace-nowrap sm:px-8 sm:py-[13px] sm:text-[15px]">
                Continue →
            </x-hp.button>
        </div>

    </form>

</x-register.wizard-shell>
