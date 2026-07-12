{{-- Privacy Consent (FR-KSK-04): RA 10173 notice shown once per session.
     "I Agree" stamps state.consentAt (persisted to clinic_visits.privacy_consent_at
     at final submit) and proceeds to Vitals. "Decline" resets to Welcome storing
     NOTHING (no DB write happens on this screen either way). --}}
<section class="kiosk-screen" x-show="state.screen === 'consent'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center px-10 py-10">
        <span class="rounded-full bg-hp-peach/40 px-4 py-1.5 text-sm font-semibold uppercase tracking-widest text-hp-orange">Data Privacy</span>
        <h1 class="mt-3 text-3xl font-semibold text-hp-slate">Privacy Consent</h1>
        <p class="mt-2 text-base text-hp-slate/70">Republic Act No. 10173 — Data Privacy Act of 2012</p>

        {{-- Scrollable notice --}}
        <div class="mt-6 max-h-[26rem] w-full max-w-2xl overflow-y-auto rounded-2xl bg-hp-white p-7 text-left text-base leading-relaxed text-hp-slate shadow-sm">
            <p>
                By proceeding, you consent to PSU University Health Services collecting
                and processing your <strong>vital signs and health-screening responses</strong>
                captured at this kiosk for the purpose of your medical clearance.
            </p>
            <p class="mt-3">
                Your information will be reviewed only by authorized clinic staff (nurse and
                University Physician), stored securely on University systems, and will
                <strong>not</strong> be shared with third parties or used for any purpose
                other than your clearance.
            </p>
            <p class="mt-3">
                Under RA 10173 you have the right to access, correct, and object to the
                processing of your personal data. This consent applies to
                <strong>this session only</strong>.
            </p>
        </div>

        {{-- Actions --}}
        <div class="mt-8 flex items-center gap-4">
            <button
                type="button"
                @click="reset()"
                class="rounded-2xl px-8 py-5 text-base font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >Decline</button>
            <button
                type="button"
                @click="agreeConsent()"
                class="rounded-2xl bg-hp-orange px-12 py-5 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
            >I Agree — Proceed</button>
        </div>
    </div>
</section>
