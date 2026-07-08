<x-register.wizard-shell :step="1">

    <h2 class="mb-[6px] text-[15px] font-bold text-hp-slate">Step 1 — Data Privacy Consent</h2>

    {{-- Scrollable notice --}}
    <div class="mb-[14px] h-56 overflow-y-auto rounded-lg bg-hp-bg p-3.5
                text-[12px] leading-[1.8] text-hp-slate/[75%]">

        <p class="mb-3 font-bold text-hp-slate">
            RA 10173 — Data Privacy Act of 2012
        </p>

        <p class="mb-3">
            Pampanga State University's Health Services Unit collects personal and health information
            from students for the purpose of processing medical and dental clearances. This is governed
            by the <strong>Republic Act No. 10173 (Data Privacy Act of 2012)</strong>.
        </p>

        <p class="mb-2 font-semibold text-hp-slate">What we collect</p>
        <p class="mb-3">
            Name, student number, college enrollment, date of birth, place of birth, civil status,
            address, contact email, vital signs (height, weight, blood pressure, temperature, heart rate),
            and responses to a nine-item body-system health screening questionnaire.
        </p>

        <p class="mb-2 font-semibold text-hp-slate">Why we collect it</p>
        <p class="mb-3">
            To process your medical and dental clearance requests, generate official clearance documents
            (PamSU form DHVSU-QSP-OSS-004-FO002-R03), and maintain clinic records required by the
            university for accreditation and institutional reporting.
        </p>

        <p class="mb-2 font-semibold text-hp-slate">How we protect it</p>
        <p class="mb-3">
            Your data is stored securely on university servers and is accessible only to authorized
            clinic personnel (nurses) and your designated College Administrator. Health assessment
            outcomes and individual records do not leave the university's information systems.
        </p>

        <p class="mb-2 font-semibold text-hp-slate">Your rights under RA 10173</p>
        <p class="mb-3">
            You have the right to: access your personal data, request corrections, object to processing,
            and (subject to legal retention requirements) request erasure of your records. To exercise
            these rights, contact the University Data Protection Officer through the Office of Student
            Welfare and Formation.
        </p>

        <p>
            By ticking the checkbox and continuing, you acknowledge that you have read and understood
            this notice, and that you <strong>freely give your informed consent</strong> to the
            collection and processing of your personal information for the purposes described above.
        </p>

    </div>

    {{-- Consent form (Alpine drives checkbox → button state) --}}
    <form
        method="POST"
        action="{{ route('register.consent') }}"
        x-data="{ agreed: false }"
    >
        @csrf

        {{-- Checkbox --}}
        <label class="mb-5 flex cursor-pointer items-start gap-[10px]">
            <input
                type="checkbox"
                name="consent"
                value="1"
                x-model="agreed"
                class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-hp-slate/30
                       text-hp-orange focus:ring-hp-orange"
            >
            <span class="text-[12px] leading-[1.6] text-hp-slate">
                I consent to the collection and processing of my personal health data by PamSU Campus
                Clinic for medical clearance purposes, in accordance with RA 10173.
            </span>
        </label>

        @error('consent')
            <p class="mb-3 text-xs text-red-600">{{ $message }}</p>
        @enderror

        {{-- Actions --}}
        <div class="flex flex-col items-center gap-[10px] sm:flex-row sm:justify-between">
            <a href="{{ route('login') }}"
               class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-full px-8 py-[13px] text-[15px]
                      font-semibold bg-transparent text-hp-slate border-[1.5px] border-hp-slate/30
                      transition-colors hover:bg-hp-slate/5">
                ← Back to Login
            </a>

            <x-hp.button
                type="submit"
                variant="primary"
                size="lg"
                x-bind:disabled="!agreed"
                class="shrink-0 whitespace-nowrap"
            >
                Continue →
            </x-hp.button>
        </div>

    </form>

</x-register.wizard-shell>
