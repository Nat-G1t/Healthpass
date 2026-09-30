<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Programs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DEMO ONLY — top a college up to N ACTIVE students, so a College Admin can
 * build batches big enough to show hour spans and schedule conflicts at the
 * defense (D-98).
 *
 *   php artisan healthpass:demo-students CCS CIT            (30 each)
 *   php artisan healthpass:demo-students CCS --target=40
 *
 * It only ADDS students: a college already at or above the target is left
 * alone, and nobody is ever edited or deleted — real registrations count
 * toward the target. Safe to re-run.
 *
 * Each demo student:
 *   - is an ACTIVE account, so it appears in the New Batch Request picker;
 *   - has a Resend TEST address (delivered+ccs-demo-01@resend.dev): approving
 *     a batch emails every student (FR-STU-12), and these are accepted and
 *     marked delivered instead of bouncing. Each one still counts as a send
 *     against the Resend plan's daily limit;
 *   - has a random password nobody knows — these accounts are for the roster,
 *     not for logging in;
 *   - has a student number starting 20268 (2026, 8, college id, sequence), a
 *     pattern no real PSU number uses, so they are easy to find later.
 */
class DemoStudents extends Command
{
    protected $signature = 'healthpass:demo-students
        {colleges* : College codes to top up, e.g. CCS CIT}
        {--target=30 : How many ACTIVE students each college should end up with}';

    protected $description = 'DEMO: top colleges up to a number of active students (adds only, never deletes)';

    private const FIRST_NAMES_MALE = [
        'Juan', 'Jose', 'Mark', 'John Paul', 'Christian', 'Carlo', 'Miguel', 'Joshua',
        'Angelo', 'Kenneth', 'Paolo', 'Rafael', 'Adrian', 'Jericho', 'Francis',
    ];

    private const FIRST_NAMES_FEMALE = [
        'Maria', 'Angelica', 'Kristine', 'Camille', 'Patricia', 'Nicole', 'Bea',
        'Jasmine', 'Andrea', 'Czarina', 'Hazel', 'Mikaela', 'Sofia', 'Janine', 'Trisha',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Dizon', 'Lacson',
        'Manalo', 'David', 'Pineda', 'Canlas', 'Tolentino', 'Yabut', 'Sicat', 'Gomez',
        'Salenga', 'Mercado', 'Lusung', 'Tuazon',
    ];

    public function handle(): int
    {
        $target = (int) $this->option('target');

        if ($target < 1 || $target > 500) {
            $this->error('--target must be between 1 and 500.');

            return self::FAILURE;
        }

        foreach ((array) $this->argument('colleges') as $code) {
            $college = College::where('code', strtoupper((string) $code))->first();

            if ($college === null) {
                $this->error("No college with code {$code}.");

                return self::FAILURE;
            }

            $active = $this->activeCount($college);
            $missing = max(0, $target - $active);

            for ($i = 0; $i < $missing; $i++) {
                $this->createStudent($college);
            }

            $this->info("{$college->code}: {$active} active before, added {$missing}, now {$this->activeCount($college)}.");
        }

        return self::SUCCESS;
    }

    /** The count the admin dashboard and the batch picker show (D-98). */
    private function activeCount(College $college): int
    {
        return $college->studentProfiles()
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->count();
    }

    private function createStudent(College $college): void
    {
        $sequence = $this->nextFreeSequence($college);
        $isFemale = $sequence % 2 === 0;
        $names = $isFemale ? self::FIRST_NAMES_FEMALE : self::FIRST_NAMES_MALE;
        $firstName = $names[$sequence % count($names)];
        $lastName = self::LAST_NAMES[($sequence * 7) % count(self::LAST_NAMES)];
        $programs = Programs::forCollege($college->id);
        $yearLevels = Programs::yearLevelKeys($college->id);

        DB::transaction(function () use ($college, $sequence, $isFemale, $firstName, $lastName, $programs, $yearLevels) {
            $user = User::create([
                'role' => 'student',
                'name' => "{$firstName} {$lastName}",
                'email' => $this->email($college, $sequence),
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            StudentProfile::create([
                'user_id' => $user->id,
                'college_id' => $college->id,
                'student_number' => $this->studentNumber($college, $sequence),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'sex' => $isFemale ? 'F' : 'M',
                // Catalog values (D-42), spread across the college's programs.
                'course' => $programs === [] ? 'Not specified' : $programs[$sequence % count($programs)],
                'year_level' => (string) ($yearLevels === [] ? '1' : $yearLevels[$sequence % count($yearLevels)]),
                'date_of_birth' => sprintf('%d-%02d-%02d', 2003 + $sequence % 4, 1 + $sequence % 12, 1 + $sequence % 28),
                'place_of_birth' => 'City of San Fernando, Pampanga',
                'civil_status' => 'Single',
                'address' => 'Bacolor, Pampanga',
                'qr_token' => Str::random(64),
                'privacy_consent_at' => now(),
            ]);
        });
    }

    /** The first sequence whose student number AND email are both unused. */
    private function nextFreeSequence(College $college): int
    {
        $sequence = 1;

        while (
            StudentProfile::where('student_number', $this->studentNumber($college, $sequence))->exists()
            || User::where('email', $this->email($college, $sequence))->exists()
        ) {
            $sequence++;
        }

        return $sequence;
    }

    private function studentNumber(College $college, int $sequence): string
    {
        return sprintf('20268%02d%03d', $college->id, $sequence);
    }

    private function email(College $college, int $sequence): string
    {
        return sprintf('delivered+%s-demo-%02d@resend.dev', Str::lower($college->code), $sequence);
    }
}
