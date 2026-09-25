<?php

namespace Database\Seeders;

use App\Models\ClearanceRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DEFENSE DEPLOY ONLY — the demo data for the public site (healthpass.site).
 *
 * Run ONCE, on a freshly migrated, EMPTY database:
 *
 *     php artisan db:seed --class=DefenseDemoSeeder --force
 *
 * It builds exactly the data the laptop shows (the same dev seeders as
 * DatabaseSeeder) plus the 2021–2025 history (HistoricalClearanceSeeder),
 * then makes it safe for the internet:
 *
 *  - the demo Director becomes **Dev Director** and the demo Physician becomes
 *    **Dev Physician** (license 123456) — real inboxes, a one-time password
 *    each, and `must_change_password` so both must set their own at first
 *    login (D-35);
 *  - the demo Nurse and the 11 demo College Admins are **deactivated**
 *    (FR-AUTH-07). They stay as the "former" staff who submitted and encoded
 *    the demo history, but cannot log in. The real CCS admin and nurse are
 *    created live from Director → Staff Accounts (D-47);
 *  - no account keeps the dev shared `password`;
 *  - every dummy address moves to Resend's test inbox
 *    (`delivered+<name>@resend.dev`), which never reaches a real person and
 *    does not count against the sending domain's reputation.
 *
 * The passwords are printed once and stored nowhere (same rule as StaffSeeder).
 */
class DefenseDemoSeeder extends Seeder
{
    private const DEV_DIRECTOR = [
        'name' => 'Dev Director',
        'email' => '2023300194@pampangastateu.edu.ph',
    ];

    private const DEV_PHYSICIAN = [
        'name' => 'Dev Physician',
        'email' => 'medinanatnat@gmail.com',
        'license_number' => '123456',
    ];

    /** Resend's "always delivered" test inbox — a label goes after the `+`. */
    private const TEST_INBOX_DOMAIN = 'resend.dev';

    private const PASSWORD_LENGTH = 16;

    public function run(): void
    {
        // Refuse to touch a database that already has people in it — this
        // seeder rewrites accounts and must never run against live data.
        if (User::query()->exists()) {
            throw new RuntimeException('DefenseDemoSeeder only runs on an EMPTY database (users table is not empty).');
        }

        // The dev seeders look staff up by their @healthpass.test address, so
        // pin dev mode for this run whatever the server's .env says.
        config([
            'healthpass.seed_staff.one_time_passwords' => false,
            'healthpass.seed_staff.email_domain' => 'healthpass.test',
        ]);

        // The demo seeders skip themselves when app()->environment() is
        // 'production', so a stray `db:seed` can never fill a real clinic's
        // database with fake visits. This seeder IS the sanctioned way to put
        // demo data on the defense site (approved by Nat, 2026-09-25), so it
        // lifts that guard for this one run and restores the real environment.
        $environment = app()->environment();
        app()->instance('env', 'defense-demo');

        try {
            $this->call([
                DatabaseSeeder::class,
                HistoricalClearanceSeeder::class, // 2021–2025, for the yearly report (D-81)
            ]);
        } finally {
            app()->instance('env', $environment);
        }

        $passwords = DB::transaction(fn (): array => $this->makePublicSafe());

        $this->report($passwords);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> rows of name, email, password
     */
    private function makePublicSafe(): array
    {
        $director = User::where('role', 'director')->sole();
        $physician = User::where('role', 'physician')->sole();

        $directorPassword = $this->newPassword();
        $director->update([
            ...self::DEV_DIRECTOR,
            'password' => Hash::make($directorPassword),
            'must_change_password' => true,
        ]);

        $physicianPassword = $this->newPassword();
        $physician->update([
            ...self::DEV_PHYSICIAN,
            'password' => Hash::make($physicianPassword),
            'must_change_password' => true,
        ]);

        // Records the physician encoded print a snapshot of their name and
        // license (D-64), so re-stamp them with the account's new identity.
        ClearanceRecord::where('encoded_by', $physician->id)
            ->update(ClearanceRecord::physicianBlockFor($physician->refresh()));

        // Former staff: deactivated, unknown password, undeliverable address.
        $unusable = Hash::make(Str::random(40));
        User::whereIn('role', ['nurse', 'college_admin'])->each(function (User $staff) use ($unusable): void {
            $staff->update([
                'email' => $this->testInbox($staff->email),
                'password' => $unusable,
                'status' => 'inactive',
            ]);
        });

        // Dummy students share one random password so Nat can open a
        // student's view during the demo; nobody else knows it.
        $studentPassword = $this->newPassword();
        $studentHash = Hash::make($studentPassword);
        User::where('role', 'student')->each(function (User $student) use ($studentHash): void {
            $student->update([
                'email' => $this->testInbox($student->email),
                'password' => $studentHash,
            ]);
        });

        return [
            [$director->name, $director->email, $directorPassword],
            [$physician->name, $physician->email, $physicianPassword],
            ['Every dummy student', 'delivered+<name>@'.self::TEST_INBOX_DOMAIN, $studentPassword],
        ];
    }

    /** juan.santos@psu.edu.ph → delivered+juan.santos@resend.dev */
    private function testInbox(string $email): string
    {
        return 'delivered+'.Str::before($email, '@').'@'.self::TEST_INBOX_DOMAIN;
    }

    private function newPassword(): string
    {
        return Str::password(self::PASSWORD_LENGTH, symbols: false);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    private function report(array $rows): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->warn('Passwords — copy these NOW, they are not stored anywhere.');
        $this->command->warn('Dev Director and Dev Physician must change theirs at first login (D-35).');
        $this->command->table(['Account', 'Email', 'Password'], $rows);
        $this->command->newLine();
    }
}
