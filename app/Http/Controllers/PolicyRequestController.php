<?php

namespace App\Http\Controllers;

use App\Http\Requests\PolicyRequestStoreRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use App\Services\PremiumCalculator;
use App\Services\ReconciliationService;
use App\Services\StateTransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculator $calculator,
        protected StateTransitionService $stateTransition,
        protected PolicyIssuanceService $issuanceService,
        protected ReconciliationService $reconciliationService
    ) {}

    /**
     * Intake endpoint: POST /api/policy-requests
     * Create or safely return existing request.
     * Enforces idempotency via DB unique constraint on (agent_id, client_request_id).
     */
    public function store(PolicyRequestStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $agentId = $validated['agent_id'];
        $clientRequestId = $validated['client_request_id'];

        // 1. Check if logical request already exists (Application-level pre-check)
        $existing = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'status' => 'EXISTING_REQUEST',
                'message' => 'Policy request already exists. Idempotent response returned.',
                'data' => new PolicyRequestResource($existing),
            ], 200);
        }

        // 2. Calculate immutable premium snapshot
        $pricing = $this->calculator->calculate(
            planCode: $validated['plan_code'],
            sumInsured: $validated['sum_insured'],
            destination: $validated['destination'],
            dob: $validated['date_of_birth'],
            tripStart: $validated['trip_start'],
            tripEnd: $validated['trip_end']
        );

        // 3. Persist with DB-level concurrency safety
        try {
            $policyRequest = DB::transaction(function () use ($validated, $pricing) {
                $created = PolicyRequest::create([
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => $pricing['destination'],
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'travel_days' => $pricing['travel_days'],
                    'customer_age' => $pricing['customer_age'],
                    'plan_code' => $pricing['plan_code'],
                    'sum_insured' => $pricing['sum_insured'],
                    'base_daily_rate' => $pricing['base_daily_rate'],
                    'age_multiplier' => $pricing['age_multiplier'],
                    'destination_multiplier' => $pricing['destination_multiplier'],
                    'coverage_multiplier' => $pricing['coverage_multiplier'],
                    'premium_amount' => $pricing['premium_amount'],
                    'status' => StateTransitionService::STATUS_RECEIVED,
                    'attempt_count' => 0,
                ]);

                // Transition to VALIDATED
                return $this->stateTransition->transition(
                    policyRequest: $created,
                    toStatus: StateTransitionService::STATUS_VALIDATED,
                    reason: 'Request intake successfully verified and priced.'
                );
            });

            return response()->json([
                'success' => true,
                'status' => 'CREATED',
                'message' => 'Policy request created and validated successfully.',
                'data' => new PolicyRequestResource($policyRequest),
            ], 201);

        } catch (QueryException $e) {
            // DB unique constraint prevented duplicate race condition
            $duplicate = PolicyRequest::where('agent_id', $agentId)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($duplicate) {
                return response()->json([
                    'success' => true,
                    'status' => 'EXISTING_REQUEST_CONCURRENT',
                    'message' => 'Concurrent duplicate intake resolved via database constraint.',
                    'data' => new PolicyRequestResource($duplicate),
                ], 200);
            }

            throw $e;
        }
    }

    /**
     * Read endpoint: GET /api/policy-requests/{id}
     * Retrieve status, premium breakdown, attempts, and audit trail.
     */
    public function show(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['attempts', 'stateHistory', 'providerEvents'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PolicyRequestResource($policyRequest),
        ], 200);
    }

    /**
     * List endpoint: GET /api/policy-requests
     * Supports status filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->query('agent_id'));
        }

        if ($request->filled('plan_code')) {
            $query->where('plan_code', strtoupper($request->query('plan_code')));
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => PolicyRequestResource::collection($paginated->items()),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * Submit endpoint: POST /api/policy-requests/{id}/submit
     * Start or safely resume issuance.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::findOrFail($id);

        // Allow scenario specification via header or request body for testing/simulation
        $scenario = $request->input('scenario') ?: $request->header('X-Mock-Scenario');

        $result = $this->issuanceService->submit($policyRequest, $scenario);

        $httpStatus = 200;
        if (isset($result['status']) && $result['status'] === StateTransitionService::STATUS_FAILED && !($result['already_issued'] ?? false)) {
            $httpStatus = 422;
        }

        return response()->json([
            'success' => !($httpStatus === 422),
            'result' => $result,
            'data' => new PolicyRequestResource($result['policy_request']->fresh(['attempts', 'stateHistory'])),
        ], $httpStatus);
    }

    /**
     * Reconcile endpoint: POST /api/policy-requests/{id}/reconcile
     */
    public function reconcile(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::findOrFail($id);
        $result = $this->reconciliationService->reconcile($policyRequest);

        return response()->json([
            'success' => true,
            'result' => $result,
            'data' => new PolicyRequestResource($result['policy_request']->fresh(['attempts', 'stateHistory'])),
        ], 200);
    }
}
