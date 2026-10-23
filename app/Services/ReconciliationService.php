<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Exception;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(
        protected MockInsurerService $mockInsurer,
        protected StateTransitionService $stateTransition
    ) {}

    /**
     * Reconcile a policy request that is in NEEDS_RECONCILIATION or PENDING_CONFIRMATION.
     *
     * @param PolicyRequest $policyRequest
     * @return array
     */
    public function reconcile(PolicyRequest $policyRequest): array
    {
        // Lookup upstream provider status
        $providerStatus = $this->mockInsurer->statusLookup(
            providerReference: $policyRequest->provider_reference,
            agentId: $policyRequest->agent_id,
            clientRequestId: $policyRequest->client_request_id
        );

        if (!$providerStatus || !($providerStatus['found'] ?? false)) {
            // Provider has no record of issuance. If it was stuck in reconciliation, mark FAILED so it can be safely retried
            if ($policyRequest->status === StateTransitionService::STATUS_NEEDS_RECONCILIATION) {
                $this->stateTransition->transition(
                    policyRequest: $policyRequest,
                    toStatus: StateTransitionService::STATUS_FAILED,
                    reason: 'Reconciliation verified: provider has no record of issuance. Safe to retry.',
                    context: ['provider_lookup' => $providerStatus]
                );
            }

            return [
                'reconciled' => true,
                'outcome' => 'NOT_ISSUED_BY_PROVIDER',
                'policy_request' => $policyRequest->fresh(),
            ];
        }

        // If provider confirms policy was ISSUED:
        if ($providerStatus['status'] === 'ISSUED') {
            $policyNumber = $providerStatus['policy_number'];
            $providerRef = $providerStatus['provider_reference'];

            $updatedRequest = $this->stateTransition->transition(
                policyRequest: $policyRequest,
                toStatus: StateTransitionService::STATUS_ISSUED,
                reason: 'Reconciliation confirmed: policy was issued by provider during timeout/pending window.',
                context: [
                    'provider_lookup' => $providerStatus,
                ],
                extraAttributes: [
                    'policy_number' => $policyNumber,
                    'provider_reference' => $providerRef,
                    'last_error' => null,
                ]
            );

            return [
                'reconciled' => true,
                'outcome' => 'CONFIRMED_ISSUED',
                'policy_request' => $updatedRequest,
            ];
        }

        // If provider says PENDING:
        if ($providerStatus['status'] === 'PENDING') {
            if ($policyRequest->status !== StateTransitionService::STATUS_PENDING_CONFIRMATION) {
                $this->stateTransition->transition(
                    policyRequest: $policyRequest,
                    toStatus: StateTransitionService::STATUS_PENDING_CONFIRMATION,
                    reason: 'Reconciliation verified: provider still processing issuance asynchronously.',
                    context: ['provider_lookup' => $providerStatus],
                    extraAttributes: [
                        'provider_reference' => $providerStatus['provider_reference'],
                    ]
                );
            }

            return [
                'reconciled' => true,
                'outcome' => 'STILL_PENDING',
                'policy_request' => $policyRequest->fresh(),
            ];
        }

        // If provider says FAILED:
        $this->stateTransition->transition(
            policyRequest: $policyRequest,
            toStatus: StateTransitionService::STATUS_FAILED,
            reason: 'Reconciliation confirmed: provider recorded failed issuance.',
            context: ['provider_lookup' => $providerStatus]
        );

        return [
            'reconciled' => true,
            'outcome' => 'CONFIRMED_FAILED',
            'policy_request' => $policyRequest->fresh(),
        ];
    }
}
