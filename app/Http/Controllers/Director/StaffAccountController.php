<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Http\Requests\Director\StoreStaffAccountRequest;
use App\Http\Requests\Director\UpdateStaffCollegeRequest;
use App\Http\Requests\Director\UpdateStaffLicenseRequest;
use App\Jobs\SendStaffAccountCreatedMail;
use App\Jobs\SendStaffTransferredMail;
use App\Models\College;
use App\Models\User;
use App\Support\TransferNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Staff Accounts (FR-AUTH-10, D-47) — the Director's provisioning console.
 *
 * Before this screen, a College Admin or Nurse account could only be created by
 * running database/seeders/StaffSeeder.php, which on the hosted deploy (D-34)
 * means a developer with server and database access. This adds an in-app path
 * ALONGSIDE the seeder; the seeder is unchanged and stays the bootstrap for the
 * very first Director account.
 *
 * D-47 gives the Director super-admin CAPABILITIES rather than a role of its
 * own. D-64 later added `physician` to users.role (now five values); the
 * Director provisions physicians here, with a license number, and can correct
 * that license afterwards.
 *
 * Three rules run through everything below:
 *
 *  1. ANTI-ESCALATION — only college_admin, nurse and physician can be created
 *     or touched.
 *     Never another director, never a student. Enforced server-side in
 *     StoreStaffAccountRequest and in guardManageable(), not by the UI.
 *  2. ONE CREDENTIAL PATH — the joining password is generated exactly as
 *     StaffSeeder::createStaff() does (D-35) and the existing
 *     RequirePasswordChange middleware forces the change at first login. The
 *     plaintext is shown once and stored nowhere. The Director issues that
 *     password ONCE, at creation, and can never set it again: a Director who
 *     could reset an account's password could sign in as that person and read
 *     their records, which is the impersonation D-47 rules out. A forgotten
 *     password goes through the ordinary forgot-password OTP flow (D-20),
 *     which only the account's own mailbox can complete.
 *  3. NEVER DELETE — clearance_records.encoded_by and batch_requests.reviewed_by
 *     point at staff rows with restrictOnDelete. Deactivation is the only form
 *     of removal this system has, and it leaves history untouched.
 */
class StaffAccountController extends Controller
{
    /** Same length StaffSeeder issues (D-35). */
    private const ONE_TIME_PASSWORD_LENGTH = 16;

    /**
     * The only roles this screen lists or writes to.
     *
     * @var list<string>
     */
    public const MANAGEABLE_ROLES = ['college_admin', 'nurse', 'physician'];

    /** The staff roster, plus the one-time credential if one was just issued. */
    public function index(): View
    {
        $accounts = User::with('managedCollege')
            ->whereIn('role', self::MANAGEABLE_ROLES)
            ->orderBy('name')
            ->get()
            // Then group by role. Sorted in PHP, not with orderBy('role'):
            // role is a MySQL ENUM, which MySQL sorts by DECLARATION order while
            // the SQLite test database sorts it alphabetically — a difference the
            // suite could never catch (CLAUDE.md). PHP's sort is stable, so names
            // stay ordered inside each role.
            ->sortBy(fn (User $account): int => (int) array_search($account->role, self::MANAGEABLE_ROLES, true))
            ->values();

        return view('director.staff', [
            'accounts' => $accounts,
            'colleges' => College::orderBy('code')->get(),
        ]);
    }

