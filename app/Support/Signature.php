<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * D-134 — a nurse's or physician's uploaded PNG signature, as the printed
 * forms embed it.
 *
 * The file lives on the PRIVATE `local` disk (storage/app/private/signatures),
 * so no URL reaches it. The forms carry it the same way they carry the
 * letterhead logos (ClearanceDocument::logo()): as a base64 `data:` URI inside
 * the document. The browser's print iframe and dompdf then both draw it with
 * no request of their own — a URL would need a route for the iframe and a
 * remote fetch for dompdf.
 *
 * It is always the person's CURRENT signature: a reprint after they replace
 * it prints the new one.
 */
final class Signature
{
    /** The folder on the private disk the uploads go to. */
    public const DIRECTORY = 'signatures';

    /**
     * The box a printed signature is fitted into, in points. 30pt is the
     * Medical Clearance's `.sig-space` (so it moves nothing on that page) and
     * 2in is narrower than every signature slot on both forms.
     */
    private const MAX_HEIGHT_PT = 30.0;

    private const MAX_WIDTH_PT = 144.0;

    /**
     * What a form needs to draw $user's signature — the data URI plus a width
     * and height that keep its proportions inside the box — or null when there
     * is no one, no signature on file, or the file has gone missing (the form
     * then prints its blank line, exactly as before D-134).
     *
     * The size is decided HERE, not with CSS `max-width`: dompdf does not
     * reliably honour max-width on an image, and an explicit width and height
     * mean the browser print and the PDF lay it out identically.
     *
     * @return array{src: string, width: float, height: float}|null
     */
    public static function forPrint(?User $user): ?array
    {
        $path = $user?->signature_path;

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            // The column points at a file that is gone (a restore without
            // storage/app/private, say). Print the blank line, but say so.
            Log::warning('D-134: signature file missing; printing a blank line.', [
                'user_id' => $user->id,
                'path' => $path,
            ]);

            return null;
        }

        $bytes = (string) $disk->get($path);
        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            Log::warning('D-134: signature file is not a readable image; printing a blank line.', [
                'user_id' => $user->id,
                'path' => $path,
            ]);

            return null;
        }

        [$width, $height] = $size;
        $scale = min(self::MAX_HEIGHT_PT / $height, self::MAX_WIDTH_PT / $width);

        return [
            'src' => 'data:image/png;base64,'.base64_encode($bytes),
            'width' => round($width * $scale, 1),
            'height' => round($height * $scale, 1),
        ];
    }
}
