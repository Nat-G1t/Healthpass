<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff accounts (FR-AUTH-05): `nurse`, `college_admin`, and `director` are
 * created HERE, never by self-registration — there is no public staff
 * registration path, and D-35 rejected adding one.
 *
 * Two modes, chosen by HEALTHPASS_SEED_STAFF_ONE_TIME:
 *
 *  - false (default) — local/dev: the familiar shared `password` and
 *    @healthpass.test addresses, no forced change. Keeps `docs/dev-notes.md`
 *    and the test suite working unchanged.
 *  - true — hosted internet deploy (D-34/D-35): real email domain, a distinct
 *    random one-time password per account, and `must_change_password` set so
 *    each staff member must replace it through the OTP-confirmed change-password
 *    flow before they can use the app. The passwords are printed ONCE and never
 *    stored anywhere in plaintext.
 */
class StaffSeeder extends Seeder
{
    /** Length of a generated one-time password. */
    private const ONE_TIME_PASSWORD_LENGTH = 16;

    public function run(): void
    {
        // Read through config(), never env(): once `php artisan config:cache` has
        // run on the server, env() reads empty and this would silently fall back
        // to the dev shared password on the public deploy.
        $oneTime = (bool) config('healthpass.seed_staff.one_time_passwords');
        $domain = (string) config('healthpass.seed_staff.email_domain');

        /** @var list<array{name: string, email: string, password: string}> $issued */
        $issued = [];

        // Director — sees all colleges, no managed_college_id
        $issued[] = $this->createStaff([
            'role' => 'director',
            'name' => 'Clinic Director',
            'email' => 'director@'.$domain,
        ], $oneTime);

        // Nurse — operates the Live Queue and Encode Result screens
        $issued[] = $this->createStaff([
            'role' => 'nurse',
            'name' => 'Head Nurse',
            'email' => 'nurse@'.$domain,
        ], $oneTime);

        // One college admin per college — each scoped to their college
        // (FR-AUTH-06). 11 units since D-43 removed Senior High School.
        $adminColleges = [
            'COE' => 'COE Administrator',
            'CEA' => 'CEA Administrator',
            'CBS' => 'CBS Administrator',
            'CAS' => 'CAS Administrator',
            'CSSP' => 'CSSP Administrator',
            'CCS' => 'CCS Administrator',
            'CHTM' => 'CHTM Administrator',
            'CIT' => 'CIT Administrator',
            'LAW' => 'LAW Administrator',
            'GS' => 'GS Administrator',
            'LHS' => 'LHS Administrator',
        ];

        foreach ($adminColleges as $code => $name) {
            $college = College::where('code', $code)->firstOrFail();

            $issued[] = $this->createStaff([
                'role' => 'college_admin',
                'name' => $name,
                'email' => 'admin.'.strtolower($code).'@'.$domain,
                'managed_college_id' => $college->id,
            ], $oneTime);
        }

        if ($oneTime) {
            $this->reportOneTimePasswords($issued);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{name: string, email: string, password: string}
     */
    private function createStaff(array $attributes, bool $oneTime): array
    {
        $password = $oneTime
            ? Str::password(self::ONE_TIME_PASSWORD_LENGTH, symbols: false)
            : 'password';

        User::create([
            ...$attributes,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'status' => 'active',
            'must_change_password' => $oneTime,
        ]);

        return [
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $password,
        ];
    }

    /**
     * Print the generated credentials once. This is the ONLY time they exist in
     * readable form — nothing writes them to a file or the database.
     *
     * @param  list<array{name: string, email: string, password: string}>  $issued
     */
    private function reportOneTimePasswords(array $issued): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->warn('One-time staff passwords — copy these NOW, they are not stored anywhere.');
        $this->command->warn('Each account must change its password at first login (D-35).');
        $this->command->table(
            ['Name', 'Email', 'One-time password'],
            array_map(static fn (array $row): array => [$row['name'], $row['email'], $row['password']], $issued)
        );
        $this->command->newLine();
    }
}
