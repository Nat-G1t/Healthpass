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
        'license_number',
        'name',
        'email',
        'email_verified_at',
        'password',
        'managed_college_id',
        'status',
        'must_change_password',
        'last_active_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Column defaults mirrored on the model. Without this, a just-created User
     * reports `must_change_password` as NULL until it is reloaded (Eloquent does
     * not read database defaults back), which reads as "unknown" in code that
     * should see a plain false. (D-35)
     */
    protected $attributes = [
        'must_change_password' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_active_at' => 'datetime',
            // D-57: page => last-seen timestamp. Deliberately NOT fillable — it
            // is written only by App\Support\NavBadges::store().
            'nav_seen_at' => 'array',
        ];
    }

    /**
     * The two roles that work the Clinic Dashboard (`/nurse/*`) and encode
     * Fit/Unfit (D-64). Use this, never `role === 'nurse'`, for clinic access.
     *
     * @var list<string>
     */
    public const CLINIC_STAFF_ROLES = ['nurse', 'physician'];

    /** Display label per role — the sidebar, the staff list and the badges. */
    public const ROLE_LABELS = [
        'student' => 'Student',
        'college_admin' => 'College Admin',
        'nurse' => 'Nurse',
        'physician' => 'Physician',
        'director' => 'Clinic Director',
    ];

    /** A nurse or a physician (D-64) — the Clinic Dashboard's users. */
    public function isClinicStaff(): bool
    {
        return in_array($this->role, self::CLINIC_STAFF_ROLES, true);
    }

    public function isPhysician(): bool
    {
        return $this->role === 'physician';
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? '';
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

    /** Clearance records encoded by this nurse or physician. */
    public function clearanceRecordsEncoded(): HasMany
    {
        return $this->hasMany(ClearanceRecord::class, 'encoded_by');
    }
}
