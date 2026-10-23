<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PolicyIssuanceService
{
    public function __construct(
        protected MockInsurerService $mockInsurer,
        protected StateTransitionService $stateTransition,
        protected ReconciliationService $reconciliationService
    ) {}

    /**
     * Submit or safely resume policy issuance.
     *
     * @param PolicyRequest $policyRequest
     * @param string|null $scenario
     * @return array
     */
    public function submit(PolicyRequest $policyRequest, ?string $scenario = null): array
    {
        // 1. Idempotency check: If already issued, return current state immediately without duplicate provider calls
        if ($policyRequest->status === StateTransitionService::STATUS_ISSUED) {
            return [
                'already_issued' => true,
                'status' => StateTransitionService::STATUS_ISSUED,
                'policy_number' => $policyRequest->policy_number,
                'provider_reference' => $policyRequest->provider_reference,
                'message' => 'Policy has already been issued for this request. Duplicate issuance prevented.',
                'policy_request' => $policyRequest,
            ];
        }

        // 2. Check if request needs reconciliation before any retry
        if ($policyRequest->status === StateTransitionService::STATUS_NEEDS_RECONCILIATION) {
            $reconcileResult = $this->reconciliationService->reconcile($policyRequest);
            $policyRequest = $policyRequest->fresh();

            if ($policyRequest->status === StateTransitionService::STATUS_ISSUED) {
                return [
                    'already_issued' => true,
                    'reconciled' => true,
                    'status' => StateTransitionService::STATUS_ISSUED,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                    'message' => 'Policy reconciled successfully from upstream provider.',
                    'policy_request' => $policyRequest,
                ];
            }
        }

        // 3. Concurrency check & transition to SUBMITTED under database lock
        $policyRequest = DB::transaction(function () use ($policyRequest) {
            /** @var PolicyRequest $locked */
            $locked = PolicyRequest::where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === StateTransitionService::STATUS_ISSUED) {
                return $locked;
            }

            // Only VALIDATED or FAILED (retry) can transition to SUBMITTED
            if ($locked->status === StateTransitionService::STATUS_RECEIVED) {
                $locked = $this->stateTransition->transition(
                    policyRequest: $locked,
                    toStatus: StateTransitionService::STATUS_VALIDATED,
                    reason: 'Intake validated successfully.'
                );
            }

            if (!in_array($locked->status, [StateTransitionService::STATUS_VALIDATED, StateTransitionService::STATUS_FAILED])) {
                return $locked;
            }

            $locked->increment('attempt_count');

            return $this->stateTransition->transition(
                policyRequest: $locked,
                toStatus: StateTransitionService::STATUS_SUBMITTED,
                reason: 'Submission started to insurer provider.'
            );
        });

        if ($policyRequest->status === StateTransitionService::STATUS_ISSUED) {
            return [
                'already_issued' => true,
                'status' => StateTransitionService::STATUS_ISSUED,
                'policy_number' => $policyRequest->policy_number,
                'provider_reference' => $policyRequest->provider_reference,
                'policy_request' => $policyRequest,
            ];
        }

        // Create attempt log
        $attempt = IssuanceAttempt::create([
            'policy_request_id' => $policyRequest->id,
            'attempt_number' => $policyRequest->attempt_count,
            'scenario' => $scenario ?: MockInsurerService::SCENARIO_SUCCESS,
            'request_payload' => [
                'client_request_id' => $policyRequest->client_request_id,
                'agent_id' => $policyRequest->agent_id,
                'customer_name' => $policyRequest->customer_name,
                'plan_code' => $policyRequest->plan_code,
                'sum_insured' => (string) $policyRequest->sum_insured,
                'premium_amount' => (string) $policyRequest->premium_amount,
            ],
            'status' => 'PENDING',
        ]);

        // 4. Invoke mock insurer service
        try {
            $providerResponse = $this->mockInsurer->issuePolicy($policyRequest, $scenario);

            if ($providerResponse['status'] === 'SUCCESS') {
                $attempt->update([
                    'response_payload' => $providerResponse,
                    'status' => 'SUCCESS',
                    'provider_reference' => $providerResponse['provider_reference'],
                ]);

                $updated = $this->stateTransition->transition(
                    policyRequest: $policyRequest,
                    toStatus: StateTransitionService::STATUS_ISSUED,
                    reason: 'Insurer successfully issued policy.',
                    context: $providerResponse,
                    extraAttributes: [
                        'policy_number' => $providerResponse['policy_number'],
                        'provider_reference' => $providerResponse['provider_reference'],
                        'last_error' => null,
                    ]
                );

                return [
                    'status' => StateTransitionService::STATUS_ISSUED,
                    'policy_number' => $updated->policy_number,
                    'provider_reference' => $updated->provider_reference,
                    'message' => 'Policy issued successfully.',
                    'policy_request' => $updated,
                ];
            }

            if ($providerResponse['status'] === 'PENDING') {
                $attempt->update([
                    'response_payload' => $providerResponse,
                    'status' => 'PENDING',
                    'provider_reference' => $providerResponse['provider_reference'],
                ]);

                $updated = $this->stateTransition->transition(
                    policyRequest: $policyRequest,
                    toStatus: StateTransitionService::STATUS_PENDING_CONFIRMATION,
                    reason: 'Insurer returned pending status; awaiting asynchronous callback.',
                    context: $providerResponse,
                    extraAttributes: [
                        'provider_reference' => $providerResponse['provider_reference'],
                    ]
                );

                return [
                    'status' => StateTransitionService::STATUS_PENDING_CONFIRMATION,
                    'provider_reference' => $updated->provider_reference,
                    'message' => 'Policy issuance pending confirmation.',
                    'policy_request' => $updated,
                ];
            }

            // Provider returned FAILED (e.g. temporary failure)
            $attempt->update([
                'response_payload' => $providerResponse,
                'status' => 'TEMPORARY_FAILURE',
                'error_message' => $providerResponse['error'] ?? 'Provider returned failure.',
            ]);

            $updated = $this->stateTransition->transition(
                policyRequest: $policyRequest,
                toStatus: StateTransitionService::STATUS_FAILED,
                reason: 'Provider returned temporary failure.',
                context: $providerResponse,
                extraAttributes: [
                    'last_error' => $providerResponse['error'] ?? 'Provider returned failure.',
                ]
            );

            return [
                'status' => StateTransitionService::STATUS_FAILED,
                'retriable' => true,
                'error' => $updated->last_error,
                'policy_request' => $updated,
            ];

        } catch (ConnectionException $e) {
            // TIMEOUT CASE: Network timeout occurred after request reached provider
            $attempt->update([
                'status' => 'TIMEOUT',
                'error_message' => $e->getMessage(),
            ]);

            $updated = $this->stateTransition->transition(
                policyRequest: $policyRequest,
                toStatus: StateTransitionService::STATUS_NEEDS_RECONCILIATION,
                reason: 'Network connection timeout during insurer submission. Flagged for reconciliation.',
                context: ['exception' => $e->getMessage()],
                extraAttributes: [
                    'last_error' => 'Connection timeout to insurer: ' . $e->getMessage(),
                ]
            );

            return [
                'status' => StateTransitionService::STATUS_NEEDS_RECONCILIATION,
                'error' => 'Submission timed out. Request placed in NEEDS_RECONCILIATION to protect against duplicate issuance.',
                'policy_request' => $updated,
            ];

        } catch (Throwable $e) {
            $attempt->update([
                'status' => 'SYSTEM_ERROR',
                'error_message' => $e->getMessage(),
            ]);

            $updated = $this->stateTransition->transition(
                policyRequest: $policyRequest,
                toStatus: StateTransitionService::STATUS_FAILED,
                reason: 'Unexpected error during issuance: ' . $e->getMessage(),
                extraAttributes: [
                    'last_error' => $e->getMessage(),
                ]
            );

            return [
                'status' => StateTransitionService::STATUS_FAILED,
                'error' => $e->getMessage(),
                'policy_request' => $updated,
            ];
        }
    }
}
