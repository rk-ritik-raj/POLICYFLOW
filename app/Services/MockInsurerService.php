<?php

namespace App\Services;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;

class MockInsurerService
{
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    public const SCENARIO_TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    public const SCENARIO_DELAYED_CALLBACK = 'DELAYED_CALLBACK';
    public const SCENARIO_DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK';

    /**
     * Submit policy issuance to the mock provider.
     *
     * @param PolicyRequest $policyRequest
     * @param string|null $scenario
     * @return array
     * @throws ConnectionException|RuntimeException
     */
    public function issuePolicy(PolicyRequest $policyRequest, ?string $scenario = null): array
    {
        $scenario = strtoupper(trim($scenario ?: self::SCENARIO_SUCCESS));

        // Generate deterministic or stable provider reference based on agent + client_request_id
        $existing = MockInsurerPolicy::where('agent_id', $policyRequest->agent_id)
            ->where('client_request_id', $policyRequest->client_request_id)
            ->first();

        $providerRef = $existing ? $existing->provider_reference : 'REF-' . strtoupper(Str::random(10));
        $policyNumber = $existing ? $existing->policy_number : 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        switch ($scenario) {
            case self::SCENARIO_SUCCESS:
                $mockRecord = MockInsurerPolicy::updateOrCreate(
                    [
                        'agent_id' => $policyRequest->agent_id,
                        'client_request_id' => $policyRequest->client_request_id,
                    ],
                    [
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                        'plan_code' => $policyRequest->plan_code,
                        'sum_insured' => $policyRequest->sum_insured,
                        'premium_amount' => $policyRequest->premium_amount,
                        'status' => 'ISSUED',
                    ]
                );

                return [
                    'status' => 'SUCCESS',
                    'policy_number' => $mockRecord->policy_number,
                    'provider_reference' => $mockRecord->provider_reference,
                    'message' => 'Policy issued successfully.',
                ];

            case self::SCENARIO_TEMPORARY_FAILURE:
                // Transient error: do NOT create an issued policy in insurer ledger
                return [
                    'status' => 'FAILED',
                    'retriable' => true,
                    'error' => 'Mock provider temporary 503 Service Unavailable.',
                ];

            case self::SCENARIO_TIMEOUT_AFTER_ISSUE:
                // The insurer receives the request and issues the policy internally...
                MockInsurerPolicy::updateOrCreate(
                    [
                        'agent_id' => $policyRequest->agent_id,
                        'client_request_id' => $policyRequest->client_request_id,
                    ],
                    [
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                        'plan_code' => $policyRequest->plan_code,
                        'sum_insured' => $policyRequest->sum_insured,
                        'premium_amount' => $policyRequest->premium_amount,
                        'status' => 'ISSUED',
                    ]
                );

                // ...but the socket connection drops or times out before response reaches client!
                throw new ConnectionException("Mock provider connection timed out after upstream processing.");

            case self::SCENARIO_DELAYED_CALLBACK:
                // Policy accepted and placed in pending queue
                $mockRecord = MockInsurerPolicy::updateOrCreate(
                    [
                        'agent_id' => $policyRequest->agent_id,
                        'client_request_id' => $policyRequest->client_request_id,
                    ],
                    [
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                        'plan_code' => $policyRequest->plan_code,
                        'sum_insured' => $policyRequest->sum_insured,
                        'premium_amount' => $policyRequest->premium_amount,
                        'status' => 'PENDING',
                    ]
                );

                return [
                    'status' => 'PENDING',
                    'provider_reference' => $mockRecord->provider_reference,
                    'message' => 'Policy issuance is pending confirmation. Result will be sent via callback.',
                ];

            default:
                throw new RuntimeException("Unknown mock scenario: '{$scenario}'.");
        }
    }

    /**
     * Provider status lookup / reconciliation check.
     * Allows checking whether the external insurer actually issued a policy for this request.
     *
     * @param string|null $providerReference
     * @param string|null $agentId
     * @param string|null $clientRequestId
     * @return array|null
     */
    public function statusLookup(?string $providerReference = null, ?string $agentId = null, ?string $clientRequestId = null): ?array
    {
        $query = MockInsurerPolicy::query();

        if ($providerReference) {
            $query->where('provider_reference', $providerReference);
        } elseif ($agentId && $clientRequestId) {
            $query->where('agent_id', $agentId)
                  ->where('client_request_id', $clientRequestId);
        } else {
            return null;
        }

        $record = $query->first();

        if (!$record) {
            return [
                'found' => false,
                'status' => 'NOT_FOUND',
                'message' => 'No record found on provider ledger for given identifiers.',
            ];
        }

        return [
            'found' => true,
            'status' => $record->status,
            'policy_number' => $record->policy_number,
            'provider_reference' => $record->provider_reference,
            'plan_code' => $record->plan_code,
            'sum_insured' => (string) $record->sum_insured,
            'premium_amount' => (string) $record->premium_amount,
        ];
    }
}
