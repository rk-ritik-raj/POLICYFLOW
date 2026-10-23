<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\StateTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function createValidatedRequest(string $clientRequestId, float $sumInsured = 300000): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
            'agent_id' => 'AGENT-REL',
            'customer_name' => 'Reliability Test Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => $sumInsured,
        ]);

        return PolicyRequest::findOrFail($response->json('data.id'));
    }

    /**
     * Reliability Case 1: Duplicate intake idempotency
     * Repeated submissions with the same agent_id + client_request_id return HTTP 200
     * and do not create another logical record in the database.
     */
    public function test_reliability_case_1_duplicate_intake_returns_existing_logical_request(): void
    {
        $payload = [
            'client_request_id' => 'REL-INTAKE-DUP-001',
            'agent_id' => 'AGENT-DUP',
            'customer_name' => 'Alice Duplicate',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        // 1st intake: 201 Created
        $res1 = $this->postJson('/api/policy-requests', $payload);
        $res1->assertStatus(201);
        $firstId = $res1->json('data.id');
        $this->assertEquals('60.00', $res1->json('data.pricing_breakdown.premium_amount'));

        // 2nd intake: 200 OK with identical payload
        $res2 = $this->postJson('/api/policy-requests', $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'EXISTING_REQUEST',
            ]);
        $this->assertEquals($firstId, $res2->json('data.id'));

        // 3rd intake with slight non-key payload variations (e.g. agent retry)
        $payloadModified = $payload;
        $payloadModified['customer_name'] = 'Alice Modified Name';
        $res3 = $this->postJson('/api/policy-requests', $payloadModified);
        $res3->assertStatus(200);
        $this->assertEquals($firstId, $res3->json('data.id'));

        // DB level verification: Exactly 1 row exists
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-DUP')->where('client_request_id', 'REL-INTAKE-DUP-001')->count());
    }

    /**
     * Reliability Case 2: Timeout after success and reconciliation
     * Upstream insurer succeeds and records issuance, but connection times out to client.
     * Engine enters NEEDS_RECONCILIATION.
     * Reconciliation service queries provider status and safely reconciles to ISSUED
     * without creating duplicate policy numbers or altering the accepted premium snapshot.
     */
    public function test_reliability_case_2_timeout_after_success_reconciles_without_duplicate_issuance(): void
    {
        $request = $this->createValidatedRequest('REL-TIMEOUT-001', 300000);
        $originalPremium = $request->premium_amount;
        $this->assertEquals('225.00', (string) $originalPremium);

        // 1. Submit with TIMEOUT_AFTER_ISSUE scenario
        $submitResponse = $this->postJson("/api/policy-requests/{$request->id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $submitResponse->assertStatus(200)
            ->assertJson([
                'result' => [
                    'status' => 'NEEDS_RECONCILIATION',
                ],
            ]);

        $requestAfterTimeout = $request->fresh();
        $this->assertEquals('NEEDS_RECONCILIATION', $requestAfterTimeout->status);
        $this->assertNull($requestAfterTimeout->policy_number);

        // Verify the mock insurer ledger actually has the issued policy recorded
        $mockLedgerEntry = MockInsurerPolicy::where('client_request_id', 'REL-TIMEOUT-001')->first();
        $this->assertNotNull($mockLedgerEntry);
        $expectedPolicyNumber = $mockLedgerEntry->policy_number;

        // 2. Trigger reconciliation
        $reconcileResponse = $this->postJson("/api/policy-requests/{$request->id}/reconcile");
        $reconcileResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'reconciled' => true,
                    'outcome' => 'CONFIRMED_ISSUED',
                ],
                'data' => [
                    'status' => 'ISSUED',
                    'policy_number' => $expectedPolicyNumber,
                    'pricing_breakdown' => [
                        'premium_amount' => '225.00',
                    ],
                ],
            ]);

        $finalRequest = $request->fresh();
        $this->assertEquals('ISSUED', $finalRequest->status);
        $this->assertEquals($expectedPolicyNumber, $finalRequest->policy_number);
        $this->assertEquals($originalPremium, $finalRequest->premium_amount);

        // Ensure exactly ONE policy number exists in database
        $this->assertEquals(1, PolicyRequest::where('policy_number', $expectedPolicyNumber)->count());

        // 3. Resubmitting an already reconciled request is safe and returns ISSUED
        $resubmitResponse = $this->postJson("/api/policy-requests/{$request->id}/submit");
        $resubmitResponse->assertStatus(200)
            ->assertJson([
                'result' => [
                    'already_issued' => true,
                    'status' => 'ISSUED',
                    'policy_number' => $expectedPolicyNumber,
                ],
            ]);
    }

    /**
     * Reliability Case 3: Duplicate provider callback idempotency
     * The insurer delivers the same callback event multiple times.
     * System processes the event once and safely ignores subsequent deliveries.
     */
    public function test_reliability_case_3_duplicate_callbacks_are_idempotent(): void
    {
        $request = $this->createValidatedRequest('REL-DUP-CB-001');

        // Set to PENDING_CONFIRMATION
        $submitResponse = $this->postJson("/api/policy-requests/{$request->id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);
        $providerRef = $submitResponse->json('result.provider_reference');

        $callbackPayload = [
            'event_id' => 'EVENT-IDEMP-777',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => 'POL-UNIQUE-CB-777',
            'agent_id' => $request->agent_id,
            'client_request_id' => $request->client_request_id,
        ];

        // 1st callback delivery: Successfully processed
        $cb1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'PROCESSED',
                    'current_policy_status' => 'ISSUED',
                ],
            ]);

        $this->assertEquals('ISSUED', $request->fresh()->status);
        $this->assertEquals('POL-UNIQUE-CB-777', $request->fresh()->policy_number);

        // 2nd callback delivery with identical event_id
        $cb2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'DUPLICATE_IGNORED',
                ],
            ]);

        // 3rd callback delivery
        $cb3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb3->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'DUPLICATE_IGNORED',
                ],
            ]);

        // Database constraint check: exactly 1 event record in provider_events
        $this->assertEquals(1, ProviderEvent::where('event_id', 'EVENT-IDEMP-777')->count());

        // Policy state remains ISSUED and was not modified or corrupted
        $this->assertEquals('ISSUED', $request->fresh()->status);
        $this->assertEquals('POL-UNIQUE-CB-777', $request->fresh()->policy_number);
    }

    /**
     * Reliability Case 4: Retry after temporary failure without duplicate policies or changed premium
     * Submission fails with retriable error.
     * Retrying submission succeeds, generates exactly 1 policy, and preserves original premium snapshot.
     */
    public function test_reliability_case_4_retry_after_temporary_failure_preserves_premium_and_issues_once(): void
    {
        $request = $this->createValidatedRequest('REL-RETRY-001', 300000);
        $acceptedPremium = $request->premium_amount;
        $this->assertEquals('225.00', (string) $acceptedPremium);

        // 1. First attempt: TEMPORARY_FAILURE
        $failResponse = $this->postJson("/api/policy-requests/{$request->id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $failResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
                'result' => [
                    'status' => 'FAILED',
                    'retriable' => true,
                ],
            ]);

        $requestAfterFail = $request->fresh();
        $this->assertEquals('FAILED', $requestAfterFail->status);
        $this->assertNull($requestAfterFail->policy_number);
        $this->assertEquals('225.00', (string) $requestAfterFail->premium_amount);

        // 2. Retry attempt: SUCCESS
        $retryResponse = $this->postJson("/api/policy-requests/{$request->id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $retryResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'ISSUED',
                ],
                'data' => [
                    'status' => 'ISSUED',
                    'pricing_breakdown' => [
                        'premium_amount' => '225.00', // Unaltered!
                    ],
                ],
            ]);

        $finalRequest = $request->fresh();
        $this->assertEquals('ISSUED', $finalRequest->status);
        $this->assertNotNull($finalRequest->policy_number);
        // Immutable premium guarantee:
        $this->assertEquals($acceptedPremium, $finalRequest->premium_amount);
        $this->assertEquals(2, $finalRequest->attempt_count);

        // Assert exactly one policy exists for this logical entity
        $this->assertEquals(1, PolicyRequest::where('policy_number', $finalRequest->policy_number)->count());
    }
}
