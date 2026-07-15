<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Flagged Anomalies CSV export (FR-ANL-06) — one row per tripped flag,
 * captured visits included (flags surface from capture, FR-ANL-07), on the
 * same flagged() scope as the screen; plus the format contract: UTF-8 BOM,
 * header row, dated filename, and formula-injection guarding of
 * user-entered cells.
 */
class CsvExportTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A student user with a profile of the given sex. */
    private function makeStudent(string $sex, array $userOverrides = [], array $profileOverrides = []): User
    {
        $student = User::factory()->create(array_merge(['role' => 'student'], $userOverrides));

        StudentProfile::factory()
            ->forCollege($this->ccs)
            ->create(array_merge(['user_id' => $student->id, 'sex' => $sex], $profileOverrides));

        return $student;
    }

    /**
     * Persist one visit with vitals — unflagged and in-range unless
     * overridden, exactly like the kiosk submit stores them.
     */
    private function makeVisit(
        College $college,
        User $student,
        array $vitalOverrides = [],
        string $status = 'captured',
        ?CarbonInterface $checkedInAt = null,
    ): ClinicVisit {
        static $seq = 9000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => $status,
            'checked_in_at' => $checkedInAt ?? now(),
        ]);

        VitalSigns::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 170.0,
            'weight_kg' => 65.0,
            'bmi' => 22.5,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 120,
            'bp_diastolic' => 80,
            'entry_method' => 'manual',
            'is_bp_flagged' => false,
            'is_temp_flagged' => false,
            'is_bmi_flagged' => false,
        ], $vitalOverrides));

        return $visit;
    }

    /** Encode a visit: clearance record + status flip, like FR-NRS-04. */
    private function encode(ClinicVisit $visit, array $categories = [], string $result = 'Fit'): ClearanceRecord
    {
        $visit->update(['status' => 'encoded']);

        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => $result,
            'encoded_at' => now(),
        ]);

        foreach ($categories as $category) {
            $record->caseCategories()->create(['case_category' => $category]);
        }

        return $record;
    }

    /** Parse a streamed CSV download into rows, asserting the BOM prefix. */
    private function csvRows(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $content, 'CSV must start with the UTF-8 BOM');

        $lines = explode("\n", rtrim(substr($content, strlen("\u{FEFF}"))));

        return array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
    }

    public function test_guests_and_other_roles_cannot_download_exports(): void
    {
        $this->get('/director/anomalies/export')->assertRedirect('/login');

        // The role middleware bounces other roles to their own home.
        $this->actingAs($this->nurse)->get('/director/anomalies/export')->assertRedirect();
    }

    public function test_anomalies_export_writes_one_row_per_tripped_flag(): void
    {
        $this->freezeTime();

        $student = $this->makeStudent(
            'M',
            userOverrides: ['name' => 'Juan Santos'],
            profileOverrides: ['student_number' => '2024300123'],
        );

        // Older visit trips TWO flags and is still captured — it exports
        // as two rows marked Encoded = No (flags surface from capture).
        $this->makeVisit($this->ccs, $student, [
            'is_bp_flagged' => true, 'bp_systolic' => 150, 'bp_diastolic' => 95,
            'is_temp_flagged' => true, 'temperature_c' => 38.4,
        ], checkedInAt: now()->subHour());

        // Newer visit: one flag, already encoded.
        $encoded = $this->makeVisit($this->cea, $student, ['is_bmi_flagged' => true, 'bmi' => 31.4]);
        $this->encode($encoded, ['Metabolic Endocrine System']);

        // Unflagged visit → never exported.
        $this->makeVisit($this->ccs, $student);

        $response = $this->actingAs($this->director)
            ->get('/director/anomalies/export')
            ->assertOk()
            ->assertDownload('healthpass-flagged-anomalies-'.now()->format('Ymd').'.csv');

        $hourAgo = now()->subHour()->format('Y-m-d H:i');

        // Newest visit first (same ordering as the on-screen table); the
        // double-flagged visit contributes one row PER flag, in the same
        // BP → Fever → BMI order as flagDetails().
        $this->assertSame([
            ['Student Number', 'Student', 'College', 'Flag', 'Value', 'Captured At', 'Encoded'],
            ['2024300123', 'Juan Santos', 'CEA', 'Abnormal BMI', '31.4', now()->format('Y-m-d H:i'), 'Yes'],
            ['2024300123', 'Juan Santos', 'CCS', 'High Blood Pressure', '150/95 mmHg', $hourAgo, 'No'],
            ['2024300123', 'Juan Santos', 'CCS', 'Fever', '38.4°C', $hourAgo, 'No'],
        ], $this->csvRows($response));
    }

    public function test_formula_like_cells_are_neutralized_against_csv_injection(): void
    {
        // Excel would EXECUTE a cell starting with "=" — the export must
        // prefix an apostrophe so it opens as plain text.
        $student = $this->makeStudent('F', userOverrides: ['name' => '=HYPERLINK("http://evil.test")']);
        $this->makeVisit($this->ccs, $student, ['is_temp_flagged' => true, 'temperature_c' => 38.4]);

        $rows = $this->csvRows(
            $this->actingAs($this->director)->get('/director/anomalies/export')->assertOk(),
        );

        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", $rows[1][1]);
    }
}
