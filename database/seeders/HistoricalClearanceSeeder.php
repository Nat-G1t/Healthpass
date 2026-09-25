<?php

namespace Database\Seeders;

use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Programs;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DEMO ONLY — five years of clearance history (2021–2025) so the College
 * Admin's Yearly Clearance Report (FR-ADM-13, D-81) has rows for every year
 * its picker offers. Requested for the defense site; runs after
 * DatabaseSeeder (it needs the demo director, college admins and nurse).
 *
 * Per college and year: 40–50 ENCODED clearances, drawn from a pool of 60
 * GRADUATED students — inactive accounts, so they cannot log in and are left
 * off every new batch's roster. A graduate is cleared at most once a year,
 * and most appear in several years, as a real student would.
 *
 * Like every demo visit since D-61, each one sits on an approved batch its
 * own college submitted, so the report's batch-scope rule counts it. All are
 * encoded by the demo nurse, so the printed physician block stays blank
 * (D-64) — the Dev Physician never signs a record from before their time.
 *
 * Deterministic, no randomness in anything the report shows. The BR-/APT-/
 * HP-2021…2025 reference bands are free: HealthPass has no data before 2026.
 */
class HistoricalClearanceSeeder extends DemoClinicVisitSeeder
{
    private const FIRST_YEAR = 2021;

    private const LAST_YEAR = 2025;

    private const GRADUATES_PER_COLLEGE = 60;

    /** Clearances per college per year run from this to this + 10. */
    private const MIN_PER_YEAR = 40;

    /** A batch never outgrows one clinic hour (hourly capacity, D-37). */
    private const MAX_PER_BATCH = 12;

    /** One month per batch of the year, in order. */
    private const BATCH_MONTHS = [2, 3, 6, 8, 10];

    private const FEMALE_NAMES = [
        'Andrea', 'Bea', 'Charmaine', 'Danica', 'Elaine', 'Faith', 'Grace', 'Hannah',
        'Irish', 'Joanna', 'Katrina', 'Lea', 'Mae', 'Nicole', 'Olivia', 'Pauline',
        'Queenie', 'Rochelle', 'Samantha', 'Trisha', 'Ursula', 'Vanessa', 'Wendy',
    ];

    private const MALE_NAMES = [
        'Adrian', 'Benedict', 'Cedric', 'Dominic', 'Emmanuel', 'Francis', 'Gabriel',
        'Harold', 'Ivan', 'Jerome', 'Kevin', 'Lorenzo', 'Manuel', 'Nathan', 'Oliver',
        'Patrick', 'Quintin', 'Rafael', 'Samuel', 'Timothy', 'Vincent', 'Warren', 'Xavier',
    ];

    private const SURNAMES = [
        'Aguilar', 'Bernardo', 'Canlas', 'Dizon', 'Enriquez', 'Figueroa', 'Galang',
        'Herrera', 'Ignacio', 'Jimenez', 'Lacson', 'Manansala', 'Navarro', 'Ordonez',
        'Pineda', 'Quiambao', 'Salonga', 'Tiglao', 'Valdez', 'Yabut', 'Zapata',
        'Bondoc', 'Cunanan', 'David', 'Espiritu', 'Gomez', 'Lansangan', 'Mallari',
        'Nunag', 'Pangilinan', 'Sicat', 'Tuazon', 'Vergara', 'Yumul', 'Miranda',
        'Macapagal', 'Serrano',
    ];

    /** @var array<int, array{batch: int, appointment: int, visit: int}> year => last number used */
    private array $sequence = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (BatchRequest::where('reference_no', 'like', 'BR-'.self::FIRST_YEAR.'-%')->exists()) {
            $this->command?->info('HistoricalClearanceSeeder: history already exists, skipping.');

            return;
        }

        $this->director = User::where('role', 'director')->orderBy('id')->firstOrFail();
        $this->admins = User::where('role', 'college_admin')->orderByDesc('id')->get()->keyBy('managed_college_id');
        $nurse = User::where('role', 'nurse')->orderBy('id')->firstOrFail();

        $visits = 0;

        DB::transaction(function () use ($nurse, &$visits): void {
            // Graduates never log in, so one unknown password serves them all.
            $passwordHash = Hash::make(Str::random(40));

            foreach (College::orderBy('id')->get()->values() as $collegeIndex => $college) {
                $graduates = $this->graduatesOf($college, $collegeIndex, $passwordHash);

                for ($year = self::FIRST_YEAR; $year <= self::LAST_YEAR; $year++) {
                    $yearIndex = $year - self::FIRST_YEAR;
                    $count = self::MIN_PER_YEAR + (($collegeIndex * 7 + $yearIndex * 3) % 11);

                    // Rotate through the pool so each year clears a different,
                    // overlapping cohort — never the same student twice a year.
                    $cohort = array_map(
                        fn (int $i): User => $graduates[($yearIndex * 12 + $i) % self::GRADUATES_PER_COLLEGE],
                        range(0, $count - 1),
                    );

                    foreach (array_chunk($cohort, self::MAX_PER_BATCH) as $batchIndex => $roster) {
                        $batch = $this->approvedBatch(
                            reference: sprintf('BR-%d-%03d', $year, $this->next($year, 'batch')),
                            college: $college,
                            date: $this->clinicDate($year, $batchIndex, $collegeIndex),
                            slot: sprintf('%02d:00:00', 8 + ($collegeIndex % 8)),
                            studentCount: count($roster),
                            reason: self::DEMO_REASONS[($collegeIndex + $yearIndex + $batchIndex) % count(self::DEMO_REASONS)],
                        );

                        foreach ($roster as $seat => $student) {
                            $this->historicalVisit($batch, $student, $college, $nurse, $year, $seat, $visits++);
                        }
                    }
                }
            }
        });

