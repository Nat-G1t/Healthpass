<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-134 — a nurse's or physician's signature upload on the My Signature page.
 *
 * PNG only, because a PNG can carry a TRANSPARENT background: a signature on
 * white or grey paper would print as a box over the form's lines. The file
 * has to pass two separate checks, so neither the name nor the browser's
 * claimed type is ever trusted on its own:
 *
 *   - `extensions:png` — the file NAME ends in .png;
 *   - `mimetypes:image/png` — PHP's fileinfo reads the file's real BYTES and
 *     finds a PNG. A JPEG renamed signature.png passes the first and fails this.
 *
 * The size and pixel limits live in config('healthpass.signatures').
 */
class StoreSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The /nurse route group already enforces auth + role:nurse,physician,
        // and the controller only ever writes the signed-in user's own row.
        return true;
    }

    public function rules(): array
    {
        $limits = config('healthpass.signatures');

        return [
            'signature' => [
                'required',
                'file',
                'extensions:png',
                'mimetypes:image/png',
                'max:'.$limits['max_kb'],
                // getimagesize() on the real file, after the type checks above.
                sprintf(
                    'dimensions:min_width=%d,min_height=%d,max_width=%d,max_height=%d',
                    $limits['min_width'],
                    $limits['min_height'],
                    $limits['max_width'],
                    $limits['max_height'],
                ),
            ],
        ];
    }

    public function messages(): array
    {
        $limits = config('healthpass.signatures');
        $notPng = 'The signature must be a PNG image. Save it from remove.bg as PNG and upload that file.';
        $tooBig = 'The signature must be '.(int) round($limits['max_kb'] / 1024).' MB or smaller.';

        return [
            'signature.required' => 'Please choose your signature image.',
            'signature.file' => 'Please choose your signature image.',
            // PHP refusing the file before Laravel sees it — in practice, over
            // the server's upload limit.
            'signature.uploaded' => $tooBig,
            'signature.extensions' => $notPng,
            'signature.mimetypes' => $notPng,
            'signature.max' => $tooBig,
            'signature.dimensions' => sprintf(
                'The signature must be between %d×%d and %d×%d pixels.',
                $limits['min_width'],
                $limits['min_height'],
                $limits['max_width'],
                $limits['max_height'],
            ),
        ];
    }
}
