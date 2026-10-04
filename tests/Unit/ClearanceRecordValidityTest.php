<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClearanceRecord;
use Tests\TestCase;

/**
 * ClearanceRecord::validUntil() and isExpired() — D-107 / BR-26.
 *
 * A Fit result is valid THROUGH the encoded date + healthpass.clearance_validity_months
 * (no overflow: Aug 31 + 6 months = Feb 28), expired from the next day on the
 * server clock. An Unfit result has no validity at all. Nothing is persisted.
 */
class ClearanceRecordValidityTest extends TestCase
{
    private function record(string $result, ?string $encodedAt, ?string $createdAt = null): ClearanceRecord
    {
        $record = new ClearanceRecord(['result' => $result, 'encoded_at' => $encodedAt]);
        $record->created_at = $createdAt;

        return $record;
    }

    public function test_valid_until_is_the_encoded_day_plus_six_months(): void
    {
        $validUntil = $this->record('Fit', '2026-10-04 15:30:00')->validUntil();

        $this->assertSame('2027-04-04 00:00:00', $validUntil->toDateTimeString());
    }

    public function test_valid_until_does_not_overflow_into_the_next_month(): void
    {
        $this->assertSame('2027-02-28', $this->record('Fit', '2026-08-31 09:00:00')->validUntil()->toDateString());
    }

    public function test_valid_until_reads_the_month_count_from_config(): void
    {
        config(['healthpass.clearance_validity_months' => 3]);

        $this->assertSame('2027-01-04', $this->record('Fit', '2026-10-04 09:00:00')->validUntil()->toDateString());
    }

    public function test_valid_until_falls_back_to_created_at(): void
    {
        $record = $this->record('Fit', null, '2026-10-04 08:00:00');

        $this->assertSame('2027-04-04', $record->validUntil()->toDateString());
    }

    public function test_valid_until_leaves_encoded_at_untouched(): void
    {
        $record = $this->record('Fit', '2026-10-04 15:30:00');
        $record->validUntil();

        $this->assertSame('2026-10-04 15:30:00', $record->encoded_at->toDateTimeString());
    }

    public function test_an_unfit_result_has_no_validity_and_never_expires(): void
    {
        $record = $this->record('Unfit', '2020-01-01 09:00:00');

        $this->assertNull($record->validUntil());
        $this->assertFalse($record->isExpired());
    }

    public function test_it_is_not_expired_on_the_last_valid_day(): void
    {
        $this->travelTo('2027-04-04 23:59:00');

        $this->assertFalse($this->record('Fit', '2026-10-04 15:30:00')->isExpired());
    }

    public function test_it_is_expired_from_the_next_day(): void
    {
        $this->travelTo('2027-04-05 00:00:00');

        $this->assertTrue($this->record('Fit', '2026-10-04 15:30:00')->isExpired());
    }
}
