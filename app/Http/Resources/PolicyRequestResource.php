<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth ? $this->date_of_birth->format('Y-m-d') : null,
            'destination' => $this->destination,
            'trip_start' => $this->trip_start ? $this->trip_start->format('Y-m-d') : null,
            'trip_end' => $this->trip_end ? $this->trip_end->format('Y-m-d') : null,
            'travel_days' => $this->travel_days,
            'customer_age' => $this->customer_age,
            'plan_code' => $this->plan_code,
            'sum_insured' => (string) $this->sum_insured,
            'pricing_breakdown' => [
                'base_daily_rate' => (string) $this->base_daily_rate,
                'age_multiplier' => (string) $this->age_multiplier,
                'destination_multiplier' => (string) $this->destination_multiplier,
                'coverage_multiplier' => (string) $this->coverage_multiplier,
                'premium_amount' => (string) $this->premium_amount,
            ],
            'status' => $this->status,
            'provider_reference' => $this->provider_reference,
            'policy_number' => $this->policy_number,
            'attempt_count' => $this->attempt_count,
            'last_error' => $this->last_error,
            'attempts' => $this->whenLoaded('attempts', function () {
                return $this->attempts->map(function ($attempt) {
                    return [
                        'attempt_number' => $attempt->attempt_number,
                        'scenario' => $attempt->scenario,
                        'status' => $attempt->status,
                        'provider_reference' => $attempt->provider_reference,
                        'error_message' => $attempt->error_message,
                        'response_payload' => $attempt->response_payload,
                        'created_at' => $attempt->created_at ? $attempt->created_at->toIso8601String() : null,
                    ];
                });
            }),
            'state_history' => $this->whenLoaded('stateHistory', function () {
                return $this->stateHistory->map(function ($history) {
                    return [
                        'from_status' => $history->from_status,
                        'to_status' => $history->to_status,
                        'reason' => $history->reason,
                        'context' => $history->context,
                        'created_at' => $history->created_at ? $history->created_at->toIso8601String() : null,
                    ];
                });
            }),
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toIso8601String() : null,
        ];
    }
}
