<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\BatchRequest;
use App\Support\SignedMemo;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Director opens a batch's signed memo (D-109) — Card 3 of the Review
 * page. Any batch, any status: the Director sees every college's requests.
 * The `role:director` route group keeps everyone else out.
 */
class BatchMemoController extends Controller
{
    public function __invoke(BatchRequest $batch): StreamedResponse
    {
        return SignedMemo::response($batch);
    }
}
