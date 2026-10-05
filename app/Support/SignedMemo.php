<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BatchRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a batch's signed memo (D-109) from the PRIVATE `local` disk.
 *
 * The file lives in storage/app/private/memos — outside public/, so no URL
 * reaches it directly. The only way in is a route that has already checked
 * who is asking (the Director's, or the batch's own college admin's — see the
 * two BatchMemoControllers); both call this so the file is always sent the
 * same way — inline, with the server-detected type and `nosniff`
 * (PrivateUpload::inline(), shared with D-113's release attachment).
 */
class SignedMemo
{
    public static function response(BatchRequest $batch): StreamedResponse
    {
        // A batch from before D-109 has no memo (a 404, like a missing file).
        return PrivateUpload::inline($batch->memo_path, (string) $batch->memo_original_name, (string) $batch->memo_mime);
    }

    /** "340 KB" / "2.4 MB" for the Review page's PDF tile, or null with no memo file. */
    public static function sizeLabel(BatchRequest $batch): ?string
    {
        return PrivateUpload::sizeLabel($batch->memo_path);
    }
}
