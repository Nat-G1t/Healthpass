<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name'];

    // ── Relationships ────────────────────────────────────────────────────────

    /** The college admin accounts scoped to this college. */
    public function admins(): HasMany
    {
        return $this->hasMany(User::class, 'managed_college_id');
    }

    /** All student profiles enrolled in this college. */
    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'college_id');
    }

    /** Batch clearance requests submitted for this college. */
    public function batchRequests(): HasMany
    {
        return $this->hasMany(BatchRequest::class, 'college_id');
    }
}
