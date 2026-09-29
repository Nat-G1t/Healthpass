<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The year span of a Yearly Clearance Report (FR-ADM-13, D-94) — shared by
 * the College Admin's report and the Director's.
 *
 * D-94 replaced the single `year` with a START year (`from`) and an END year
 * (`to`). Both run from healthpass.reports.yearly_first_year (2021) to the
 * CURRENT year on the server clock (BR-20 — never the browser's), and the end
 * may not come before the start. The popup only offers valid years and
 * refuses an end before the start, so a failure here means a hand-edited URL.
 */
class YearlyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (role + college scope) already gates access.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $first = (int) config('healthpass.reports.yearly_first_year');
        $current = now()->year;

        return [
            'from' => ['required', 'integer', "min:{$first}", "max:{$current}"],
            'to' => ['required', 'integer', "min:{$first}", "max:{$current}", 'gte:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.gte' => 'The end year cannot be before the start year.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from' => 'start year', 'to' => 'end year'];
    }

    /**
     * Every calendar year in the span, oldest first.
     *
     * @return list<int>
     */
    public function years(): array
    {
        return range((int) $this->validated('from'), (int) $this->validated('to'));
    }

    /** "2026" for one year, "2021-2026" for a span — used in the file name. */
    public function spanLabel(): string
    {
        $years = $this->years();

        return count($years) === 1 ? (string) $years[0] : $years[0].'-'.end($years);
    }
}