        $this->command?->info(sprintf(
            'HistoricalClearanceSeeder: %d graduates and %d encoded clearances across %d–%d.',
            College::count() * self::GRADUATES_PER_COLLEGE,
            $visits,
            self::FIRST_YEAR,
            self::LAST_YEAR,
        ));
    }

    /**
     * The college's pool of graduated students: inactive accounts with a
     * profile in one of the college's own programs (D-42).
     *
     * @return list<User>
     */
    private function graduatesOf(College $college, int $collegeIndex, string $passwordHash): array
    {
        $programs = Programs::forCollege($college->id);
        $graduates = [];

        for ($i = 0; $i < self::GRADUATES_PER_COLLEGE; $i++) {
            $isFemale = ($i + $collegeIndex) % 2 === 0;
            $names = $isFemale ? self::FEMALE_NAMES : self::MALE_NAMES;
            $first = $names[($i * 7 + $collegeIndex * 3) % count($names)];
            $last = self::SURNAMES[($i * 11 + $collegeIndex * 5) % count(self::SURNAMES)];
            $middle = self::SURNAMES[($i * 13 + $collegeIndex * 2 + 7) % count(self::SURNAMES)];
            $entryYear = 2016 + intdiv($i, 12); // five entering classes, 2016–2020

            $user = User::create([
                'role' => 'student',
                'name' => "{$first} {$last}",
                'email' => Str::lower("{$first}.{$last}.".sprintf('%02d%03d', $collegeIndex + 1, $i + 1).'@alumni.healthpass.test'),
                'email_verified_at' => Carbon::create($entryYear, 6, 1),
                'password' => $passwordHash,
                'status' => 'inactive',
            ]);

            StudentProfile::factory()->forCollege($college)->create([
                'user_id' => $user->id,
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'sex' => $isFemale ? 'F' : 'M',
                // YYYY9CCNNN — the 9 keeps graduates clear of real numbers.
                'student_number' => sprintf('%d9%02d%03d', $entryYear, $collegeIndex + 1, $i + 1),
                'course' => $programs === [] ? 'Not specified' : $programs[$i % count($programs)],
                'year_level' => '4',
                'date_of_birth' => Carbon::create($entryYear - 18, 1 + $i % 12, 1 + $i % 28)->toDateString(),
                'qr_token' => Str::random(32),
            ]);

            $graduates[] = $user->load('studentProfile:id,user_id,course');
        }

        return $graduates;
    }

    /** A weekday in the batch's month, spread so colleges rarely share a day. */
    private function clinicDate(int $year, int $batchIndex, int $collegeIndex): Carbon
    {
        $date = Carbon::create(
            $year,
            self::BATCH_MONTHS[$batchIndex % count(self::BATCH_MONTHS)],
            5 + (($collegeIndex * 3 + $batchIndex * 5 + $year) % 20),
        );

        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * One encoded clearance on $batch, built the way the analytics spread
     * builds its own (DemoClinicVisitSeeder::createSpreadMedicalVisit) but
     * numbered in the visit's own year.
     */
    private function historicalVisit(
        BatchRequest $batch,
        User $student,
        College $college,
        User $nurse,
        int $year,
        int $seat,
        int $n,
    ): void {
        $checkedIn = $batch->scheduled_date->copy()
            ->setTimeFromTimeString($batch->requested_time)
            ->addMinutes($seat * 5);

        $appointment = $this->seat(
            $batch,
            $student,
            sprintf('APT-%d-%04d', $year, $this->next($year, 'appointment')),
            'completed',
        );

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-%d-%04d', $year, $this->next($year, 'visit')),
            'student_id' => $student->id,
            'college_id' => $college->id,                 // capture-time snapshot (D-17)
            'course' => $student->studentProfile?->course, // program snapshot (D-43)
            'appointment_id' => $appointment->id,
            'login_method' => $n % 4 === 0 ? 'email' : 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => $checkedIn->copy()->subMinutes(5),
            'checked_in_at' => $checkedIn,
        ]);

        $this->createSpreadVitalsAndScreening($visit, $n, $batch->form_type);

        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            ...$this->encodedVitals($visit, $n % 19 === 5 ? 26 : 12 + ($n % 9)),
            'result' => $n % 6 === 5 ? 'Unfit' : 'Fit',
            ...$visit->batchPurpose(),
            ...ClearanceRecord::physicianBlockFor($nurse), // D-64: nurse → blank block
            'encoded_at' => $checkedIn->copy()->addHours(2),
        ]);

        $this->medicalAssessment($record, $batch->form_type, $n);
    }

    /** The next reference number of this kind in $year (1, 2, 3 …). */
    private function next(int $year, string $kind): int
    {
        $this->sequence[$year] ??= ['batch' => 0, 'appointment' => 0, 'visit' => 0];

        return ++$this->sequence[$year][$kind];
    }
}