    /** Create a College Admin, Nurse or Physician and issue their one-time password. */
    public function store(StoreStaffAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $password = $this->generateOneTimePassword();

        $staff = User::create([
            'role' => $validated['role'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Staff do not verify by email — the Director hands the account over
            // through official channels, so the address is trusted on creation.
            // StaffSeeder does the same.
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            // Belt and braces on top of the request's required/prohibited rules:
            // a nurse never carries a managed college.
            'managed_college_id' => $validated['role'] === 'college_admin'
                ? $validated['managed_college_id']
                : null,
            // Same belt and braces: only a physician carries a license (D-64).
            'license_number' => $validated['role'] === 'physician'
                ? $validated['license_number']
                : null,
            'status' => 'active',
            'must_change_password' => true,
        ]);

        // Tell them the account exists and where to sign in (D-50). QUEUED, so a
        // slow or unreachable mail server cannot fail the provisioning that has
        // already been written. The message carries NO password — that is shown
        // on this screen once and handed over in person (D-35).
        SendStaffAccountCreatedMail::dispatch($staff);

        return redirect()
            ->route('director.staff.index')
            ->with('status', 'Account created for '.$validated['name'].'. A welcome email is on its way.')
            ->with('new_staff_credential', [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $password,
            ]);
    }

    /**
     * Activate or deactivate an account (FR-AUTH-07). An inactive user is
     * refused at login by LoginRequest::authenticate().
     */
    public function status(Request $request, User $user): RedirectResponse
    {
        $this->guardManageable($request, $user);

        // The form posts the TARGET state rather than "toggle", so a resubmitted
        // or double-clicked request lands on the state the Director chose
        // instead of flipping the account back on behind them.
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        // Only the one column moves. Their clearance records and reviewed
        // batches keep pointing at this row and keep rendering as before.
        $user->update(['status' => $validated['status']]);

        return redirect()
            ->route('director.staff.index')
            ->with('status', $validated['status'] === 'active'
                ? $user->name.' can sign in again.'
                : $user->name.' has been deactivated and can no longer sign in.');
    }

    /** Move a College Admin to a different college (FR-AUTH-06). */
    public function college(UpdateStaffCollegeRequest $request, User $user): RedirectResponse
    {
        $this->guardManageable($request, $user);

        // Nurses are clinic-wide and have no managed college, so there is
        // nothing to reassign — refuse rather than quietly writing the column.
        abort_unless($user->role === 'college_admin', 403);

        // Read the origin BEFORE the write. The column is overwritten in place
        // and the old value is not recorded anywhere, so this is the only moment
        // the transfer can be described at all.
        $from = $user->managedCollege;
        $to = College::findOrFail($request->validated()['managed_college_id']);

        // Moving an admin to the college they are already on is a no-op the UI
        // cannot even produce (the select fires no change event), but guard it
        // so a hand-made request cannot send "moved from CCS to CCS".
        if ($from !== null && $from->is($to)) {
            return redirect()
                ->route('director.staff.index')
                ->with('status', $user->name.' already manages '.$to->code.'.');
        }

        $user->update(['managed_college_id' => $to->id]);

        if ($from !== null) {
            // Two notices, both about the same move (D-50). The email is the
            // durable one; the dashboard notice is so the change is not a
            // surprise when their students appear to have vanished.
            SendStaffTransferredMail::dispatch($user, $from, $to);
            TransferNotice::put($user, $from, $to);
        }

        return redirect()
            ->route('director.staff.index')
            ->with('status', $user->name.' now manages '.$to->code.'. They have been emailed about the change.');
    }

    /**
     * Correct a physician's license number (D-64). Records they already
     * encoded keep the license copied at encode time; only new ones change.
     */
    public function license(UpdateStaffLicenseRequest $request, User $user): RedirectResponse
    {
        $this->guardManageable($request, $user);

        // Only a physician has a license — refuse rather than write the column
        // onto a nurse or a college admin.
        abort_unless($user->isPhysician(), 403);

        $user->update(['license_number' => $request->validated()['license_number']]);

        return redirect()
            ->route('director.staff.index')
            ->with('status', "{$user->name}'s license number is now {$user->license_number}. Records they encode from now on print it.");
    }

    /**
     * Every write endpoint runs this before touching anything.
     *
     * Route-model binding has already turned {user} into a User, but it binds
     * ANY user id — including a student, another director, or the signed-in
     * Director themselves. These two checks are what make the endpoints safe.
     */
    private function guardManageable(Request $request, User $user): void
    {
        // The Director may not act on their own account here: deactivating
        // yourself locks the only super-admin out of the system, and reissuing
        // your own password would sidestep the OTP-confirmed change flow (D-20).
        abort_if($user->is($request->user()), 403, 'You cannot manage your own account from this screen.');

        // Anti-escalation (D-47): never a director, never a student.
        abort_unless(in_array($user->role, self::MANAGEABLE_ROLES, true), 403);
    }

    /**
     * The joining password, generated exactly as StaffSeeder::createStaff() does
     * (D-35) — 16 random characters, no symbols, because this string is read off
     * a screen and typed by hand.
     *
     * Called from store() and nowhere else: this is the only moment in an
     * account's life at which anyone but its owner sets its password. It is
     * returned in plaintext for a single flash to the next request and is never
     * written anywhere in readable form — only the bcrypt hash reaches the
     * database.
     */
    private function generateOneTimePassword(): string
    {
        return Str::password(self::ONE_TIME_PASSWORD_LENGTH, symbols: false);
    }
}
