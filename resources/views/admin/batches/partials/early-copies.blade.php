{{--
    D-136: where the college's early printed-copies request stands — the
    wording every College Admin page shares (Request Submitted, the batch's
    details page and its results page). Each page wraps it in its own label.

    Takes $batch. Draw it only when $batch->earlyCopiesStatus() is not null.
--}}
@switch ($batch->earlyCopiesStatus())
    @case('requested')
        <span data-early-copies="requested">Requested — the Director decides it when approving the batch.</span>
        @break
    @case('approved')
        <span data-early-copies="approved">Approved — the clinic prints each student’s form as soon as it is encoded.</span>
        @break
    @case('not_approved')
        <span data-early-copies="not_approved">Not approved — you can still ask for printed copies in the release request once the batch is completed.</span>
        @break
@endswitch
