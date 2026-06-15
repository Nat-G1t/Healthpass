<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'clinic_visit_id',
        'vision',
        'hearing',
        'nose',
        'skin',
        'respiratory',
        'heart',
        'digestive',
        'bones',
        'nervous',
        'is_pregnant',
        'last_menstrual_period',
    ];

    protected function casts(): array
    {
        return [
            'vision' => 'boolean',
            'hearing' => 'boolean',
            'nose' => 'boolean',
            'skin' => 'boolean',
            'respiratory' => 'boolean',
            'heart' => 'boolean',
            'digestive' => 'boolean',
            'bones' => 'boolean',
            'nervous' => 'boolean',
            'is_pregnant' => 'boolean',
            'last_menstrual_period' => 'date',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit this questionnaire belongs to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
