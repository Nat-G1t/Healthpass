<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'role',
        'name',
        'email',
        'email_verified_at',
        'password',
        'managed_college_id',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The college this admin is scoped to (college_admin role only). */
    public function managedCollege(): BelongsTo
    {
        return $this->belongsTo(College::class, 'managed_college_id');
    }

    /** 1:1 student detail record (student role only). */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** Appointments booked by or generated for this student. */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'student_id');
    }

    /** Kiosk clinic visits submitted by this student. */
    public function clinicVisits(): HasMany
    {
        return $this->hasMany(ClinicVisit::class, 'student_id');
    }

    /** Batch requests this college admin submitted. */
    public function batchRequestsSubmitted(): HasMany
    {
        return $this->hasMany(BatchRequest::class, 'requested_by');
    }

    /** Batch requests this director approved or rejected. */
    public function batchRequestsReviewed(): HasMany
    {
        return $this->hasMany(BatchRequest::class, 'reviewed_by');
    }

    /** Pivot rows for batch requests this student appears in. */
    public function batchRequestStudents(): HasMany
    {
        return $this->hasMany(BatchRequestStudent::class, 'student_id');
    }

    /** Clearance records encoded by this nurse. */
    public function clearanceRecordsEncoded(): HasMany
    {
        return $this->hasMany(ClearanceRecord::class, 'encoded_by');
    }
}
