<x-layout.sidebar title="Staff Accounts">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Staff Accounts</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Create College Admin, Nurse and Physician accounts, move an admin between
        colleges, correct a physician's license number, and deactivate anyone who
        has left. Accounts are never deleted — their records
        stay in the system. A staff member who forgets their password recovers it
        themselves with "Forgot password" on the login page (FR-AUTH-10).
    </p>
</div>

{{-- Success / status flash (create, activate/deactivate, reassign). --}}
@if (session('status'))
    <div data-hp-flash class="mb-5 rounded-lg border border-hp-orange/30 bg-hp-peach/40 px-4 py-3 text-sm font-medium text-hp-slate">
        {{ session('status') }}
    </div>
@endif

{{-- A refused license correction (D-64). Its own error bag, so the message
     never lands on the New staff account form's License No. field. --}}
@if ($errors->license->any())
    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        License not changed: {{ $errors->license->first('license_number') }}
    </div>
@endif

{{-- ── Show-once credential panel ───────────────────────────────────────────────
     Rendered ONLY on the request right after a password was issued (D-35/D-47).
     The plaintext was flashed to the session by the controller and is gone on the
     next request; nothing anywhere stores it in readable form. --}}
@if (session('new_staff_credential'))
    @php $credential = session('new_staff_credential'); @endphp
    <x-hp.card class="mb-6 border-2 border-hp-orange bg-hp-white" x-data="{ copied: false }">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 shrink-0 text-hp-orange">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
            </div>
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-hp-slate">
                    Copy this password now — it will not be shown again
                </h3>
                <p class="mt-1 text-[13px] text-hp-slate/70">
                    Hand it to <strong class="font-semibold text-hp-slate">{{ $credential['name'] }}</strong>
                    through official channels. HealthPass does not email it and does not
                    store it anywhere, and <strong class="font-semibold text-hp-slate">this is the only
                    password you will ever set for them</strong> — if it is lost before they sign in,
                    they recover it themselves with "Forgot password" on the login page.
                    They must change it the first time they sign in.
                </p>

                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/50">Sign in with</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-hp-slate">{{ $credential['email'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/50">One-time password</dt>
                        <dd class="mt-1 flex items-center gap-2">
                            <input type="text" readonly x-ref="pw" value="{{ $credential['password'] }}"
                                   aria-label="One-time password"
                                   class="w-full rounded-lg border-[1.5px] border-hp-orange/40 bg-hp-peach/40 px-3 py-2 font-mono text-sm font-semibold tracking-wide text-hp-slate">
                            <x-hp.button variant="soft" size="sm" class="shrink-0"
                                         x-on:click="$refs.pw.select(); navigator.clipboard.writeText($refs.pw.value); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-show="!copied">Copy</span>
                                <span x-show="copied" x-cloak>Copied</span>
                            </x-hp.button>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </x-hp.card>
@endif

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ── New staff account ────────────────────────────────────────────────
         `role` drives whether the college picker and the license field are
         enabled. A DISABLED field is not submitted at all, which is exactly what
         the request's `prohibited` rules want for every other role — the server
         re-checks either way. --}}
    <x-hp.card class="h-fit lg:col-span-1" x-data="{ role: '{{ old('role', 'college_admin') }}' }">
        <h3 class="text-sm font-semibold text-hp-slate">New staff account</h3>
        <p class="mt-1 text-[13px] text-hp-slate/60">
            The account is created active, with a one-time password shown once on
            this page.
        </p>

        <form method="POST" action="{{ route('director.staff.store') }}" class="mt-4 space-y-4">
            @csrf

            <x-hp.select label="Role" name="role" x-model="role" required
                         :error="$errors->first('role')">
                <option value="college_admin">College Admin</option>
                <option value="nurse">Nurse</option>
                <option value="physician">Physician</option>
            </x-hp.select>

            <x-hp.input label="Full name" name="name" type="text" maxlength="120" required
                        value="{{ old('name') }}"
                        placeholder="e.g. Maria Santos"
                        :error="$errors->first('name')" />
            {{-- D-64: the print adds ", MD" itself (NAME, MD). --}}
            <p x-show="role === 'physician'" x-cloak class="-mt-2 text-[12px] text-hp-slate/50">
                Enter the name without 'Dr.' or 'MD'; the form adds ', MD'.
            </p>

            <x-hp.input label="Email" name="email" type="email" maxlength="191" required
                        value="{{ old('email') }}"
                        placeholder="e.g. admin.ccs@dhvsu.edu.ph"
                        :error="$errors->first('email')" />

            <div x-show="role === 'college_admin'" x-cloak>
                <x-hp.select label="College" name="managed_college_id"
                             x-bind:disabled="role !== 'college_admin'"
                             :error="$errors->first('managed_college_id')">
                    <option value="">Select a college…</option>
                    @foreach ($colleges as $college)
                        <option value="{{ $college->id }}" @selected(old('managed_college_id') == $college->id)>
                            {{ $college->code }} — {{ $college->name }}
                        </option>
                    @endforeach
                </x-hp.select>
                <p class="mt-1 text-[12px] text-hp-slate/50">
                    An admin only ever sees this college's students and batches.
                </p>
            </div>

            {{-- D-64: prints under the physician's name on every record they
                 encode. Required for a physician, refused for anyone else. --}}
            <div x-show="role === 'physician'" x-cloak>
                <x-hp.input label="License No." name="license_number" type="text"
                            inputmode="numeric" pattern="[0-9]{4,10}" maxlength="10"
                            x-bind:disabled="role !== 'physician'"
                            x-bind:required="role === 'physician'"
                            value="{{ old('license_number') }}"
                            placeholder="e.g. 60252"
                            :error="$errors->first('license_number')" />
                <p class="mt-1 text-[12px] text-hp-slate/50">
                    Digits only, 4 to 10. Prints on the records this physician encodes.
                </p>
            </div>

            <p x-show="role === 'nurse' || role === 'physician'" x-cloak
               class="rounded-lg bg-hp-slate/5 px-3 py-2 text-[12px] text-hp-slate/60">
                Nurses and physicians work clinic-wide and are not tied to a college.
            </p>

            <x-hp.button type="submit" variant="primary" size="md" class="w-full"
                         data-pending-label="Creating…">
                Create account
            </x-hp.button>
        </form>
    </x-hp.card>

    {{-- ── Existing accounts ────────────────────────────────────────────────── --}}
    <x-hp.card class="lg:col-span-2">
        <h3 class="mb-4 text-sm font-semibold text-hp-slate">
            College Admins, Nurses &amp; Physicians
            <span class="ml-1 font-normal text-hp-slate/50">({{ $accounts->total() }})</span>
        </h3>

        @if ($accounts->isEmpty())
            <p class="py-8 text-center text-sm text-hp-slate/50">
                No staff accounts yet. Create one with the form on the left.
            </p>
        @else
            <x-hp.table :headers="['Name', 'Role', 'College', 'Status', 'Last active', 'Actions']">
                @foreach ($accounts as $account)
                    <x-hp.table-row>
                        <x-hp.table-cell label="Name">
                            <span class="font-medium">{{ $account->name }}</span>
                            <span class="block break-all text-[12px] text-hp-slate/50">
                                {{ $account->email }}
                            </span>
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Role">
                            {{ $account->roleLabel() }}
                            @if ($account->isPhysician())
                                {{-- D-64: the license, with its correction dialog. --}}
                                <x-license-correct-confirm :account="$account" />
                            @endif
                        </x-hp.table-cell>

                        <x-hp.table-cell label="College">
                            @if ($account->role === 'college_admin')
                                {{-- Picking a college IS the action — there is no Save
                                     button, because a second step next to a dropdown
                                     reads as "did that save or not?". The picker and
                                     its confirmation dialog live together in one
                                     component, which uses the same modal UI as the
                                     Log out confirmation. --}}
                                <x-college-reassign-confirm :account="$account" :colleges="$colleges" />
                            @else
                                <span class="text-hp-slate/50">—</span>
                            @endif
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Status">
                            @if ($account->status === 'active')
                                <x-hp.badge variant="approved">Active</x-hp.badge>
                            @else
                                <x-hp.badge variant="rejected">Inactive</x-hp.badge>
                            @endif

                            @if ($account->must_change_password)
                                <span class="mt-1 block text-[11px] text-hp-slate/50">
                                    Password change pending
                                </span>
                            @endif
                        </x-hp.table-cell>

                        {{-- Last active (D-48): "allowed in" and "actually being
                             used" are different questions, and only this column
                             answers the second one. "Never" means never seen since
                             the column existed — it is not backfilled. --}}
                        <x-hp.table-cell label="Last active">
                            @if ($account->last_active_at)
                                <span title="{{ $account->last_active_at->format('M j, Y g:i A') }}">
                                    {{ $account->last_active_at->diffForHumans() }}
                                </span>
                                <span class="block text-[11px] text-hp-slate/50">
                                    {{ $account->last_active_at->format('M j, Y g:i A') }}
                                </span>
                            @else
                                <span class="text-hp-slate/50">Never</span>
                            @endif
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Actions">
                            <div class="flex flex-wrap items-center justify-end gap-2 md:justify-start">
                                {{-- There is deliberately no "reset password" action:
                                     a Director who could set someone else's password
                                     could sign in as them and read their records, which
                                     is the impersonation D-47 rules out. A forgotten
                                     password goes through "Forgot password" on the login
                                     page, which only the account's own mailbox completes. --}}

                                {{-- Activate / deactivate. The TARGET state is posted,
                                     not a toggle, so a resubmit is harmless. --}}
                                <form method="POST" action="{{ route('director.staff.status', $account) }}"
                                      @if ($account->status === 'active')
                                          onsubmit="return confirm('Deactivate this account? They will not be able to sign in. Their records stay in the system.')"
                                      @endif>
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status"
                                           value="{{ $account->status === 'active' ? 'inactive' : 'active' }}">
                                    <x-hp.button type="submit"
                                                 variant="{{ $account->status === 'active' ? 'danger' : 'soft' }}"
                                                 size="sm"
                                                 data-pending-label="Saving…">
                                        {{ $account->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </x-hp.button>
                                </form>
                            </div>
                        </x-hp.table-cell>
                    </x-hp.table-row>
                @endforeach
            </x-hp.table>

            <x-hp.pager :paginator="$accounts" />
        @endif
    </x-hp.card>
</div>

</x-layout.sidebar>
