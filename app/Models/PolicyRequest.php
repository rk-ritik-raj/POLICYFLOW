<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'travel_days',
        'customer_age',
        'plan_code',
        'sum_insured',
        'base_daily_rate',
        'age_multiplier',
        'destination_multiplier',
        'coverage_multiplier',
        'premium_amount',
        'status',
        'provider_reference',
        'policy_number',
        'attempt_count',
        'last_error',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'travel_days' => 'integer',
        'customer_age' => 'integer',
        'sum_insured' => 'decimal:2',
        'base_daily_rate' => 'decimal:2',
        'age_multiplier' => 'decimal:2',
        'destination_multiplier' => 'decimal:2',
        'coverage_multiplier' => 'decimal:2',
        'premium_amount' => 'decimal:2',
        'attempt_count' => 'integer',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_number', 'asc');
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('created_at', 'asc');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->orderBy('created_at', 'asc');
    }
}
