<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReleaseRequest;
use App\Models\BatchRequest;
use App\Services\BatchDocumentZip;
use App\Support\BatchResults;
use App\Support\PrivateUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * D-113 — a College Admin's side of the document release, on the batch's
 * results page (FR-ADM-15):
 *
 *   store()     ask the Director to release the batch's documents — a reason
 *               and one attached photo / image / PDF
 *   download()  once the Director has approved, the selected encoded
 *               students' official forms as one ZIP
 *
 * The batch is always found THROUGH the managed college, like every /admin
 * batch page (FR-ADM-06): another college's batch is a plain 404, and only an
 * approved batch has a results page at all.
 */
class DocumentReleaseController extends Controller
{
    use ScopedToManagedCollege;

    /**
     * The attachment is saved to the private disk FIRST — a file can't be
     * part of a database transaction — and deleted again if the request is
     * refused or the transaction fails, so no orphan file is left behind (the
     * D-109 memo's pattern).
     *
     * The rules are checked on the LOCKED batch row, so two clicks (or two
     * admins of the same college) can't both create a pending request.
     */
    public function store(StoreReleaseRequest $request, int $batchId): RedirectResponse
    {
        $batch = $this->approvedBatch($batchId);
        $file = $request->file('attachment');
        $path = $file->store('release-requests', 'local');

        if ($path === false) {
            Log::error('D-113: a release attachment could not be written to storage/app/private/release-requests.', [
                'batch_request_id' => $batch->id,
                'user_id' => $request->user()->id,
            ]);

            return back()->withInput()->withErrors(['attachment' => 'The attachment could not be saved. Please try again.']);
        }

        try {
            // null = created, otherwise why it was refused.
            $refusal = DB::transaction(function () use ($request, $batch, $file, $path): ?string {
                $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();

                if ($locked->releaseRequests()->where('status', 'approved')->exists()) {
                    return 'The Director has already released this batch’s documents.';
                }

                if ($locked->releaseRequests()->where('status', 'pending')->exists()) {
                    return 'A release request for this batch is already waiting for the Director.';
                }

                if (! $locked->load(BatchResults::statusRelations())->isReadyForRelease()) {
                    return 'Documents can be requested once the batch is completed.';
                }

                $locked->releaseRequests()->create([
                    'requested_by' => $request->user()->id,
                    'reason' => $request->validated('reason'),
                    'attachment_path' => $path,
                    'attachment_original_name' => PrivateUpload::displayName($file->getClientOriginalName(), 'attachment'),
                    // The type the SERVER detected from the content — never the browser's claim.
                    'attachment_mime' => $file->getMimeType(),
                    'status' => 'pending',
                ]);

                return null;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        if ($refusal !== null) {
            Storage::disk('local')->delete($path);

            return redirect()->route('admin.batches.results', $batch->id)->with('error', $refusal);
        }

        return redirect()->route('admin.batches.results', $batch->id)
            ->with('status', 'Release requested — the Director will review it.');
    }

    /**
     * Refused (403) until the Director has released this batch. Which
     * students may be in the ZIP is BatchDocumentZip's one shared rule.
     */
    public function download(Request $request, int $batchId, BatchDocumentZip $zip): BinaryFileResponse
    {
        $batch = $this->approvedBatch($batchId);

        abort_unless($batch->hasReleasedDocuments(), 403, 'The Director has not released this batch’s documents.');

        return $zip->download($batch, $request->input('visits'));
    }

    private function approvedBatch(int $batchId): BatchRequest
    {
        return $this->managedCollege()->batchRequests()->where('status', 'approved')->findOrFail($batchId);
    }
}
