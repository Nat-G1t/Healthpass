<x-register.wizard-shell :step="2" maxWidth="max-w-[560px]">

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
        x-init="validate()"
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

        {{-- Student Number --}}
        <div class="mb-4">
            <x-hp.input
                label="Student Number"
                id="student_number"
                name="student_number"
                type="text"
                :value="old('student_number')"
                placeholder="e.g. 2024-00001"
                required
                autocomplete="off"
                :error="$errors->first('student_number')"
            />
        </div>

        {{-- College --}}
        <div class="mb-4">
            <x-hp.select
                label="College"
                id="college_id"
                name="college_id"
                required
                :error="$errors->first('college_id')"
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

        {{-- Course / Year Level --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-hp.input
                    label="Course"
                    id="course"
                    name="course"
                    type="text"
                    :value="old('course')"
                    placeholder="e.g. BSCS, BSED, BSBA"
                    required
                    :error="$errors->first('course')"
                />
            </div>
            <div>
                <x-hp.select
                    label="Year Level"
                    id="year_level"
                    name="year_level"
                    required
                    :error="$errors->first('year_level')"
                >
                    <option value="">—</option>
                    @foreach (['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year', '5' => '5th Year'] as $val => $label)
                        <option value="{{ $val }}" {{ old('year_level') === $val ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
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
                           transition-colors duration-150 focus:border-hp-orange focus:ring-1
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

        {{-- Place of Birth --}}
        <div class="mb-4">
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
        </div>

        {{-- Civil Status --}}
        <div class="mb-4">
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
                       placeholder-hp-slate/40 transition-colors duration-150 resize-none
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
