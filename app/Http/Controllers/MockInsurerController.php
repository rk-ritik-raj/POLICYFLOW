<?php

namespace App\Http\Controllers;

use App\Services\MockInsurerService;
use App\Services\ProviderCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerController extends Controller
{
    public function __construct(
        protected ProviderCallbackService $callbackService,
        protected MockInsurerService $mockInsurer
    ) {}

    /**
     * Endpoint: POST /api/mock-insurer/callback
     * Receive simulated provider event idempotently.
     */
    public function callback(Request $request): JsonResponse
    {
        $payload = $request->all();

        $result = $this->callbackService->handleCallback($payload);

        return response()->json([
            'success' => $result['success'] ?? false,
            'result' => $result,
        ], 200);
    }

    /**
     * Endpoint: GET /api/mock-insurer/lookup
     * Query mock insurer's external ledger for status reconciliation.
     */
    public function lookup(Request $request): JsonResponse
    {
        $providerRef = $request->query('provider_reference');
        $agentId = $request->query('agent_id');
        $clientRequestId = $request->query('client_request_id');

        $result = $this->mockInsurer->statusLookup($providerRef, $agentId, $clientRequestId);

        return response()->json([
            'success' => (bool) $result,
            'data' => $result,
        ], 200);
    }
}
