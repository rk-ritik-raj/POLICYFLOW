<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'policy_number',
        'provider_reference',
        'plan_code',
        'sum_insured',
        'premium_amount',
        'status',
    ];

    protected $casts = [
        'sum_insured' => 'decimal:2',
        'premium_amount' => 'decimal:2',
    ];
}
