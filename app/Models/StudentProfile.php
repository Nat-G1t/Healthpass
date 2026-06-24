<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'college_id',
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'sex',
        'course',
        'year_level',
        'date_of_birth',
        'place_of_birth',
        'civil_status',
        'address',
        'qr_token',
        'privacy_consent_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'privacy_consent_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The user account that owns this profile. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The college this student is enrolled in. */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether a physical student ID has been bound to this profile.
     *
     * The schema has no boolean flag for this (PRD 10-table limit). At
     * registration qr_token is a 64-char random placeholder; once the student
     * links their ID, qr_token becomes the scanned IDNo, whose digits equal the
     * student_number digits. So a digit match means the QR is the real,
     * linked ID — a random placeholder never matches.
     */
    public function isQrLinked(): bool
    {
        $tokenDigits = preg_replace('/\D/', '', (string) $this->qr_token);
        $numberDigits = preg_replace('/\D/', '', (string) $this->student_number);

        return $tokenDigits !== '' && $tokenDigits === $numberDigits;
    }
}
