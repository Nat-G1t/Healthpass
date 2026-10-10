<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MakesSignatures;
use Tests\TestCase;

/**
 * FR-NRS-15 / D-134 — My Signature: a nurse or physician uploads a PNG of
 * their signature (transparent background), replaces it or removes it. The
 * file sits on the PRIVATE disk; only its owner can see it, through the
 * page's preview route.
 */
class SignaturePageTest extends TestCase
{
    use MakesSignatures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // ── 1. Access ────────────────────────────────────────────────────────────

    public static function clinicRoles(): array
    {
        return ['nurse' => ['nurse'], 'physician' => ['physician']];
    }

    #[DataProvider('clinicRoles')]
    public function test_clinic_staff_see_the_menu_item_and_the_page(string $role): void
    {
        $staff = User::factory()->create(['role' => $role, 'license_number' => $role === 'physician' ? '60252' : null]);

        $this->actingAs($staff)->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSee('My Signature')
            ->assertSee(route('nurse.signature'), false);

        $this->actingAs($staff)->get(route('nurse.signature'))
            ->assertOk()
            ->assertSee('Use a clear, transparent background.')
            ->assertSee('href="https://www.remove.bg/" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('accept="image/png"', false)
            ->assertSee('No signature yet — forms print a blank line for you to sign by hand.');
    }

    public static function refusedRoles(): array
    {
        return [
            'student' => ['student'],
            'college admin' => ['college_admin'],
            'director' => ['director'],
        ];
    }

    #[DataProvider('refusedRoles')]
    public function test_every_other_role_is_refused(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('nurse.signature'))->assertRedirect();
        $this->actingAs($user)->get(route('nurse.signature.image'))->assertRedirect();
        $this->actingAs($user)
            ->post(route('nurse.signature.store'), ['signature' => $this->uploadOf($this->signaturePng(), 'sig.png')])
            ->assertRedirect();

        $this->assertNull($user->fresh()->signature_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('nurse.signature'))->assertRedirect(route('login'));
    }

    // ── 2. The preview image: the owner's own file, nobody else's ───────────

    public function test_the_image_route_serves_the_owners_own_file(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $b64 = $this->giveSignature($nurse);

        $response = $this->actingAs($nurse)->get(route('nurse.signature.image'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame($b64, base64_encode($response->streamedContent()));
    }

    public function test_another_clinic_user_gets_their_own_or_a_404_never_someone_elses(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $nurseB64 = $this->giveSignature($nurse, shade: 10);

        $physician = User::factory()->physician()->create();

        // No signature of their own → 404, not the nurse's.
        $this->actingAs($physician)->get(route('nurse.signature.image'))->assertNotFound();

        // Their own once they have one.
        $physicianB64 = $this->giveSignature($physician, shade: 90);
        $served = base64_encode(
            $this->actingAs($physician)->get(route('nurse.signature.image'))->assertOk()->streamedContent()
        );

        $this->assertSame($physicianB64, $served);
        $this->assertNotSame($nurseB64, $served);
    }

    public function test_the_path_is_never_serialised(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $this->giveSignature($nurse);

        $this->assertArrayNotHasKey('signature_path', $nurse->fresh()->toArray());
    }

    // ── 3. Upload ────────────────────────────────────────────────────────────

    public function test_a_real_png_is_stored_privately_and_its_path_saved(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->post(route('nurse.signature.store'), ['signature' => $this->uploadOf($this->signaturePng(), 'My Signature.png')])
            ->assertRedirect(route('nurse.signature'))
            ->assertSessionHas('status', 'Signature uploaded.');

        $path = $nurse->fresh()->signature_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith('signatures/', $path);
        // A random name, never the uploader's.
        $this->assertStringNotContainsString('My Signature', $path);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($nurse)->get(route('nurse.signature'))
            ->assertOk()
            ->assertSee('data-signature-preview', false)
            ->assertSee('Uploaded ')
            ->assertSee('Replace')
            ->assertSee('Remove');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function refusedFiles(): array
    {
        return [
            // Right name and claimed type, wrong bytes: fileinfo finds a JPEG.
            'a JPEG renamed .png' => ['jpeg', 'signature.png', 'image/png'],
            'a PDF' => ['pdf', 'signature.pdf', 'application/pdf'],
            'a PDF renamed .png' => ['pdf', 'signature.png', 'image/png'],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function test_anything_but_a_real_png_is_refused(string $kind, string $name, string $clientMime): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $bytes = $kind === 'jpeg'
            ? $this->jpegBytes()
            : "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        $this->actingAs($nurse)
            ->from(route('nurse.signature'))
            ->post(route('nurse.signature.store'), ['signature' => $this->uploadOf($bytes, $name, $clientMime)])
            ->assertRedirect(route('nurse.signature'))
            ->assertSessionHasErrors([
                'signature' => 'The signature must be a PNG image. Save it from remove.bg as PNG and upload that file.',
            ]);

        $this->assertNull($nurse->fresh()->signature_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_png_over_two_megabytes_is_refused(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        // Still a real PNG to fileinfo and getimagesize — only its size is wrong.
        $bytes = $this->signaturePng().str_repeat("\0", 2 * 1024 * 1024 + 1024);

        $this->actingAs($nurse)
            ->from(route('nurse.signature'))
            ->post(route('nurse.signature.store'), ['signature' => $this->uploadOf($bytes, 'big.png')])
            ->assertSessionHasErrors(['signature' => 'The signature must be 2 MB or smaller.']);

        $this->assertNull($nurse->fresh()->signature_path);
    }

    public function test_a_png_smaller_than_150_by_50_is_refused(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->from(route('nurse.signature'))
            ->post(route('nurse.signature.store'), ['signature' => $this->uploadOf($this->signaturePng(100, 30), 'tiny.png')])
            ->assertSessionHasErrors(['signature' => 'The signature must be between 150×50 and 3000×3000 pixels.']);

        $this->assertNull($nurse->fresh()->signature_path);
    }

    // ── 4. Replace / remove ──────────────────────────────────────────────────

    public function test_replacing_saves_the_new_file_and_deletes_the_old_one(): void
    {
        $physician = User::factory()->physician()->create();
        $this->giveSignature($physician, shade: 10);
        $oldPath = $physician->fresh()->signature_path;

        $this->actingAs($physician)
            ->post(route('nurse.signature.store'), ['signature' => $this->uploadOf($this->signaturePng(shade: 99), 'new.png')])
            ->assertRedirect(route('nurse.signature'))
            ->assertSessionHas('status', 'Signature replaced.');

        $newPath = $physician->fresh()->signature_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_removing_deletes_the_file_and_nulls_the_column(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $this->giveSignature($nurse);
        $path = $nurse->fresh()->signature_path;

        $this->actingAs($nurse)
            ->delete(route('nurse.signature.destroy'))
            ->assertRedirect(route('nurse.signature'));

        $this->assertNull($nurse->fresh()->signature_path);
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($nurse)->get(route('nurse.signature'))
            ->assertSee('No signature yet — forms print a blank line for you to sign by hand.');
    }

    public function test_removing_with_nothing_on_file_is_a_friendly_no_op(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->delete(route('nurse.signature.destroy'))
            ->assertRedirect(route('nurse.signature'))
            ->assertSessionHas('status', 'There is no signature to remove.');
    }
}
