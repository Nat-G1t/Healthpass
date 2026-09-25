<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use App\Support\Programs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * TESTING ONLY — give one student a kiosk appointment for TODAY, so the whole
 * kiosk flow can be walked on the Pi with a real ID card.
 *
 * An artisan command is a PHP class you run from the terminal
 * (`php artisan healthpass:kiosk-test-student 2020…`); Laravel finds every
 * class in app/Console/Commands on its own. It is used here instead of a
 * seeder because the student number is typed on the Pi, never committed —
 * CLAUDE.md forbids real student data in the repo.
 *
 *   php artisan healthpass:kiosk-test-student 20203300194
 *   php artisan healthpass:kiosk-test-student 20203300194 --form=assessment --sex=F
 *
 * What it writes, in the same shape Director approval does (D-61: students
 * never self-schedule, so the appointment hangs off an APPROVED CCS batch):
 *
 *   - the student, if that student number has no account yet: a CCS student
 *     whose qr_token IS the student number — exactly what linking a physical
 *     ID stores — so scanning the card's "IDNo:" line finds them. Email login
 *     works too: <student number>@kiosk.test / password.
 *   - one approved batch (a real BR- ref) with one appointment (a real APT-
 *     ref) today, in the current clinic hour.
 *
 * Re-run it after each finished kiosk visit: a submitted visit uses up the
 * day's appointment (Appointment::todayFor), and the next run books a fresh
 * one. While an open appointment exists it is reported, not duplicated.
 * Needs the seeded CCS college admin and Director (`php artisan db:seed`).
 */
class KioskTestStudent extends Command
{
    protected $signature = 'healthpass:kiosk-test-student
        {student_number : The number printed on the ID card (the QR\'s IDNo: line)}
        {--form=clearance : Which form the batch names — clearance or assessment (D-62)}
        {--sex=M : M or F — F also shows the pregnancy question (D-79)}';

    protected $description = 'TESTING: give a student a kiosk appointment for today (creates the student if needed)';

    public function handle(): int
    {
        $studentNumber = trim((string) $this->argument('student_number'));
        $form = (string) $this->option('form');
        $sex = strtoupper((string) $this->option('sex'));

        $problem = $this->invalidInput($studentNumber, $form, $sex);

        if ($problem !== null) {
            return $this->refuse($problem);
        }

        $ccs = College::where('code', 'CCS')->first();
        $admin = $ccs ? User::where('role', 'college_admin')->where('managed_college_id', $ccs->id)->orderBy('id')->first() : null;
        $director = User::where('role', 'director')->orderBy('id')->first();

        if ($ccs === null || $admin === null || $director === null) {
            return $this->refuse('The CCS college, its college admin and the Director must exist — run `php artisan db:seed` first.');
        }

        $profile = StudentProfile::where('student_number', $studentNumber)->first()
            ?? $this->createStudent($studentNumber, $ccs);

        if ($profile === null) {
            return $this->refuse("Another student's ID is already linked to {$studentNumber}.");
        }

        // Keeps --sex meaningful on a re-run, so both pregnancy paths can be tried.
        $profile->update(['sex' => $sex]);

        $open = Appointment::todayFor($profile->user_id);

        if ($open !== null) {
            $this->info("{$studentNumber} already has an open appointment today: {$open->reference_no} ({$open->formType()}, {$open->timeLabel()}).");

            return self::SUCCESS;
        }

        $appointment = $this->book($profile, $ccs, $admin, $director, $form);

        $this->info("Booked {$appointment->reference_no} for {$studentNumber} today at {$appointment->timeLabel()} ({$form}, sex {$sex}).");
        $this->line("Scan the ID card, or log in on the kiosk as {$profile->user->email} / password.");

        return self::SUCCESS;
    }

    /** The first thing wrong with the typed arguments, or null when all are fine. */
    private function invalidInput(string $studentNumber, string $form, string $sex): ?string
    {
        // Same rule the registration form applies to a student number.
        if (preg_match('/^[\d-]{1,20}$/', $studentNumber) !== 1) {
            return 'The student number may only contain digits and dashes (max 20).';
        }

        if (! array_key_exists($form, BatchRequest::REASONS_BY_FORM)) {
            return '--form must be clearance or assessment.';
        }

        return in_array($sex, ['M', 'F'], true) ? null : '--sex must be M or F.';
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** An approved batch of one, its appointment today and its roster row. */
    private function book(StudentProfile $profile, College $ccs, User $admin, User $director, string $form): Appointment
    {
        $refs = app(ReferenceNumberService::class);
        $slot = $this->currentSlot(app(ClinicScheduleService::class));

        return DB::transaction(function () use ($refs, $ccs, $admin, $director, $form, $slot, $profile) {
            $batch = BatchRequest::create([
                'reference_no' => $refs->generateBatchRef(),
                'college_id' => $ccs->id,
                'requested_by' => $admin->id,
                'form_type' => $form,
                // The form's first printed purpose — never 'others', which needs a detail.
                'reason' => array_key_first(BatchRequest::REASONS_BY_FORM[$form]),
                'service_type' => 'medical',
                'requested_date' => today()->toDateString(),
                'requested_time' => $slot,
                'requested_blocks' => 1,
                'scheduled_date' => today()->toDateString(),
                'status' => 'approved',
                'reviewed_by' => $director->id,
                'reviewed_at' => now(),
            ]);

            $appointment = Appointment::create([
                'reference_no' => $refs->generateAppointmentRef(),
                'student_id' => $profile->user_id,
                'service_type' => 'medical',
                'scheduled_date' => today()->toDateString(),
                'scheduled_time' => $slot,
                'status' => 'scheduled',
                'source' => 'batch',
                'batch_request_id' => $batch->id,
                'created_by' => $director->id,
            ]);

            BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $profile->user_id,
                'appointment_id' => $appointment->id,
            ]);

            return $appointment;
        });
    }

    /**
     * A CCS student whose ID is already linked (qr_token = student number).
     * Returns null when that number is already some other profile's qr_token.
     */
    private function createStudent(string $studentNumber, College $ccs): ?StudentProfile
    {
        if (StudentProfile::where('qr_token', $studentNumber)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($studentNumber, $ccs) {
            $user = User::create([
                'role' => 'student',
                'name' => 'Kiosk Tester',
                'email' => "{$studentNumber}@kiosk.test",
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'status' => 'active',
            ]);

            return StudentProfile::create([
                'user_id' => $user->id,
                'college_id' => $ccs->id,
                'student_number' => $studentNumber,
                'first_name' => 'Kiosk',
                'last_name' => 'Tester',
                'sex' => 'M',
                // Catalog values (D-42), so the account can still save its own profile.
                'course' => Programs::forCollege($ccs->id)[0],
                'year_level' => (string) array_key_first(Programs::yearLevelsForCollege($ccs->id)),
                'date_of_birth' => '2002-01-01',
                'place_of_birth' => 'City of San Fernando, Pampanga',
                'civil_status' => 'Single',
                'address' => 'Pampanga State University, Bacolor, Pampanga',
                'qr_token' => $studentNumber,
                'privacy_consent_at' => now(),
            ]);
        });
    }

    /**
     * The clinic hour it is right now, as a slot key; before opening it is the
     * first slot and after closing the last, so the test works at any hour.
     */
    private function currentSlot(ClinicScheduleService $schedule): string
    {
        $slots = $schedule->slots();
        $now = sprintf('%02d:00:00', now()->hour);

        if ($now < $slots[0]) {
            return $slots[0];
        }

        return in_array($now, $slots, true) ? $now : end($slots);
    }
}
