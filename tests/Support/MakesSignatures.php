<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * D-134 — real signature images for the tests, drawn with GD (already on:
 * dompdf needs it). Real bytes matter: the upload rules sniff the file's
 * actual content, which Laravel's UploadedFile::fake() would only pretend to
 * have. Callers fake the `local` disk first.
 */
trait MakesSignatures
{
    /** A PNG with a transparent background and one stroke; $shade varies the bytes. */
    private function signaturePng(int $width = 400, int $height = 120, int $shade = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetthickness($image, 4);
        imageline($image, 10, $height - 15, $width - 10, 15, imagecolorallocate($image, $shade, $shade, 60));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** A JPEG — what a phone photo is, and what a PNG name must not smuggle in. */
    private function jpegBytes(int $width = 400, int $height = 120): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * An upload carrying exactly these bytes under this name and claimed type,
     * the way a browser would send it — so the server's own sniffing is what
     * is being tested.
     */
    private function uploadOf(string $bytes, string $name, string $clientMime = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    /**
     * Put a signature on file for $user, as the My Signature page would, and
     * return its base64 — what the printed form's data URI must carry.
     */
    private function giveSignature(User $user, int $width = 400, int $height = 120, int $shade = 20): string
    {
        $bytes = $this->signaturePng($width, $height, $shade);
        $path = 'signatures/'.Str::random(40).'.png';

        Storage::disk('local')->put($path, $bytes);
        $user->update(['signature_path' => $path]);

        return base64_encode($bytes);
    }
}
