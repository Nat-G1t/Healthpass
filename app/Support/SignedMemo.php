<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BatchRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a batch's signed memo (D-109) from the PRIVATE `local` disk.
 *
 * The file lives in storage/app/private/memos — outside public/, so no URL
 * reaches it directly. The only way in is a route that has already checked
 * who is asking (the Director's, or the batch's own college admin's — see the
 * two BatchMemoControllers); both call this so the file is always sent the
 * same way:
 *
 *   - `inline`, so the browser SHOWS the image or PDF instead of downloading it
 *   - the Content-Type the server detected at upload, never a guess from the
 *     file name, plus `nosniff` so the browser doesn't second-guess it either
 */
class SignedMemo
{
    public static function response(BatchRequest $batch): StreamedResponse
    {
        $disk = Storage::disk('local');

        // A batch from before D-109 has no memo; a missing file is a 404 too.
        abort_unless($batch->hasMemo() && $disk->exists($batch->memo_path), 404);

        return $disk->response($batch->memo_path, $batch->memo_original_name, [
            'Content-Type' => $batch->memo_mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /** "340 KB" / "2.4 MB" for the Review page's PDF tile, or null with no memo file. */
    public static function sizeLabel(BatchRequest $batch): ?string
    {
        $disk = Storage::disk('local');

        if (! $batch->hasMemo() || ! $disk->exists($batch->memo_path)) {
            return null;
        }

        $bytes = $disk->size($batch->memo_path);

        return $bytes < 1024 * 1024
            ? max(1, (int) round($bytes / 1024)).' KB'
            : number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
