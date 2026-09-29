<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The five counts every Yearly Clearance Report line carries — total, Fit,
 * Unfit, Male, Female (FR-ADM-13, D-81, D-94) — and the one way to add two
 * lines together.
 *
 * D-94 turned the report into a span of years, so the same five numbers are
 * now summed in several places: years into a span, programs into a college,
 * colleges into the clinic. Doing it through one function means every total
 * in the PDF is built the same way, so no two of them can disagree.
 */
final class ClearanceCounts
{
    /** @var array{total: int, fit: int, unfit: int, male: int, female: int} */
    public const EMPTY = ['total' => 0, 'fit' => 0, 'unfit' => 0, 'male' => 0, 'female' => 0];

    /**
     * @param  array{total: int, fit: int, unfit: int, male: int, female: int}  $a
     * @param  array{total: int, fit: int, unfit: int, male: int, female: int}  $b
     * @return array{total: int, fit: int, unfit: int, male: int, female: int}
     */
    public static function add(array $a, array $b): array
    {
        $sum = self::EMPTY;

        foreach (array_keys(self::EMPTY) as $key) {
            $sum[$key] = $a[$key] + $b[$key];
        }

        return $sum;
    }

    /**
     * @param  iterable<array{total: int, fit: int, unfit: int, male: int, female: int}>  $lines
     * @return array{total: int, fit: int, unfit: int, male: int, female: int}
     */
    public static function sum(iterable $lines): array
    {
        $sum = self::EMPTY;

        foreach ($lines as $line) {
            $sum = self::add($sum, $line);
        }

        return $sum;
    }
}
