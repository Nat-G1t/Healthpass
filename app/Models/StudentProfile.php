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
}
