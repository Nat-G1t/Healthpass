<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan healthpass:kiosk-test-student` — the testing command that gives
 * one student a kiosk appointment for today. Every assertion goes through the
 * real kiosk scan endpoint, so "the command worked" means "the kiosk lets this
 * ID card through to the right screens".
 */
class KioskTestStudentCommandTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT_NO = '20200000001';

    private function seedStaff(): College
    {
        $ccs = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $ccs->id]);
        User::factory()->create(['role' => 'director']);

        return $ccs;
    }

    /** Scan the ID card's IDNo line the way the USB scanner types it. */
    private function scanCard(): array
    {
        return $this->postJson(route('kiosk.scan'), ['token' => 'IDNo: '.self::STUDENT_NO])
            ->assertOk()
            ->json('identity');
    }

    public function test_it_creates_the_student_and_the_kiosk_lets_their_card_through(): void
    {
        $this->seedStaff();

        $this->artisan('healthpass:kiosk-test-student', ['student_number' => self::STUDENT_NO])
            ->assertSuccessful();

        $identity = $this->scanCard();
        $this->assertTrue($identity['hasAppointmentToday']);
        $this->assertSame('clearance', $identity['formType']);
        $this->assertFalse($identity['isFemale']);

        // The appointment hangs off an approved batch, as Director approval makes it (D-61).
        $appointment = Appointment::sole();
        $this->assertSame('batch', $appointment->source);
        $this->assertSame('approved', $appointment->batchRequest->status);
        $this->assertMatchesRegularExpression('/^APT-\d{4}-\d{4}$/', $appointment->reference_no);
    }

    public function test_the_form_and_sex_options_pick_the_kiosk_screens(): void
    {
        $this->seedStaff();

        $this->artisan('healthpass:kiosk-test-student', [
            'student_number' => self::STUDENT_NO, '--form' => 'assessment', '--sex' => 'F',
        ])->assertSuccessful();

        $identity = $this->scanCard();
        $this->assertSame('assessment', $identity['formType']);
        $this->assertTrue($identity['isFemale']);
    }

    public function test_a_re_run_with_an_open_appointment_books_nothing_new(): void
    {
        $this->seedStaff();

        $this->artisan('healthpass:kiosk-test-student', ['student_number' => self::STUDENT_NO])->assertSuccessful();
        $this->artisan('healthpass:kiosk-test-student', ['student_number' => self::STUDENT_NO])->assertSuccessful();

        $this->assertSame(1, Appointment::count());
        $this->assertSame(1, BatchRequest::count());
        $this->assertSame(1, StudentProfile::count());
    }

    public function test_after_a_finished_visit_a_re_run_books_a_fresh_appointment(): void
    {
        $ccs = $this->seedStaff();
        $this->artisan('healthpass:kiosk-test-student', ['student_number' => self::STUDENT_NO])->assertSuccessful();

        $first = Appointment::sole();
        ClinicVisit::create([
            'reference_no' => 'HP-2026-0001',
            'student_id' => $first->student_id,
            'college_id' => $ccs->id,
            'appointment_id' => $first->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);
        $this->assertFalse($this->scanCard()['hasAppointmentToday']);

        $this->artisan('healthpass:kiosk-test-student', ['student_number' => self::STUDENT_NO])->assertSuccessful();

        $this->assertTrue($this->scanCard()['hasAppointmentToday']);
        $this->assertSame(2, Appointment::count());
    }

    public function test_it_refuses_without_the_seeded_staff(): void
    {
        College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        $this->artisan('healthpass:kiosk-test-student', ['student_number' => self::STUDENT_NO])
            ->assertFailed();

        $this->assertSame(0, StudentProfile::count());
    }

    public function test_it_refuses_a_bad_student_number(): void
    {
        $this->seedStaff();

        $this->artisan('healthpass:kiosk-test-student', ['student_number' => 'abc; drop'])
            ->assertFailed();

        $this->assertSame(0, StudentProfile::count());
    }
}
