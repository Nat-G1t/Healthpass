<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Support\VisitDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * D-113 — a batch's official documents as ONE ZIP file, one PDF per selected
 * student (Q15), each rendered exactly like the clinic's own Save as PDF
 * (VisitDocument — the same template, data and paper).
 *
 * D-118: a download of ONE student skips the ZIP — the browser gets that
 * student's PDF itself, under the same file name it would have had inside
 * the ZIP. Two or more students still come as one ZIP.
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
     * The posted visit ids → one student's PDF (D-118), or a ZIP download
     * named "{Batch ID}-documents.zip" for two or more. The ZIP's temporary
     * file is deleted as soon as it has been sent.
     */
    public function download(BatchRequest $batch, mixed $visitIds): Response
    {
        $visits = $this->downloadableVisits($batch, $visitIds);

        if ($visits->count() === 1) {
            return $this->pdf($visits->first())->download(VisitDocument::filename($visits->first()));
        }

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
            // The file name (VisitDocument::filename) reads the batch and the
            // student's profile — loaded here once, not once per PDF.
            ->with(['student.studentProfile', 'appointment.batchRequest'])
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
            $zip->addFromString(VisitDocument::filename($visit), $this->pdf($visit)->output());
        }

        if ($zip->close() !== true) {
            throw new RuntimeException("D-113: could not write the document ZIP at {$path}.");
        }

        return $path;
    }

    /** The most students one download may hold (config, default 50). */
    /** One visit's document, laid out by dompdf exactly like the clinic's Save as PDF. */
    private function pdf(ClinicVisit $visit): DomPdf
    {
        return Pdf::loadView(VisitDocument::template($visit), VisitDocument::data($visit))
            ->setPaper(VisitDocument::paper($visit));
    }

    public static function maxPerDownload(): int
    {
        return (int) config('healthpass.documents.max_per_download');
    }
}
