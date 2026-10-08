<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Http\Requests\Director\RejectReleaseRequest;
use App\Models\DocumentReleaseRequest;
use App\Support\PrivateUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * D-113 — the Director reviews a college's request to release a batch's
 * documents (FR-DIRA-11), from the batch's results page:
 *
 *   attachment()  the file the college attached to a request made BEFORE
 *                 D-123, shown inline — the Director is the ONLY one who can
 *                 open it (not even the college admin who uploaded it gets a
 *                 route back to it). A newer request has none: 404.
 *   approve()     release the documents to that college, for that batch only;
 *                 the approval never expires
 *   reject()      with a written message the college reads on its page
 *
 * Both decisions re-read the request under a lock and do nothing when it is
 * no longer pending, so a double-click or a second tab can't decide it twice
 * (the batch approve / reject pattern, FR-DIRA-05). The `role:director` route
 * group keeps everyone else out.
 */
class DocumentReleaseController extends Controller
{
    public function attachment(DocumentReleaseRequest $releaseRequest): StreamedResponse
    {
        // D-123: checked here, before the NULL name / type reach inline()'s
        // string parameters.
        abort_unless($releaseRequest->hasAttachment(), 404);

        return PrivateUpload::inline(
            $releaseRequest->attachment_path,
            $releaseRequest->attachment_original_name,
            $releaseRequest->attachment_mime,
        );
    }

    public function approve(Request $request, DocumentReleaseRequest $releaseRequest): RedirectResponse
    {
        $decided = $this->decide($releaseRequest, [
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return $this->backToBatch($releaseRequest, $decided,
            'Documents released — the college can now download its students’ forms.');
    }

    public function reject(RejectReleaseRequest $request, DocumentReleaseRequest $releaseRequest): RedirectResponse
    {
        $decided = $this->decide($releaseRequest, [
            'status' => 'rejected',
            'rejection_message' => $request->validated('rejection_message'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return $this->backToBatch($releaseRequest, $decided,
            'Request rejected — the college sees your message on its results page.');
    }

    /**
     * Write the decision on the LOCKED row, only while it is still pending.
     * False when it had already been decided (nothing is changed then).
     *
     * @param  array<string, mixed>  $decision
     */
    private function decide(DocumentReleaseRequest $releaseRequest, array $decision): bool
    {
        return DB::transaction(function () use ($releaseRequest, $decision): bool {
            $locked = DocumentReleaseRequest::whereKey($releaseRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                return false;
            }

            $locked->update($decision);

            return true;
        });
    }

    private function backToBatch(DocumentReleaseRequest $releaseRequest, bool $decided, string $message): RedirectResponse
    {
        $redirect = redirect()->route('director.tracking.results', $releaseRequest->batch_request_id);

        return $decided
            ? $redirect->with('status', $message)
            : $redirect->with('error', 'This request has already been decided — nothing was changed.');
    }
}
