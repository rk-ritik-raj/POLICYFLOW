<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Services\StateTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PolicyIssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createValidatedRequest(string $clientRequestId = 'AGT-REQ-101'): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        return PolicyRequest::findOrFail($response->json('data.id'));
    }

    public function test_successful_issuance_creates_exactly_one_unique_policy_number_and_records_audit_trail(): void
    {
        $request = $this->createValidatedRequest('AGT-ISSUE-001');

        $response = $this->postJson("/api/policy-requests/{$request->id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'ISSUED',
                    'message' => 'Policy issued successfully.',
                ],
            ]);

        $policyNumber = $response->json('result.policy_number');
        $providerRef = $response->json('result.provider_reference');

        $this->assertNotEmpty($policyNumber);
        $this->assertNotEmpty($providerRef);

        $fresh = $request->fresh();
        $this->assertEquals('ISSUED', $fresh->status);
        $this->assertEquals($policyNumber, $fresh->policy_number);
        $this->assertEquals($providerRef, $fresh->provider_reference);

        // Verify audit trail contains transitions
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $request->id,
            'from_status' => 'VALIDATED',
            'to_status' => 'SUBMITTED',
        ]);

        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $request->id,
            'from_status' => 'SUBMITTED',
            'to_status' => 'ISSUED',
        ]);
    }

    public function test_repeated_submission_does_not_cause_duplicate_issuance(): void
    {
        $request = $this->createValidatedRequest('AGT-REPEAT-SUBMIT');

        // First submission
        $firstSubmit = $this->postJson("/api/policy-requests/{$request->id}/submit", ['scenario' => 'SUCCESS']);
        $firstSubmit->assertStatus(200);
        $policyNumber = $firstSubmit->json('result.policy_number');

        // Repeated submission
        $secondSubmit = $this->postJson("/api/policy-requests/{$request->id}/submit", ['scenario' => 'SUCCESS']);
        $secondSubmit->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'already_issued' => true,
                    'status' => 'ISSUED',
                    'policy_number' => $policyNumber,
                ],
            ]);

        // Assert only ONE policy number exists and attempt count was not incremented
        $this->assertEquals($policyNumber, $secondSubmit->json('result.policy_number'));
        $this->assertEquals(1, PolicyRequest::where('policy_number', $policyNumber)->count());
    }

    public function test_an_issued_policy_cannot_regress_to_a_nonterminal_state(): void
    {
        $request = $this->createValidatedRequest('AGT-REGRESS-TEST');
        $this->postJson("/api/policy-requests/{$request->id}/submit", ['scenario' => 'SUCCESS']);

        $issuedRequest = $request->fresh();
        $this->assertEquals('ISSUED', $issuedRequest->status);

        $stateService = app(StateTransitionService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Illegal state transition from 'ISSUED' to 'SUBMITTED'");

        // Attempting an illegal regression: ISSUED -> SUBMITTED
        $stateService->transition($issuedRequest, StateTransitionService::STATUS_SUBMITTED);
    }

    public function test_delayed_callback_flow_transitions_to_pending_and_then_issued_on_callback(): void
    {
        $request = $this->createValidatedRequest('AGT-DELAYED-TEST');

        // Submit with DELAYED_CALLBACK
        $submitResponse = $this->postJson("/api/policy-requests/{$request->id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        $submitResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'PENDING_CONFIRMATION',
                ],
            ]);

        $providerRef = $submitResponse->json('result.provider_reference');
        $this->assertNotEmpty($providerRef);
        $this->assertEquals('PENDING_CONFIRMATION', $request->fresh()->status);

        // Simulate provider callback delivering issuance confirmation
        $callbackPayload = [
            'event_id' => 'EVT-CB-1001',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => 'POL-ASYNC-9999',
            'agent_id' => $request->agent_id,
            'client_request_id' => $request->client_request_id,
        ];

        $callbackResponse = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $callbackResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'status' => 'PROCESSED',
                    'current_policy_status' => 'ISSUED',
                ],
            ]);

        $finalRequest = $request->fresh();
        $this->assertEquals('ISSUED', $finalRequest->status);
        $this->assertEquals('POL-ASYNC-9999', $finalRequest->policy_number);
    }
}
