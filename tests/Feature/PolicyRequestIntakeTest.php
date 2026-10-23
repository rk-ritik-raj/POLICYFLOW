<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyRequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_intake_creates_policy_request_with_exact_assignment_premium(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'status' => 'CREATED',
                'data' => [
                    'client_request_id' => 'AGT-2026-0001',
                    'agent_id' => 'AGENT-17',
                    'customer_name' => 'Sample Customer',
                    'destination' => 'INTERNATIONAL',
                    'travel_days' => 5,
                    'customer_age' => 28,
                    'plan_code' => 'PLUS',
                    'sum_insured' => '300000.00',
                    'status' => 'VALIDATED',
                    'pricing_breakdown' => [
                        'base_daily_rate' => '20.00',
                        'age_multiplier' => '1.00',
                        'destination_multiplier' => '1.80',
                        'coverage_multiplier' => '1.25',
                        'premium_amount' => '225.00',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'status' => 'VALIDATED',
            'premium_amount' => 225.00,
        ]);
    }

    public function test_repeated_intake_with_same_agent_and_client_request_id_returns_existing_record_idempotently(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        // First intake: 201 Created
        $firstResponse = $this->postJson('/api/policy-requests', $payload);
        $firstResponse->assertStatus(201);
        $originalId = $firstResponse->json('data.id');

        // Second intake: 200 OK returning the same logical entity
        $secondResponse = $this->postJson('/api/policy-requests', $payload);
        $secondResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'EXISTING_REQUEST',
            ]);

        $this->assertEquals($originalId, $secondResponse->json('data.id'));

        // Database must contain only ONE record
        $this->assertEquals(1, PolicyRequest::where('client_request_id', 'AGT-2026-0001')->count());
    }

    public function test_rejects_trip_end_before_trip_start(): void
    {
        $payload = [
            'client_request_id' => 'AGT-INVALID-DATES',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10', // Invalid: before start
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['trip_end']]);
    }

    public function test_rejects_unsupported_plan_code(): void
    {
        $payload = [
            'client_request_id' => 'AGT-INVALID-PLAN',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-15',
            'plan_code' => 'SUPER_PLATINUM', // Unsupported
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['plan_code']]);
    }

    public function test_rejects_unsupported_sum_insured(): void
    {
        $payload = [
            'client_request_id' => 'AGT-INVALID-SUM',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-15',
            'plan_code' => 'BASIC',
            'sum_insured' => 250000, // Unsupported (only 100k, 300k, 500k allowed)
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['sum_insured']]);
    }

    public function test_database_unique_constraint_enforces_idempotency_at_storage_level(): void
    {
        PolicyRequest::create([
            'client_request_id' => 'DB-UNIQUE-TEST',
            'agent_id' => 'AGENT-DB',
            'customer_name' => 'Customer 1',
            'date_of_birth' => '1995-05-05',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'travel_days' => 5,
            'customer_age' => 31,
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'base_daily_rate' => 12.00,
            'age_multiplier' => 1.00,
            'destination_multiplier' => 1.00,
            'coverage_multiplier' => 1.00,
            'premium_amount' => 60.00,
            'status' => 'VALIDATED',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempting to bypass application and insert direct duplicate into DB must fail via DB unique constraint
        PolicyRequest::create([
            'client_request_id' => 'DB-UNIQUE-TEST',
            'agent_id' => 'AGENT-DB',
            'customer_name' => 'Duplicate Customer',
            'date_of_birth' => '1995-05-05',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'travel_days' => 5,
            'customer_age' => 31,
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'base_daily_rate' => 12.00,
            'age_multiplier' => 1.00,
            'destination_multiplier' => 1.00,
            'coverage_multiplier' => 1.00,
            'premium_amount' => 60.00,
            'status' => 'VALIDATED',
        ]);
    }

    public function test_read_and_filter_policy_requests_endpoints(): void
    {
        // Create 2 records
        $res1 = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-001',
            'agent_id' => 'AGENT-A',
            'customer_name' => 'Alice',
            'date_of_birth' => '1992-03-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $recordId = $res1->json('data.id');

        $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-002',
            'agent_id' => 'AGENT-B',
            'customer_name' => 'Bob',
            'date_of_birth' => '1985-07-20',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-15',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ]);

        // Test GET list
        $listResponse = $this->getJson('/api/policy-requests');
        $listResponse->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'pagination']);
        $this->assertCount(2, $listResponse->json('data'));

        // Test filter by agent
        $filterResponse = $this->getJson('/api/policy-requests?agent_id=AGENT-A');
        $filterResponse->assertStatus(200);
        $this->assertCount(1, $filterResponse->json('data'));
        $this->assertEquals('AGENT-A', $filterResponse->json('data.0.agent_id'));

        // Test GET single by dynamic ID
        $singleResponse = $this->getJson("/api/policy-requests/{$recordId}");
        $singleResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $recordId,
                    'client_request_id' => 'REQ-001',
                ],
            ]);
    }
}
