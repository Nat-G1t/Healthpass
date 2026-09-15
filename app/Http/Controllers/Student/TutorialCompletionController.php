<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\NavBadges;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * FR-STU-11, amended by D-57 — the Kiosk Tutorial reports that the student
 * reached its last step, which removes the tutorial's sidebar dot for good.
 *
 * The walkthrough itself is still client-side Alpine on a Route::view; this is
 * the one thing it tells the server. IDEMPOTENT: the first finish is the one
 * recorded, so walking the tutorial again, or a retried request, changes
 * nothing. 204 No Content — there is nothing to show, and the dot disappears
 * on the next page load.
 */
class TutorialCompletionController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $student = $request->user();

        if (! NavBadges::hasFinishedTutorial($student)) {
            NavBadges::store($student, NavBadges::stamped($student, NavBadges::TUTORIAL_COMPLETED));
        }

        return response()->noContent();
    }
}
