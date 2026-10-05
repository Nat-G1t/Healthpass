<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Support\SignedMemo;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A College Admin opens the signed memo their college attached (D-109), from
 * the Request Submitted page and the batch roster.
 *
 * The batch is found THROUGH the managed college, like every other /admin
 * batch page, so another college's batch id is a plain 404 — its memo can't
 * be reached or even confirmed to exist.
 */
class BatchMemoController extends Controller
{
    use ScopedToManagedCollege;

    public function __invoke(int $batchId): StreamedResponse
    {
        $batch = $this->managedCollege()->batchRequests()->findOrFail($batchId);

        return SignedMemo::response($batch);
    }
}
