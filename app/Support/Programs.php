<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\College;

/**
 * Read access to the academic program catalog in config/programs.php (D-42).
 *
 * The catalog is keyed by college CODE so it survives a reseed, but every
 * caller in the app holds a college ID (that is what the forms post and what
 * student_profiles stores). This class is the one place that bridges the two,
 * so no controller, request or view ever has to know a college code.
 *
 * Nothing here throws. An unknown college id returns an empty list, which
 * makes a hand-edited form FAIL VALIDATION rather than blow up with a 500 —
 * the server is the authority on what is a valid program (FR-REG-03).
 */
final class Programs
{
    /** Container key for the per-request college id => code map. */
    private const CODE_MAP = 'healthpass.college_codes';

    /**
     * The programs that college offers, in catalog order.
     *
     * @return list<string>
     */
    public static function forCollege(int $collegeId): array
    {
        return self::entry($collegeId)['programs'] ?? [];
    }

    /**
     * That college's year levels as storage-key => display-label
     * (['1' => '1st Year', …], or ['7' => 'Grade 7', …] for junior high).
     *
     * @return array<int|string, string>
     */
    public static function yearLevelsForCollege(int $collegeId): array
    {
        return self::entry($collegeId)['year_levels'] ?? [];
    }

    /**
     * The whole catalog re-keyed by college ID, for the Alpine cascade on the
     * registration and profile forms. Keyed by ID (not code) because that is
     * what the college <select> posts, so the browser can look the entry up
     * with the value it already has.
     *
     * @return array<int, array{programs: list<string>, year_levels: array<int|string, string>}>
     */
    public static function all(): array
    {
        $catalog = [];

        foreach (self::codeMap() as $collegeId => $code) {
            $catalog[(int) $collegeId] = [
                'programs' => config("programs.{$code}.programs", []),
                'year_levels' => config("programs.{$code}.year_levels", []),
            ];
        }

        return $catalog;
    }

    /**
     * Just the storage keys of that college's year levels (['1','2',…]), as
     * strings — the form posts strings, so Rule::in() compares strings.
     *
     * @return list<string>
     */
    public static function yearLevelKeys(int $collegeId): array
    {
        return array_map(strval(...), array_keys(self::yearLevelsForCollege($collegeId)));
    }

    /** Is this exact program string offered by this college? */
    public static function isValid(int $collegeId, string $course): bool
    {
        return in_array($course, self::forCollege($collegeId), strict: true);
    }

    /**
     * The catalog entry for a college id, or an empty array when the college
     * (or its catalog entry) does not exist.
     *
     * @return array{programs?: list<string>, year_levels?: array<int|string, string>}
     */
    private static function entry(int $collegeId): array
    {
        $map = self::codeMap();

        // A miss can simply mean the map was cached before this college
        // existed, so re-read once before concluding it is unknown.
        if (! array_key_exists($collegeId, $map)) {
            $map = self::loadCodeMap();
        }

        $code = $map[$collegeId] ?? null;

        return $code === null ? [] : config("programs.{$code}", []);
    }

    /**
     * college id => code, read from the database ONCE per request.
     *
     * The map is stashed on Laravel's service container — the per-request
     * registry every app already has — rather than in a static property. A
     * static would survive from one test case to the next and hand back a
     * previous test's college ids. A lookup miss re-reads the table once, so
     * a college created part-way through a request is still found.
     *
     * @return array<int, string>
     */
    private static function codeMap(): array
    {
        if (! app()->bound(self::CODE_MAP)) {
            return self::loadCodeMap();
        }

        return app(self::CODE_MAP);
    }

    /** @return array<int, string> */
    private static function loadCodeMap(): array
    {
        $map = College::query()->pluck('code', 'id')->all();

        app()->instance(self::CODE_MAP, $map);

        return $map;
    }
}
