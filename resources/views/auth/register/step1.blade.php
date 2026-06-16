<x-register.wizard-shell :step="1">

    <h2 class="mb-1 text-lg font-bold text-hp-slate">Data Privacy Consent</h2>
    <p class="mb-5 text-sm text-hp-slate/60">
        Please read the notice below before creating your account.
    </p>

    {{-- Scrollable notice --}}
    <div class="mb-5 h-56 overflow-y-auto rounded-lg border border-hp-slate/20 bg-hp-bg px-5 py-4
                text-sm leading-relaxed text-hp-slate/80">

        <p class="mb-3 font-bold text-hp-slate">
            RA 10173 — Data Privacy Notice
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
        <label class="mb-5 flex cursor-pointer items-start gap-3">
            <input
                type="checkbox"
                name="consent"
                value="1"
                x-model="agreed"
                class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-hp-slate/30
                       text-hp-orange focus:ring-hp-orange"
            >
            <span class="text-sm text-hp-slate">
                I have read and accept the RA 10173 Data Privacy Notice. I consent to the
                collection and processing of my personal information by Pampanga State
                University's Health Services Unit.
            </span>
        </label>

        @error('consent')
            <p class="mb-3 text-xs text-red-600">{{ $message }}</p>
        @enderror

        {{-- Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('login') }}" class="text-sm text-hp-slate/60 hover:underline">
                ← Back to login
            </a>

            {{-- x-bind:disabled passes Alpine binding through the component's $attributes->merge() --}}
            <x-hp.button
                type="submit"
                variant="primary"
                size="lg"
                x-bind:disabled="!agreed"
            >
                Continue →
            </x-hp.button>
        </div>

    </form>

</x-register.wizard-shell>
