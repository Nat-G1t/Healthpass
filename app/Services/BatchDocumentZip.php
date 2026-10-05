<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Support\VisitDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * D-113 — a batch's official documents as ONE ZIP file, one PDF per selected
 * student (Q15), each rendered exactly like the clinic's own Save as PDF
 * (VisitDocument — the same template, data and paper).
 *
 * Every download route calls download(): the College Admin's (after the
 * Director releases the batch), the Director's (always), and the clinic's
 * (D-114). So "which students may be downloaded" is decided in ONE place —
 * downloadableVisits() — and never from the browser's say-so.
 *
 * ZipArchive is PHP's built-in zip-file writer (the `zip` extension —
 * switched on in php.ini). You open a new archive at a file path, add files to
 * it by name, and close() it, which is when the .zip is actually written.
 */
final class BatchDocumentZip
{
    /**
     * The posted visit ids → a ZIP download named "{Batch ID}-documents.zip".
     * The temporary file is deleted as soon as it has been sent.
     */
    public function download(BatchRequest $batch, mixed $visitIds): BinaryFileResponse
    {
        $visits = $this->downloadableVisits($batch, $visitIds);

        return response()
            ->download($this->build($batch, $visits), $batch->reference_no.'-documents.zip', [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * THE rule (D-113): every posted id must be the ENCODED visit (it has a
     * clearance record) of a roster row of THIS batch that still holds its
     * seat — the row's current appointment, so a 2nd chance counts (D-110)
     * and a seat withdrawn before D-87 does not. Anything else — another
     * batch's visit, a captured or resting one, an unknown id, a repeated
     * id, more than the cap — is a 422. The page only ever offers valid ids,
     * so a 422 means the request was tampered with.
     *
     * @return Collection<int, ClinicVisit>
     */
    public function downloadableVisits(BatchRequest $batch, mixed $visitIds): Collection
    {
        $validator = Validator::make(['visits' => $visitIds], [
            'visits' => ['required', 'array', 'min:1', 'max:'.self::maxPerDownload()],
            'visits.*' => ['required', 'integer', 'distinct'],
        ]);

        abort_if($validator->fails(), 422, $validator->errors()->first());

        $ids = array_map('intval', $visitIds);

        $visits = ClinicVisit::query()
            ->whereIn('id', $ids)
            ->where('status', 'encoded')
            ->whereHas('clearanceRecord')
            ->whereHas('appointment.batchRequestStudent', fn (Builder $row) => $row
                ->where('batch_request_id', $batch->id)
                ->notWithdrawn())
            ->with('student.studentProfile')
            ->get();

        abort_if($visits->count() !== count($ids), 422, 'A selected student has no document on this batch.');

        return $visits;
    }

    /**
     * Render each visit's PDF into a new ZIP under storage/app/private/tmp/
     * and return the ZIP's path. The caller sends it and deletes it.
     *
     * @param  Collection<int, ClinicVisit>  $visits
     */
    public function build(BatchRequest $batch, Collection $visits): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('tmp');
        $path = $disk->path('tmp/'.Str::uuid().'.zip');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("D-113: could not create the document ZIP at {$path}.");
        }

        foreach ($visits as $visit) {
            $pdf = Pdf::loadView(VisitDocument::template($visit), VisitDocument::data($visit))
                ->setPaper(VisitDocument::paper($visit))
                ->output();

            $zip->addFromString($this->entryName($batch, $visit), $pdf);
        }

        if ($zip->close() !== true) {
            throw new RuntimeException("D-113: could not write the document ZIP at {$path}.");
        }

        return $path;
    }

    /** The most students one download may hold (config, default 50). */
    public static function maxPerDownload(): int
    {
        return (int) config('healthpass.documents.max_per_download');
    }

    /**
     * "{Batch ID}-{Student No}-{Last name}.pdf", e.g.
     * "BR-2026-014-2023-123456-Dela-Cruz.pdf". The name is made plain ASCII
     * (ñ → n, spaces → -) so every zip tool and operating system shows it the
     * same way. Student numbers are unique, so two entries never collide.
     */
    private function entryName(BatchRequest $batch, ClinicVisit $visit): string
    {
        $profile = $visit->student?->studentProfile;

        $parts = array_map(
            fn (string $part): string => trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($part)), '-'),
            [$batch->reference_no, (string) $profile?->student_number, (string) $profile?->last_name],
        );

        return implode('-', array_filter($parts, fn (string $part): bool => $part !== '')).'.pdf';
    }
}
