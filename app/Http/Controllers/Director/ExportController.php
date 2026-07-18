<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Flagged Anomalies CSV export (FR-ANL-06) — built on the SAME scope as
 * the screen (ClinicVisit::flagged() + VitalSigns::flagDetails()), so the
 * file can never disagree with the table it sits under.
 *
 * The response is a streamed text/csv download that starts with a UTF-8
 * BOM — the three bytes Excel on Windows needs to read the file as UTF-8
 * instead of ANSI (which would mangle names like "Peña").
 */
class ExportController extends Controller
{
    /**
     * Flagged Anomalies → "Export CSV": one row PER TRIPPED FLAG — a
     * visit that trips two thresholds becomes two rows, so each anomaly
     * is its own analyzable line. Same flagged() scope, ordering, and
     * flag label/value pairs (flagDetails()) as the on-screen table
     * (FR-ANL-05); still-captured visits are included (flags surface
     * from capture, FR-ANL-07) and marked Encoded = No.
     */
    public function anomalies(): StreamedResponse
    {
        $visits = ClinicVisit::flagged()
            ->with(['student.studentProfile', 'college:id,code', 'vitalSigns'])
            ->latest('checked_in_at')
            ->latest('id')
            ->get();

        $rows = $visits->flatMap(fn (ClinicVisit $visit): array => array_map(
            fn (array $flag): array => [
                $visit->student?->studentProfile?->student_number ?? '',
                $visit->student->name ?? '',
                $visit->college->code ?? '',
                $flag['label'],
                $flag['value'],
                $visit->checked_in_at?->format('Y-m-d H:i') ?? '',
                $visit->status === 'encoded' ? 'Yes' : 'No',
            ],
            $visit->vitalSigns?->flagDetails() ?? [],
        ));

        return $this->csv(
            'flagged-anomalies',
            ['Student Number', 'Student', 'College', 'Flag', 'Value', 'Captured At', 'Encoded'],
            $rows,
        );
    }

    /**
     * Build the streamed CSV download. streamDownload() writes the
     * Content-Disposition header from the filename; fputcsv handles all
     * quoting (escape '' disables PHP's non-standard backslash escape,
     * leaving plain RFC 4180 doubling — what Excel expects).
     *
     * @param  list<string>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function csv(string $name, array $columns, iterable $rows): StreamedResponse
    {
        $filename = sprintf('healthpass-%s-%s.csv', $name, now()->format('Ymd'));

        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // UTF-8 BOM for Excel
            fputcsv($out, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map($this->guardCell(...), $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Excel executes a cell starting with = + - or @ as a FORMULA, so a
     * crafted student name could run when the Director opens the file
     * (CSV injection). A leading apostrophe makes Excel read it as text.
     */
    private function guardCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && str_contains('=+-@', $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
