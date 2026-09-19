<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-64 — the University Physician gets their own account. FLAGGED SCHEMA
 * CHANGE (approved by Nat, 2026-09-18/19):
 *
 *  - `users.role` gains a fifth value, `physician` (supersedes D-2);
 *  - `users.license_number` — the PRC license printed under the physician's
 *    name on records they encode. Required for physicians (enforced by the
 *    Director's Form Requests), NULL for everyone else.
 *
 * `->change()` re-declares an existing column. On MySQL it is an ALTER of the
 * ENUM. SQLite cannot alter a column's CHECK constraint in place, so Laravel
 * REBUILDS the table: copy to a temp table, DROP users, rename. Dropping
 * `users` while other tables' rows point at it trips SQLite's foreign-key
 * check, so on SQLite the checks are paused around the rebuild — the rows and
 * ids are copied unchanged, so nothing is left dangling. SQLite ignores that
 * pause inside a transaction, which is why this migration opts out of the
 * one Laravel would otherwise wrap it in. Covered by PhysicianRoleMigrationTest.
 */
return new class extends Migration
{
    /** See the class comment: the foreign-key pause needs no open transaction. */
    public $withinTransaction = false;

    private const OLD_ROLES = ['student', 'college_admin', 'nurse', 'director'];

    private const NEW_ROLES = ['student', 'college_admin', 'nurse', 'physician', 'director'];

    public function up(): void
    {
        $this->rebuildingUsers(function (): void {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', self::NEW_ROLES)->change();
                // Digits only, 4–10 long (validated); 20 matches
                // clearance_records.physician_license_no, which it is copied into.
                $table->string('license_number', 20)->nullable()->after('role');
            });
        });
    }

    public function down(): void
    {
        // Narrowing the enum would fail (MySQL) or break the CHECK (SQLite) on a
        // physician row, and there is no honest role to demote one to.
        if (DB::table('users')->where('role', 'physician')->exists()) {
            throw new RuntimeException('Cannot roll back D-64: physician accounts exist. Remove them by hand first.');
        }

        $this->rebuildingUsers(function (): void {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('license_number');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', self::OLD_ROLES)->change();
            });
        });
    }

    /** Run $change with SQLite's foreign-key checks paused (a no-op elsewhere). */
    private function rebuildingUsers(callable $change): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $change();

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $change();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
