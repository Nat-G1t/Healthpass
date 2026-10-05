<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files College Admins attach — the batch's signed memo (D-109) and the
 * document release request's attachment (D-113). Both live on the PRIVATE
 * `local` disk (storage/app/private), outside public/, so no URL reaches them
 * directly: only a route that has already checked who is asking hands one
 * out, and they all send it through inline() so it goes out the same way.
 */
final class PrivateUpload
{
    /**
     * Send a stored file for the browser to SHOW (`inline`), not download:
     * with the Content-Type the server detected at upload — never a guess from
     * the name — and `nosniff`, so the browser doesn't second-guess it either.
     * No path, or a file that has gone missing, is a 404.
     */
    public static function inline(?string $path, string $name, string $mime): StreamedResponse
    {
        $disk = Storage::disk('local');

        abort_unless($path !== null && $disk->exists($path), 404);

        return $disk->response($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * The name the file had on the admin's device, made safe to keep and to
     * send back in a Content-Disposition header — control characters dropped,
     * cut to the column's 255 characters. The browser sent it, so it is never
     * trusted as is (it is only ever shown, escaped, and never used as a path).
     */
    public static function displayName(string $clientName, string $fallback): string
    {
        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $clientName)), 0, 255);

        return $name !== '' ? $name : $fallback;
    }

    /** "340 KB" / "2.4 MB" for a stored file, or null when there is none. */
    public static function sizeLabel(?string $path): ?string
    {
        $disk = Storage::disk('local');

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->size($path);

        return $bytes < 1024 * 1024
            ? max(1, (int) round($bytes / 1024)).' KB'
            : number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
