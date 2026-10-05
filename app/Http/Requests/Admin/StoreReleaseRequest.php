<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-113 — a College Admin asks the Director to release a finished batch's
 * documents: a written reason and one attached file (a photo, image or PDF —
 * the same rules as the D-109 signed memo, from config('healthpass.uploads')).
 *
 * The batch, its college and whether it may be asked for yet are checked by
 * DocumentReleaseController under a lock; this class only checks the two
 * fields.
 */
class StoreReleaseRequest extends FormRequest
{
    /** Bounds for the written reason — also used by the popup's counter. */
    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        // The /admin route group already enforces auth + role + college scope.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
            // `mimes` reads the file's REAL content (fileinfo), not its name.
            // `max` is in kilobytes.
            'attachment' => [
                'required',
                'file',
                'mimes:'.implode(',', config('healthpass.uploads.mimes')),
                'max:'.config('healthpass.uploads.max_kb'),
            ],
        ];
    }

    /**
     * The file's messages use the same words <x-hp.file-capture> shows in the
     * browser ("The file must be …"), so the two checks never disagree.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tooBig = 'The file must be '.(int) round(config('healthpass.uploads.max_kb') / 1024).' MB or smaller.';

        return [
            'reason.required' => 'Please give the Director a reason for the release.',
            'reason.min' => 'The reason must be at least :min characters.',
            'reason.max' => 'Please keep the reason to :max characters.',
            'attachment.required' => 'Please attach a photo or PDF for the Director.',
            'attachment.file' => 'Please attach a photo or PDF for the Director.',
            // PHP refusing the file before Laravel sees it — in practice, over
            // the server's upload limit.
            'attachment.uploaded' => $tooBig,
            'attachment.mimes' => 'The file must be a JPG, PNG or PDF.',
            'attachment.max' => $tooBig,
        ];
    }
}
