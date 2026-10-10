<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreSignatureRequest;
use App\Support\PrivateUpload;
use App\Support\Signature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * D-134 — My Signature: a nurse or physician uploads a PNG of their signature,
 * and the official forms they sign print it (App\Support\Signature).
 *
 *   show()    the page — guidance, the current signature, the upload
 *   store()   upload or replace (StoreSignatureRequest: PNG, 2 MB, size)
 *   destroy() remove it — forms go back to printing a blank line
 *   image()   the signed-in user's OWN file, for the page's preview
 *
 * Every action works on `$request->user()` only — there is no user id in any
 * URL, so nobody can reach or change another person's signature. The file
 * sits on the PRIVATE `local` disk; image() is the only way it leaves the
 * server other than embedded in a printed form.
 */
class SignatureController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $path = $user->signature_path;
        $disk = Storage::disk('local');
        $hasFile = $path !== null && $disk->exists($path);

        return view('nurse.signature', [
            'hasSignature' => $hasFile,
            // The file's own modified time (no extra column). A bare timestamp
            // reads as UTC, so it is put in the clinic's time zone for display.
            'uploadedOn' => $hasFile
                ? Carbon::createFromTimestamp($disk->lastModified($path), config('app.timezone'))
                : null,
        ]);
    }

    public function store(StoreSignatureRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldPath = $user->signature_path;

        // A random file name (store() hashes it), never the uploader's.
        $newPath = $request->file('signature')->store(Signature::DIRECTORY, 'local');

        if ($newPath === false) {
            return back()->withErrors(['signature' => 'The signature could not be saved. Please try again.']);
        }

        try {
            $user->update(['signature_path' => $newPath]);
        } catch (\Throwable $e) {
            // Don't leave an orphan file behind a failed save.
            Storage::disk('local')->delete($newPath);

            throw $e;
        }

        // The old file goes only AFTER the new one is saved and recorded, so
        // a failure part-way never leaves the user with no signature at all.
        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect()->route('nurse.signature')
            ->with('status', $oldPath === null ? 'Signature uploaded.' : 'Signature replaced.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $path = $user->signature_path;

        if ($path === null) {
            return redirect()->route('nurse.signature')->with('status', 'There is no signature to remove.');
        }

        $user->update(['signature_path' => null]);
        Storage::disk('local')->delete($path);

        return redirect()->route('nurse.signature')
            ->with('status', 'Signature removed. Your forms will print a blank line for you to sign by hand.');
    }

    /** The signed-in user's own signature, for the preview — never anyone else's. */
    public function image(Request $request): StreamedResponse
    {
        return PrivateUpload::inline($request->user()->signature_path, 'signature.png', 'image/png');
    }
}
