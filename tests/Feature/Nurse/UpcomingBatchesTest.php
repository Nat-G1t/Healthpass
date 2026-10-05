<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * The clinic's Upcoming Batches page (FR-NRS-12, D-114) — nurse and physician.
 *
 * Every college's APPROVED batches in two cards: Upcoming (a clinic day today
 * or later — the first day or a 2nd-chance day, D-110 — soonest first) and
 * Past (the rest, newest first). View opens the D-111 results page with a
 * Print and a PDF link on every encoded row and D-113's select & download,
 * always on. The ZIP goes through BatchDocumentZip, so the clinic is held to
 * the same 422 rule as the college and the Director.
 *
 * The fixture's clock: "now" is Friday Sep 11, 2026, 10 AM; the fixture's
 * batches run on Thursday Sep 10 (yesterday) unless a test moves them.
 */
class UpcomingBatchesTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    private const TODAY = '2026-09-11';

    private const TOMORROW = '2026-09-12';

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();

        $this->physician = User::factory()->create(['role' => 'physician']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Move $batch — and every appointment of its first day — to $date at $time.
     * One model at a time, never a bulk query update: like every place the app
     * writes an appointment, that goes through the `date` cast, so SQLite
     * stores every date in one format and ORDER BY compares like with like.
     */
    private function moveTo(BatchRequest $batch, string $date, string $time = '09:00:00'): BatchRequest
    {
        $batch->update(['scheduled_date' => $date, 'scheduled_time' => $time]);
        $batch->appointments()->get()->each->update(['scheduled_date' => $date, 'scheduled_time' => $time]);

        return $batch->fresh();
    }

    /** An approved batch of $college (CCS by default) with $students booked students on $date at $time. */
    private function batchOn(string $date, string $time = '09:00:00', int $students = 1, ?College $college = null): BatchRequest
    {
        $batch = $this->makeBatch(college: $college);

        // A for loop, not range(1, $students): range(1, 0) is [1, 0].
        for ($i = 0; $i < $students; $i++) {
            $this->addStudent($batch, 'booked');
        }

        return $this->moveTo($batch, $date, $time);
    }

    /** D-110: book $original's 2nd chance on $date and re-point the roster row, as the scheduler does. */
    private function secondChance(Appointment $original, string $date, string $time = '07:00:00'): Appointment
    {
        $secondChance = Appointment::factory()->create([
            'student_id' => $original->student_id,
            'scheduled_date' => $date,
            'scheduled_time' => $time,
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $original->batch_request_id,
            'rescheduled_from_id' => $original->id,
        ]);

        BatchRequestStudent::where('appointment_id', $original->id)->update(['appointment_id' => $secondChance->id]);

        return $secondChance;
    }

    private function indexPage(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('nurse.batches.index', $query))->assertOk();
    }

    /** @return list<string> the Batch IDs a card lists, in order */
    private function listed(TestResponse $response, string $card): array
    {
        return $response->viewData($card)->pluck('reference_no')->all();
    }

    private function download(User $user, BatchRequest $batch, mixed $visitIds): TestResponse
    {
        return $this->actingAs($user)->post(route('nurse.batches.documents', $batch), ['visits' => $visitIds]);
    }

    // ── Who sees it ──────────────────────────────────────────────────────────

    public function test_nurse_and_physician_get_the_menu_item_right_after_live_queue(): void
    {
        foreach ([$this->nurse, $this->physician] as $user) {
            $this->actingAs($user)->get(route('nurse.dashboard'))
                ->assertOk()
                ->assertSee('Upcoming Batches')
                ->assertSeeInOrder([
                    route('nurse.queue'),
                    route('nurse.batches.index'),
                    route('nurse.kiosk-devices'),
                ], false);

            $this->indexPage($user)->assertSee('Upcoming Batches');
        }
    }

    public function test_no_other_role_gets_the_menu_item(): void
    {
        $student = $this->addStudent($this->makeBatch(), 'booked')->student;

        $pages = [
            [$this->admin, route('admin.dashboard')],
            [$this->director, route('director.dashboard')],
            [$student, route('student.dashboard')],
        ];

        foreach ($pages as [$user, $url]) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertDontSee(route('nurse.batches.index'));
        }
    }

    /**
     * The clinic routes sit behind `role:nurse,physician`, which sends any
     * other signed-in role back to its own dashboard (EnsureRole) — the
     * same answer every clinic page gives. None of them gets the page or a ZIP.
     */
    public function test_only_clinic_staff_can_open_the_pages_or_download(): void
    {
        $batch = $this->makeBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $student = $visit->student;

        $pages = [route('nurse.batches.index'), route('nurse.batches.results', $batch)];
        $others = [
            [$this->admin, '/admin/dashboard'],
            [$this->director, '/director/dashboard'],
            [$student, '/student/dashboard'],
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertRedirect(route('login'));

            foreach ($others as [$user, $home]) {
                $this->actingAs($user)->get($url)->assertRedirect($home);
            }

            $this->actingAs($this->nurse)->get($url)->assertOk();
            $this->actingAs($this->physician)->get($url)->assertOk();
            auth()->logout();
        }

        foreach ($others as [$user, $home]) {
            $response = $this->download($user, $batch, [$visit->id])->assertRedirect($home);
            $this->assertNotSame('application/zip', $response->headers->get('Content-Type'));
        }
    }

    // ── The two cards ────────────────────────────────────────────────────────

    public function test_upcoming_lists_every_batch_with_a_clinic_day_from_today_soonest_first(): void
    {
        $later = $this->batchOn('2026-09-15');
        $tomorrow = $this->batchOn(self::TOMORROW, '09:00:00');
        $today = $this->batchOn(self::TODAY, '13:00:00');

        // Yesterday's batch whose missed student comes back tomorrow at 7 AM
        // (D-110) — earlier than the batch dated tomorrow at 9 AM.
        $missed = $this->makeBatch();
        $this->addStudent($missed, 'Fit');
        $this->secondChance($this->addStudent($missed, 'booked'), self::TOMORROW, '07:00:00');

        $response = $this->indexPage($this->nurse);

        $this->assertSame([
            $today->reference_no,
            $missed->reference_no,
            $tomorrow->reference_no,
            $later->reference_no,
        ], $this->listed($response, 'upcomingBatches'));
        $this->assertSame([], $this->listed($response, 'pastBatches'));

        // The 2nd-chance day shows under the batch's own first date.
        $response->assertSee('data-batches-card="upcoming"', false)
            ->assertSee('data-second-chance', false)
            ->assertSee('2nd chance: Sep 12, 2026, 7:00 AM');
    }

    public function test_past_lists_the_rest_newest_clinic_date_first(): void
    {
        $older = $this->batchOn('2026-09-01');
        $newest = $this->batchOn('2026-09-10');
        $middle = $this->batchOn('2026-09-05');

        // A 2nd chance that is itself over keeps the batch in Past.
        $secondChanceOver = $this->batchOn('2026-09-03');
        $this->secondChance($secondChanceOver->appointments()->first(), '2026-09-04');

        $response = $this->indexPage($this->physician);

        $this->assertSame([
            $newest->reference_no,
            $middle->reference_no,
            $secondChanceOver->reference_no,
            $older->reference_no,
        ], $this->listed($response, 'pastBatches'));
        $this->assertSame([], $this->listed($response, 'upcomingBatches'));
        $response->assertSee('No upcoming batches.');
    }

    public function test_only_approved_batches_are_ever_listed(): void
    {
        $approved = $this->batchOn(self::TOMORROW);

        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->makeBatch(status: $status)->update(['scheduled_date' => self::TOMORROW, 'scheduled_time' => '09:00:00']);
            $this->makeBatch(status: $status)->update(['scheduled_date' => '2026-09-01', 'scheduled_time' => '09:00:00']);
        }

        $response = $this->indexPage($this->nurse);

        $this->assertSame([$approved->reference_no], $this->listed($response, 'upcomingBatches'));
        $this->assertSame([], $this->listed($response, 'pastBatches'));
    }

    public function test_each_card_has_its_own_search_and_college_dropdown(): void
    {
        $this->batchOn(self::TOMORROW);
        $this->batchOn('2026-09-01');

        $this->indexPage($this->nurse)
            ->assertSee('data-card-filters="upcoming"', false)
            ->assertSee('data-card-filters="past"', false)
            ->assertSee('name="upcoming_q"', false)
            ->assertSee('name="upcoming_college"', false)
            ->assertSee('name="past_q"', false)
            ->assertSee('name="past_college"', false)
            ->assertSeeInOrder(['All colleges', 'CCS', 'COE'], false);
    }

    public function test_each_cards_batch_id_search_filters_that_card_only(): void
    {
        $upcoming = $this->batchOn(self::TOMORROW);
        $otherUpcoming = $this->batchOn(self::TOMORROW);
        $past = $this->batchOn('2026-09-01');
        $otherPast = $this->batchOn('2026-09-02');

        $response = $this->indexPage($this->nurse, ['upcoming_q' => $upcoming->reference_no]);
        $this->assertSame([$upcoming->reference_no], $this->listed($response, 'upcomingBatches'));
        $this->assertSame([$otherPast->reference_no, $past->reference_no], $this->listed($response, 'pastBatches'));

        $response = $this->indexPage($this->nurse, ['past_q' => $past->reference_no]);
        $this->assertSame([$upcoming->reference_no, $otherUpcoming->reference_no], $this->listed($response, 'upcomingBatches'));
        $this->assertSame([$past->reference_no], $this->listed($response, 'pastBatches'));

        $this->indexPage($this->nurse, ['upcoming_q' => 'BR-1999'])
            ->assertSee('No batch here matches these filters.');
    }

    public function test_each_cards_college_dropdown_filters_that_card_only(): void
    {
        $ccsUpcoming = $this->batchOn(self::TOMORROW);
        $coeUpcoming = $this->batchOn(self::TOMORROW, college: $this->coe);
        $ccsPast = $this->batchOn('2026-09-01');
        $coePast = $this->batchOn('2026-09-02', college: $this->coe);

        $response = $this->indexPage($this->physician, ['upcoming_college' => $this->coe->id]);
        $this->assertSame([$coeUpcoming->reference_no], $this->listed($response, 'upcomingBatches'));
        $this->assertSame([$coePast->reference_no, $ccsPast->reference_no], $this->listed($response, 'pastBatches'));

        // Both cards filtered at once, each by its own college.
        $response = $this->indexPage($this->physician, ['upcoming_college' => $this->ccs->id, 'past_college' => $this->coe->id]);
        $this->assertSame([$ccsUpcoming->reference_no], $this->listed($response, 'upcomingBatches'));
        $this->assertSame([$coePast->reference_no], $this->listed($response, 'pastBatches'));
    }

    public function test_each_card_pages_at_ten_on_its_own_page_parameter(): void
    {
        foreach (range(1, 11) as $i) {
            $this->batchOn(self::TOMORROW, students: 0);
        }
        $past = $this->batchOn('2026-09-01', students: 0);

        $response = $this->indexPage($this->nurse);
        $this->assertCount(10, $response->viewData('upcomingBatches'));
        $this->assertSame(11, $response->viewData('upcomingBatches')->total());
        $response->assertSee('upcoming_page=2', false);

        // Page 2 of Upcoming leaves Past on its first page.
        $response = $this->indexPage($this->nurse, ['upcoming_page' => 2]);
        $this->assertCount(1, $response->viewData('upcomingBatches'));
        $this->assertSame([$past->reference_no], $this->listed($response, 'pastBatches'));
    }

    public function test_the_list_does_not_query_per_student(): void
    {
        $upcoming = $this->batchOn(self::TOMORROW);
        $past = $this->batchOn('2026-09-01');

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $this->indexPage($this->nurse);

            return $queries;
        };

        // Warm up: the first request stamps last_active_at and caches a lookup.
        $this->indexPage($this->nurse);
        $withOne = $count();

        foreach ([$upcoming, $past] as $batch) {
            foreach (range(1, 4) as $i) {
                $this->addStudent($batch, 'booked');
            }
            $this->moveTo($batch, $batch->scheduled_date->toDateString());
        }

        $this->assertSame($withOne, $count());
    }

    // ── The results page ─────────────────────────────────────────────────────

    public function test_print_and_pdf_links_sit_only_on_encoded_rows(): void
    {
        $batch = $this->makeBatch();
        $fit = $this->visitOf($this->addStudent($batch, 'Fit', 'Ana Abad'));
        $unfit = $this->visitOf($this->addStudent($batch, 'Unfit', 'Ben Bueno'));
        $this->addStudent($batch, 'in_clinic', 'Cara Cruz');
        $this->addStudent($batch, 'resting', 'Dan Diaz');
        $this->addStudent($batch, 'booked', 'Eva Estrada');

        $response = $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertOk()
            ->assertSee('Batch '.$batch->reference_no)
            ->assertSee('College of Computing Studies')
            ->assertSee(route('nurse.batches.index'))
            ->assertSee('Document')
            ->assertSee('data-row-print', false)
            ->assertSee('data-row-pdf', false);

        $rows = collect($response->viewData('results')['rows'])->keyBy('name');

        // Print is the encode page's Reprint (FR-NRS-05); PDF its Save as PDF.
        foreach (['Ana Abad' => $fit, 'Ben Bueno' => $unfit] as $name => $visit) {
            $this->assertSame(route('nurse.visits.print.reprint', $visit->id), $rows[$name]['print_url']);
            $this->assertSame(route('nurse.visits.pdf', $visit->id), $rows[$name]['pdf_url']);
        }

        foreach (['Cara Cruz', 'Dan Diaz', 'Eva Estrada'] as $name) {
            $this->assertNull($rows[$name]['print_url']);
            $this->assertNull($rows[$name]['pdf_url']);
        }
    }

    /**
     * Like the encode page: Print POSTs into the page's hidden print frame
     * (which opens the print dialog in place), so the page carries that frame
     * and each row's form targets it. A Medical Clearance has one Print.
     */
    public function test_a_clearance_row_prints_in_the_hidden_frame(): void
    {
        $batch = $this->makeBatch('clearance');
        $this->addStudent($batch, 'Fit');

        $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertOk()
            ->assertSee('<iframe name="hp-print-frame"', false)
            ->assertSee('target="hp-print-frame"', false)
            ->assertSee('data-print-trigger', false)
            ->assertSee('>Print</button>', false)
            ->assertDontSee('Print front')
            ->assertDontSee('data-flip-hint', false);
    }

    /** A Medical Assessment Form is two pages: Print front and Print back, as on the encode page (D-71). */
    public function test_an_assessment_row_prints_front_and_back(): void
    {
        $batch = $this->makeBatch('assessment');
        $this->addStudent($batch, 'Fit');

        $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertOk()
            ->assertSee('name="side" value="front"', false)
            ->assertSee('name="side" value="back"', false)
            ->assertSeeInOrder(['Print front', 'Print back', 'PDF'])
            ->assertSee('data-flip-hint', false)
            ->assertSee('printed sheet back in the tray');
    }

    public function test_the_row_buttons_print_and_save_the_students_form(): void
    {
        $batch = $this->makeBatch('assessment');
        $visit = $this->visitOf($this->addStudent($batch, 'Fit', 'Ana Abad'));

        $row = $this->actingAs($this->physician)->get(route('nurse.batches.results', $batch))
            ->viewData('results')['rows'][0];

        // Print front: the Reprint endpoint, one side, printed_at stamped.
        $this->assertNull($visit->clearanceRecord->printed_at);
        $this->actingAs($this->physician)->post($row['print_url'], ['side' => 'front'])
            ->assertOk()
            ->assertSee('data-hp-print-doc', false);
        $this->assertNotNull($visit->clearanceRecord->fresh()->printed_at);

        // PDF: named {Batch ID}-{Surname}-{Student ID}.pdf.
        $studentId = $visit->student->studentProfile->student_number;
        $this->actingAs($this->physician)->get($row['pdf_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload("{$batch->reference_no}-Abad-{$studentId}.pdf");
    }

    public function test_there_is_no_release_button_and_the_download_tools_are_always_on(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');
        $this->addStudent($batch, 'booked');

        $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertOk()
            ->assertSee('data-document-tools="enabled"', false)
            ->assertSee('action="'.route('nurse.batches.documents', $batch).'"', false)
            ->assertSee('Select students')
            ->assertSee('Download documents (0)')
            ->assertDontSee('Request release of documents')
            ->assertDontSee('Review request of documents')
            ->assertDontSee('data-release-request', false)
            ->assertDontSee('data-release-review', false)
            ->assertDontSee('data-document-tools-hint', false);
    }

    public function test_the_page_stays_outcome_only(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');

        // The fixture's reading is 112/74 mmHg, 36.6 °C — neither is on the page.
        $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertOk()
            ->assertDontSee('112/74')
            ->assertDontSee('36.6');
    }

    public function test_the_college_and_director_pages_keep_their_four_columns(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');
        $this->release($batch, 'approved');

        $this->actingAs($this->admin)->get(route('admin.batches.results', $batch->id))
            ->assertOk()
            ->assertDontSee('data-row-print', false);
        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))
            ->assertOk()
            ->assertDontSee('data-row-print', false);
    }

    public function test_a_batch_that_is_not_approved_has_no_results_page(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $batch = $this->makeBatch(status: $status);

            $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))->assertNotFound();
            $this->download($this->nurse, $batch, [1])->assertNotFound();
        }
    }

    // ── The ZIP ──────────────────────────────────────────────────────────────

    public function test_clinic_staff_get_one_pdf_per_selected_student(): void
    {
        $batch = $this->makeBatch(college: $this->coe);
        $cruz = $this->visitOf($this->addStudent($batch, 'Fit', 'Ana Cruz'));
        $reyes = $this->visitOf($this->addStudent($batch, 'Unfit', 'Ben Reyes'));
        $santos = $this->visitOf($this->addStudent($batch, 'Fit', 'Liza Santos'));
        $this->addStudent($batch, 'Fit', 'Not Selected');

        // No release request anywhere: the clinic never needs one.
        $this->assertSame(0, $batch->releaseRequests()->count());

        $response = $this->download($this->nurse, $batch, [$cruz->id, $reyes->id, $santos->id])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload($batch->reference_no.'-documents.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
            $this->assertStringStartsWith('%PDF-', $zip->getFromIndex($i));
        }
        $zip->close();
        sort($names);

        $number = fn ($visit): string => $visit->student->studentProfile->student_number;
        $this->assertSame([
            "{$batch->reference_no}-Cruz-{$number($cruz)}.pdf",
            "{$batch->reference_no}-Reyes-{$number($reyes)}.pdf",
            "{$batch->reference_no}-Santos-{$number($santos)}.pdf",
        ], $names);

        $this->download($this->physician, $batch, [$cruz->id])->assertOk();
    }

    public function test_the_clinic_is_held_to_the_same_rule(): void
    {
        config(['healthpass.documents.max_per_download' => 2]);

        $batch = $this->makeBatch();
        $encoded = $this->visitOf($this->addStudent($batch, 'Fit'));
        $second = $this->visitOf($this->addStudent($batch, 'Fit'));
        $third = $this->visitOf($this->addStudent($batch, 'Unfit'));
        $captured = $this->visitOf($this->addStudent($batch, 'in_clinic'));
        $resting = $this->visitOf($this->addStudent($batch, 'resting'));
        $otherBatch = $this->visitOf($this->addStudent($this->makeBatch(), 'Fit'));

        // A seat withdrawn before D-87, even with an encoded visit. (The
        // fixture's encode completes the appointment; put the withdrawal back.)
        $withdrawn = $this->addStudent($batch, 'withdrawn');
        $withdrawnVisit = $this->visit($withdrawn, 'Fit');
        $withdrawn->update(['status' => 'cancelled']);

        $refused = [
            [$captured->id],                         // not encoded
            [$resting->id],                          // not encoded
            [$otherBatch->id],                       // another batch's student
            [$withdrawnVisit->id],                   // withdrawn
            [$encoded->id, 999999],                  // unknown id
            [$encoded->id, $second->id, $third->id], // over the cap
            [$encoded->id, $encoded->id],            // repeated
            [],                                      // nothing ticked
            ['abc'],
        ];

        foreach ($refused as $ids) {
            $this->download($this->nurse, $batch, $ids)->assertStatus(422);
        }

        $this->download($this->nurse, $batch, [$encoded->id, $second->id])->assertOk();
    }
}
