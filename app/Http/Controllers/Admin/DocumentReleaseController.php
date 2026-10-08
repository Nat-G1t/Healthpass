<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReleaseRequest;
use App\Models\BatchRequest;
use App\Services\BatchDocumentZip;
use App\Support\BatchResults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-113 — a College Admin's side of the document release, on the batch's
 * results page (FR-ADM-15):
 *
 *   store()     ask the Director to release the batch's documents — a reason,
 *               and whether the college needs printed copies (D-123)
 *   download()  once the Director has approved, the selected encoded
 *               students' official forms (one PDF, or a ZIP for two or more — D-118)
 *
 * The batch is always found THROUGH the managed college, like every /admin
 * batch page (FR-ADM-06): another college's batch is a plain 404, and only an
 * approved batch has a results page at all.
 */
class DocumentReleaseController extends Controller
{
    use ScopedToManagedCollege;

    /**
     * D-123: no attachment — the Director reviews the batch's own signed memo
     * (D-109). The college may ask for printed copies of the forms.
     *
     * The rules are checked on the LOCKED batch row, so two clicks (or two
     * admins of the same college) can't both create a pending request.
     */
    public function store(StoreReleaseRequest $request, int $batchId): RedirectResponse
    {
        $batch = $this->approvedBatch($batchId);

        // null = created, otherwise why it was refused.
        $refusal = DB::transaction(function () use ($request, $batch): ?string {
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
                // An unticked checkbox sends nothing — that means no.
                'wants_printed_copies' => $request->boolean('wants_printed_copies'),
                'status' => 'pending',
            ]);

            return null;
        });

        if ($refusal !== null) {
            return redirect()->route('admin.batches.results', $batch->id)->with('error', $refusal);
        }

        return redirect()->route('admin.batches.results', $batch->id)
            ->with('status', 'Release requested — the Director will review it.');
    }

    /**
     * Refused (403) until the Director has released this batch. Which
     * students may be in the ZIP is BatchDocumentZip's one shared rule.
     */
    public function download(Request $request, int $batchId, BatchDocumentZip $zip): Response
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
