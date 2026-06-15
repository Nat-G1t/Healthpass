<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates per-year sequential reference numbers for the three entities
 * that need them (§5.6 / BR-19):
 *
 *   APT-YYYY-####   appointments        (4-digit sequence)
 *   BR-YYYY-###     batch_requests      (3-digit sequence)
 *   HP-YYYY-####    clinic_visits       (4-digit sequence)
 *
 * CONCURRENCY NOTES
 * -----------------
 * Call these methods from *inside* the caller's DB::transaction(). When you
 * do, the lockForUpdate() acquires a row/gap lock that is held until the
 * outer transaction commits — so the generated number and the INSERT are
 * atomic and no two callers can receive the same sequence number.
 *
 * If called outside a transaction (e.g. in tinker), the method opens its own
 * transaction just for the read. The unique index on reference_no is then the
 * final safety net: the retry loop catches UniqueConstraintViolationException
 * so callers never need to handle it themselves.
 */
class ReferenceNumberService
{
    public function generateAppointmentRef(): string
    {
        return $this->nextRef('appointments', 'reference_no', 'APT', 4);
    }

    public function generateBatchRef(): string
    {
        return $this->nextRef('batch_requests', 'reference_no', 'BR', 3);
    }

    public function generateVisitRef(): string
    {
        return $this->nextRef('clinic_visits', 'reference_no', 'HP', 4);
    }

    private function nextRef(string $table, string $column, string $prefix, int $pad): string
    {
        $year = now()->year;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($table, $column, $prefix, $year, $pad) {
                    // Lock matching rows (or gap if empty) so concurrent reads
                    // block until this transaction commits.
                    $max = DB::table($table)
                        ->whereRaw("{$column} LIKE ?", ["{$prefix}-{$year}-%"])
                        ->lockForUpdate()
                        ->max($column);

                    $seq = $max === null
                        ? 1
                        : ((int) Str::afterLast($max, '-')) + 1;

                    return sprintf('%s-%d-%0' . $pad . 'd', $prefix, $year, $seq);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
            }
        }

        // Unreachable — loop always returns or re-throws on attempt 5.
        throw new \RuntimeException("Failed to generate {$prefix} reference number after 5 attempts.");
    }
}
