<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProviderCallbackService
{
    public function __construct(
        protected StateTransitionService $stateTransition
    ) {}

    /**
     * Process an incoming provider webhook callback idempotently.
     *
     * @param array $payload
     * @return array
     */
    public function handleCallback(array $payload): array
    {
        $eventId = $payload['event_id'] ?? null;
        if (!$eventId) {
            return [
                'success' => false,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'Missing required event_id.',
            ];
        }

        // 1. Idempotency check via unique event_id constraint
        $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
        if ($existingEvent) {
            return [
                'success' => true,
                'status' => 'DUPLICATE_IGNORED',
                'event_id' => $eventId,
                'message' => 'Event has already been processed. Duplicate callback ignored safely.',
            ];
        }

        // Try inserting the provider event. If a race condition occurs, catch unique constraint violation
        try {
            $providerEvent = ProviderEvent::create([
                'event_id' => $eventId,
                'event_type' => $payload['event_type'] ?? 'POLICY_ISSUED',
                'payload' => $payload,
                'status' => 'RECEIVED',
            ]);
        } catch (QueryException $e) {
            // Duplicate event caught at database constraint level
            return [
                'success' => true,
                'status' => 'DUPLICATE_IGNORED',
                'event_id' => $eventId,
                'message' => 'Event already captured concurrently. Duplicate callback ignored.',
            ];
        }

        // 2. Identify target PolicyRequest
        $providerRef = $payload['provider_reference'] ?? null;
        $agentId = $payload['agent_id'] ?? null;
        $clientRequestId = $payload['client_request_id'] ?? null;

        $policyRequest = null;
        if ($providerRef) {
            $policyRequest = PolicyRequest::where('provider_reference', $providerRef)->first();
        }
        if (!$policyRequest && $agentId && $clientRequestId) {
            $policyRequest = PolicyRequest::where('agent_id', $agentId)
                ->where('client_request_id', $clientRequestId)
                ->first();
        }

        if (!$policyRequest) {
            $providerEvent->update([
                'status' => 'UNMATCHED_REQUEST',
                'processed_at' => now(),
            ]);

            return [
                'success' => false,
                'status' => 'UNMATCHED_REQUEST',
                'message' => 'No policy request matched provider callback identifiers.',
            ];
        }

        $providerEvent->update(['policy_request_id' => $policyRequest->id]);

        // 3. Process under row lock inside transaction
        DB::transaction(function () use ($policyRequest, $payload, $providerEvent) {
            /** @var PolicyRequest $locked */
            $locked = PolicyRequest::where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard against overwriting confirmed ISSUED state
            if ($locked->status === StateTransitionService::STATUS_ISSUED) {
                $providerEvent->update([
                    'status' => 'PROCESSED_NOOP_ALREADY_ISSUED',
                    'processed_at' => now(),
                ]);
                return;
            }

            $eventType = strtoupper($payload['event_type'] ?? 'POLICY_ISSUED');

            if ($eventType === 'POLICY_ISSUED') {
                $policyNumber = $payload['policy_number'] ?? ('POL-' . date('Ymd') . '-CB');

                $this->stateTransition->transition(
                    policyRequest: $locked,
                    toStatus: StateTransitionService::STATUS_ISSUED,
                    reason: "Policy issuance confirmed via provider callback (event: {$providerEvent->event_id}).",
                    context: $payload,
                    extraAttributes: [
                        'policy_number' => $policyNumber,
                        'provider_reference' => $payload['provider_reference'] ?? $locked->provider_reference,
                        'last_error' => null,
                    ]
                );
            } elseif ($eventType === 'POLICY_FAILED') {
                $this->stateTransition->transition(
                    policyRequest: $locked,
                    toStatus: StateTransitionService::STATUS_FAILED,
                    reason: "Policy failure reported via provider callback (event: {$providerEvent->event_id}).",
                    context: $payload,
                    extraAttributes: [
                        'last_error' => $payload['error'] ?? 'Provider callback reported issuance failure.',
                    ]
                );
            }

            $providerEvent->update([
                'status' => 'PROCESSED',
                'processed_at' => now(),
            ]);
        });

        return [
            'success' => true,
            'status' => 'PROCESSED',
            'event_id' => $eventId,
            'policy_request_id' => $policyRequest->id,
            'current_policy_status' => $policyRequest->fresh()->status,
        ];
    }
}
