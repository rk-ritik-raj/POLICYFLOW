<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyStateHistory extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'policy_state_history';

    protected $fillable = [
        'policy_request_id',
        'from_status',
        'to_status',
        'reason',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
