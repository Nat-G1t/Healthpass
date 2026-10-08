<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-123 — FLAGGED SCHEMA CHANGE on document_release_requests: the attachment
 * columns become nullable and `wants_printed_copies` is added. RefreshDatabase
 * has already run the migration up on SQLite; down() must refuse while a row
 * without an attachment exists, since there is no honest value to restore.
 */
class ReleaseRequestMigrationTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_08_000001_d123_release_requests_no_attachment_printed_copies.php');
    }

    public function test_up_makes_the_attachment_optional_and_adds_printed_copies(): void
    {
        $this->assertTrue(Schema::hasColumn('document_release_requests', 'wants_printed_copies'));

        // A row with no attachment and no printed-copies value is accepted.
        $release = $this->release($this->completedBatch())->fresh();

        $this->assertNull($release->attachment_path);
        $this->assertFalse($release->wants_printed_copies);
    }

    public function test_down_refuses_while_a_request_has_no_attachment(): void
    {
        $this->release($this->completedBatch());

        try {
            $this->migration()->down();
            $this->fail('down() should have refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be rolled back', $e->getMessage());
        }

        // Nothing was changed.
        $this->assertTrue(Schema::hasColumn('document_release_requests', 'wants_printed_copies'));
    }
}
